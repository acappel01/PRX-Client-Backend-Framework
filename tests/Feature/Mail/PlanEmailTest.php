<?php

namespace Tests\Feature\Mail;

use App\Events\Quiz\QuizCompleted;
use App\Listeners\Quiz\SendPlanEmail;
use App\Mail\PlanReadyMail;
use App\Models\Lead;
use App\Services\Mail\MailConfigurator;
use App\Settings\CommunicationSettings;
use App\Settings\ContactSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The plan email, and the three separate reasons not to send one.
 *
 * The rule under test throughout: `plan_sent_at` records what HAPPENED, never
 * what was intended. The plan page reads it to decide whether it may say "we
 * sent you a copy", so anything that stamps it without a send turns that page
 * into a confident lie.
 */
class PlanEmailTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'Andrew',
            'last_name' => 'Cappello',
            'email' => 'a@example.com',
            'email_consent' => true,
        ], $overrides));
    }

    private function enableSending(): void
    {
        $settings = app(CommunicationSettings::class);
        $settings->email_enabled = true;
        $settings->save();

        // `log` and `array` are working mailers that reach nobody, so the
        // listener refuses them. Point at a real transport name for the tests
        // that expect a send; Mail::fake() intercepts before anything leaves.
        config(['mail.default' => 'smtp']);
    }

    public function test_it_sends_and_records_when_everything_is_in_place(): void
    {
        Mail::fake();
        $this->enableSending();
        $lead = $this->lead();

        app(SendPlanEmail::class)->handle(new QuizCompleted($lead));

        Mail::assertSent(PlanReadyMail::class, fn ($mail) => $mail->hasTo('a@example.com'));
        $this->assertNotNull($lead->fresh()->plan_sent_at);
    }

    public function test_it_does_not_send_without_consent(): void
    {
        Mail::fake();
        $this->enableSending();
        $lead = $this->lead(['email_consent' => false]);

        app(SendPlanEmail::class)->handle(new QuizCompleted($lead));

        Mail::assertNothingSent();
        $this->assertNull($lead->fresh()->plan_sent_at);
    }

    public function test_a_configured_mailer_is_not_permission_to_send(): void
    {
        // email_enabled is the operator's switch and defaults to false. A
        // working transport does not imply consent to start mailing people.
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $lead = $this->lead();

        app(SendPlanEmail::class)->handle(new QuizCompleted($lead));

        Mail::assertNothingSent();
        $this->assertNull($lead->fresh()->plan_sent_at);
    }

    public function test_it_refuses_a_transport_that_reaches_nobody(): void
    {
        // The one that matters most: `log` sends "successfully" and delivers
        // nothing, so stamping plan_sent_at would make the plan page claim a
        // delivery that never happened.
        Mail::fake();
        $settings = app(CommunicationSettings::class);
        $settings->email_enabled = true;
        $settings->save();
        config(['mail.default' => 'log']);

        $lead = $this->lead();

        app(SendPlanEmail::class)->handle(new QuizCompleted($lead));

        Mail::assertNothingSent();
        $this->assertNull($lead->fresh()->plan_sent_at);
    }

    public function test_a_failed_send_leaves_the_lead_visibly_un_emailed(): void
    {
        $this->enableSending();
        $lead = $this->lead();

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('transport exploded'));

        // Must not bubble: a marketing send is never a reason to lose a lead.
        app(SendPlanEmail::class)->handle(new QuizCompleted($lead));

        $this->assertNull($lead->fresh()->plan_sent_at);
    }

    public function test_settings_override_the_env_provider_but_null_leaves_it_alone(): void
    {
        config(['mail.default' => 'mailgun']);

        // Nothing selected — .env stays the floor, so an install that never
        // opened the settings page keeps sending exactly as it did.
        app(MailConfigurator::class)->apply();
        $this->assertSame('mailgun', config('mail.default'));

        $settings = app(CommunicationSettings::class);
        $settings->mail_provider = 'postmark';
        $settings->postmark_token = 'tok_123';
        $settings->save();

        (new MailConfigurator(app('config'), $settings, app(ContactSettings::class)))->apply();

        $this->assertSame('postmark', config('mail.default'));
        $this->assertSame('tok_123', config('services.postmark.token'));
    }

    public function test_only_the_selected_providers_credentials_are_applied(): void
    {
        // An SES key has no business sitting in the config of an install that
        // sends through Mailgun.
        config(['services.ses.key' => null]);

        $settings = app(CommunicationSettings::class);
        $settings->mail_provider = 'mailgun';
        $settings->mailgun_domain = 'mg.example.com';
        $settings->ses_key = 'AKIA-should-not-be-applied';
        $settings->save();

        (new MailConfigurator(app('config'), $settings, app(ContactSettings::class)))->apply();

        $this->assertSame('mg.example.com', config('services.mailgun.domain'));
        $this->assertNull(config('services.ses.key'));
    }
}
