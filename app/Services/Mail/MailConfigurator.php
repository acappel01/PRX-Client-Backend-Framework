<?php

namespace App\Services\Mail;

use App\Settings\CommunicationSettings;
use App\Settings\ContactSettings;
use Illuminate\Contracts\Config\Repository;

/**
 * Applies the operator's mail settings over the config the app booted with.
 *
 * WHY OVER config RATHER THAN INSTEAD OF IT. `.env` stays the floor: an
 * install that has never opened the settings page keeps sending exactly as it
 * did, because every setting defaults to null and null means "leave the
 * configured value alone". Taking over the mailer the moment a settings row
 * exists would silently repoint mail on upgrade, which is the kind of change
 * nobody notices until a customer does.
 *
 * Only the SELECTED provider's credentials are applied. Writing all of them
 * would put an SES key into config on an install that sends through Mailgun,
 * which is a secret in a place with no reason to hold it.
 *
 * There is deliberately no driver INTERFACE here. Laravel's mail manager
 * already is one — every provider below is a Symfony transport it can build —
 * so a bespoke interface would be a second abstraction wrapping the first, and
 * adding SendGrid or Brevo later is a package plus a case, not a new class.
 */
class MailConfigurator
{
    public function __construct(
        private readonly Repository $config,
        private readonly CommunicationSettings $settings,
        private readonly ContactSettings $contact,
    ) {}

    public function apply(): void
    {
        $provider = $this->settings->mail_provider;

        if (filled($provider)) {
            $this->config->set('mail.default', $provider);
        }

        match ($provider) {
            'mailgun' => $this->applyMailgun(),
            'postmark' => $this->applyPostmark(),
            'ses' => $this->applySes(),
            default => null,
        };

        if (filled($this->settings->mail_from_address)) {
            $this->config->set('mail.from.address', $this->settings->mail_from_address);
        }

        if (filled($this->settings->mail_from_name)) {
            $this->config->set('mail.from.name', $this->settings->mail_from_name);
        }

        $this->applyReplyTo();
    }

    /**
     * Where replies go: the Communication setting, else Contact → Support
     * email, else whatever MAIL_REPLY_TO_* left in config.
     *
     * The support email is in the chain because the From is normally a
     * no-reply on the sending domain with no inbox behind it, so an install
     * that has filled in its support address but never opened this page would
     * otherwise send every patient's reply into nothing. It sits BELOW the
     * explicit setting, so an operator who wants replies somewhere other than
     * the public support address can say so.
     *
     * Only the address falls back. A name set here without an address is still
     * applied — it labels whichever address won — but a name is never borrowed
     * from anywhere else, since the From name ("Atlas Protocol") on a reply
     * address reads as a second no-reply.
     */
    private function applyReplyTo(): void
    {
        $address = $this->settings->mail_reply_to_address;

        if (blank($address)) {
            $address = $this->contact->support_email;
        }

        $this->setIfFilled('mail.reply_to.address', $address);
        $this->setIfFilled('mail.reply_to.name', $this->settings->mail_reply_to_name);
    }

    /**
     * Whether a send should be attempted at all.
     *
     * Two conditions, and both matter. `email_enabled` is the operator's
     * switch. The second is whether anything could actually deliver — an
     * install pointed at `log` or `array` has a working mailer that reaches
     * nobody, and a funnel telling a visitor "we've sent it" on that basis is
     * exactly the lie this whole path is written to avoid.
     */
    public function canSend(): bool
    {
        return $this->settings->email_enabled
            && ! in_array($this->config->get('mail.default'), ['log', 'array', null], true);
    }

    private function applyMailgun(): void
    {
        $this->setIfFilled('services.mailgun.domain', $this->settings->mailgun_domain);
        $this->setIfFilled('services.mailgun.secret', $this->settings->mailgun_secret);
        $this->setIfFilled('services.mailgun.endpoint', $this->settings->mailgun_endpoint);
    }

    private function applyPostmark(): void
    {
        $this->setIfFilled('services.postmark.token', $this->settings->postmark_token);
    }

    private function applySes(): void
    {
        $this->setIfFilled('services.ses.key', $this->settings->ses_key);
        $this->setIfFilled('services.ses.secret', $this->settings->ses_secret);
        $this->setIfFilled('services.ses.region', $this->settings->ses_region);
    }

    /** Null and '' both mean "the operator left this blank", never "clear it". */
    private function setIfFilled(string $key, ?string $value): void
    {
        if (filled($value)) {
            $this->config->set($key, $value);
        }
    }
}
