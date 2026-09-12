<?php

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\ManageCommunication;
use App\Models\User;
use App\Settings\CommunicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Communication page's Email section, driven through the REAL form save.
 *
 * Every field in that section — "Send email", provider, credentials, From —
 * was on the page but absent from CommunicationSettingsData and from the
 * update action, so the page said "saved" and wrote nothing. That was the
 * fourth silent save on this project; like the others, only a test that goes
 * through getState(), validateAndCreate() and the action could have caught it.
 *
 * Booleans are asserted in BOTH directions: a dropped value reads as false,
 * so only switching back off distinguishes "saved false" from "never saved".
 */
class ManageCommunicationEmailSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');

        $user = User::factory()->create()->refresh();
        $user->assignRole('super_admin');

        $this->actingAs($user);
    }

    private function stored(): CommunicationSettings
    {
        return $this->app->make(CommunicationSettings::class)->refresh();
    }

    public function test_switching_email_on_persists(): void
    {
        Livewire::test(ManageCommunication::class)
            ->fillForm(['email_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->stored()->email_enabled);
    }

    public function test_switching_email_back_off_persists(): void
    {
        $settings = $this->stored();
        $settings->email_enabled = true;
        $settings->save();

        Livewire::test(ManageCommunication::class)
            ->fillForm(['email_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->stored()->email_enabled);
    }

    public function test_sender_and_reply_to_persist(): void
    {
        Livewire::test(ManageCommunication::class)
            ->fillForm([
                'mail_provider' => 'mailgun',
                'mailgun_domain' => 'mg.example.test',
                'mailgun_secret' => 'key-secret',
                'mail_from_address' => 'no-reply@mg.example.test',
                'mail_from_name' => 'Brand',
                'mail_reply_to_address' => 'care@example.test',
                'mail_reply_to_name' => 'Care team',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $this->stored();
        $this->assertSame('mailgun', $stored->mail_provider);
        $this->assertSame('mg.example.test', $stored->mailgun_domain);
        $this->assertSame('key-secret', $stored->mailgun_secret);
        $this->assertSame('no-reply@mg.example.test', $stored->mail_from_address);
        $this->assertSame('Brand', $stored->mail_from_name);
        $this->assertSame('care@example.test', $stored->mail_reply_to_address);
        $this->assertSame('Care team', $stored->mail_reply_to_name);
    }

    public function test_clearing_the_reply_to_persists(): void
    {
        $settings = $this->stored();
        $settings->mail_reply_to_address = 'care@example.test';
        $settings->save();

        Livewire::test(ManageCommunication::class)
            ->assertFormSet(['mail_reply_to_address' => 'care@example.test'])
            ->fillForm(['mail_reply_to_address' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->stored()->mail_reply_to_address);
    }

    public function test_an_invalid_reply_to_is_refused_and_nothing_is_written(): void
    {
        Livewire::test(ManageCommunication::class)
            ->fillForm(['email_enabled' => true, 'mail_reply_to_address' => 'not an address'])
            ->call('save')
            ->assertHasFormErrors(['mail_reply_to_address']);

        $stored = $this->stored();
        $this->assertNull($stored->mail_reply_to_address);
        $this->assertFalse($stored->email_enabled);
    }

    /**
     * Provider credentials are only VISIBLE for the selected provider, and a
     * hidden field is absent from the form state. Switching provider must not
     * erase the previous provider's key — nor switching SMS off its message.
     */
    public function test_fields_hidden_by_the_current_choice_are_kept(): void
    {
        $settings = $this->stored();
        $settings->mail_provider = 'mailgun';
        $settings->mailgun_secret = 'mg-secret';
        $settings->sms_enabled = true;
        $settings->sms_opt_in_message = 'Reply YES';
        $settings->save();

        Livewire::test(ManageCommunication::class)
            ->fillForm(['mail_provider' => 'postmark', 'postmark_token' => 'pm-token', 'sms_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $this->stored();
        $this->assertSame('postmark', $stored->mail_provider);
        $this->assertSame('pm-token', $stored->postmark_token);
        $this->assertSame('mg-secret', $stored->mailgun_secret);
        $this->assertFalse($stored->sms_enabled);
        $this->assertSame('Reply YES', $stored->sms_opt_in_message);
    }

    /**
     * Adding the email fields to the save must not start wiping the SMS ones —
     * the action assigns every field, so a key the form stopped sending would
     * now be written as null rather than ignored.
     */
    public function test_saving_keeps_the_twilio_settings(): void
    {
        $settings = $this->stored();
        $settings->twilio_account_sid = 'AC123';
        $settings->sms_enabled = true;
        $settings->sms_opt_in_message = 'Reply YES';
        $settings->save();

        Livewire::test(ManageCommunication::class)
            ->fillForm(['email_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $this->stored();
        $this->assertSame('AC123', $stored->twilio_account_sid);
        $this->assertTrue($stored->sms_enabled);
        $this->assertSame('Reply YES', $stored->sms_opt_in_message);
    }
}
