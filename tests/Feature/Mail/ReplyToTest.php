<?php

namespace Tests\Feature\Mail;

use App\Mail\PlanReadyMail;
use App\Models\Lead;
use App\Services\Mail\MailConfigurator;
use App\Settings\CommunicationSettings;
use App\Settings\ContactSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Where a recipient's reply goes.
 *
 * Mail is sent From a no-reply on the provider-verified domain, which has no
 * inbox, so without a Reply-To every patient reply disappears. Three sources,
 * in order: the Communication setting, Contact → Support email, then
 * MAIL_REPLY_TO_* in config.
 *
 * The sends go through the `array` transport rather than Mail::fake(), because
 * the fake intercepts the mailable before the mailer builds a message — and the
 * global Reply-To is applied while building it. A fake would pass with the
 * header missing.
 */
class ReplyToTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'mail.reply_to' => ['address' => null, 'name' => null],
        ]);
    }

    /** Apply the settings and drop any mailer resolved with the old config. */
    private function configure(array $communication = [], ?string $supportEmail = null): void
    {
        $settings = app(CommunicationSettings::class);
        foreach ($communication as $key => $value) {
            $settings->{$key} = $value;
        }
        $settings->save();

        $contact = app(ContactSettings::class);
        $contact->support_email = $supportEmail;
        $contact->save();

        app(MailConfigurator::class)->apply();
        Mail::forgetMailers();
    }

    private function sentRaw(): Email
    {
        Mail::raw('Body', fn ($mail) => $mail->to('patient@example.test')->subject('Hello'));

        return $this->lastSent();
    }

    private function lastSent(): Email
    {
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'Nothing was sent.');

        return $messages->last()->getOriginalMessage();
    }

    public function test_no_source_means_no_reply_to_header(): void
    {
        $this->configure();

        $this->assertSame([], $this->sentRaw()->getReplyTo());
    }

    public function test_the_env_floor_is_used_when_nothing_else_is_set(): void
    {
        config(['mail.reply_to' => ['address' => 'env@example.test', 'name' => 'Env']]);
        $this->configure();

        $replyTo = $this->sentRaw()->getReplyTo();
        $this->assertSame('env@example.test', $replyTo[0]->getAddress());
        $this->assertSame('Env', $replyTo[0]->getName());
    }

    public function test_the_support_email_beats_the_env_floor(): void
    {
        config(['mail.reply_to' => ['address' => 'env@example.test', 'name' => null]]);
        $this->configure([], 'support@brand.example.test');

        $this->assertSame('support@brand.example.test', $this->sentRaw()->getReplyTo()[0]->getAddress());
    }

    public function test_the_communication_setting_beats_the_support_email(): void
    {
        $this->configure(
            ['mail_reply_to_address' => 'care@brand.example.test', 'mail_reply_to_name' => 'Care team'],
            'support@brand.example.test',
        );

        $replyTo = $this->sentRaw()->getReplyTo();
        $this->assertCount(1, $replyTo);
        $this->assertSame('care@brand.example.test', $replyTo[0]->getAddress());
        $this->assertSame('Care team', $replyTo[0]->getName());
    }

    /**
     * The From name must never be borrowed for the reply address: "Atlas
     * Protocol <support@…>" beside a From of "Atlas Protocol <no-reply@…>"
     * reads as two no-replies.
     */
    public function test_a_fallback_address_borrows_no_name(): void
    {
        config(['mail.from.name' => 'Brand']);
        $this->configure([], 'support@brand.example.test');

        $this->assertSame('', $this->sentRaw()->getReplyTo()[0]->getName());
    }

    public function test_reply_to_leaves_the_from_address_alone(): void
    {
        config(['mail.from.address' => 'no-reply@mg.example.test']);
        $this->configure(['mail_reply_to_address' => 'care@brand.example.test']);

        $this->assertSame('no-reply@mg.example.test', $this->sentRaw()->getFrom()[0]->getAddress());
    }

    public function test_the_plan_email_carries_it_too(): void
    {
        $this->configure([], 'support@brand.example.test');

        $lead = Lead::factory()->create(['email' => 'a@example.test', 'first_name' => 'Ada']);
        Mail::to($lead->email)->send(new PlanReadyMail($lead, 'https://shop.example.test/plan/x'));

        $this->assertSame('support@brand.example.test', $this->lastSent()->getReplyTo()[0]->getAddress());
    }
}
