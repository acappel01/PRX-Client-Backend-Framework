<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Twilio credentials and channel toggles.
 * Auth token is encrypted at rest via the `encrypted()` method.
 * Account SID is not secret (it appears in HTTP basic auth usernames) but
 * we store it encrypted too for consistency.
 */
class CommunicationSettings extends Settings
{
    // ── Twilio ───────────────────────────────────────────────────────────

    /** Twilio Account SID (starts with "AC"). */
    public ?string $twilio_account_sid = null;

    /** Twilio Auth Token. Encrypted at rest. */
    public ?string $twilio_auth_token = null;

    /** Twilio phone number to send from, in E.164 format: +15551234567. */
    public ?string $twilio_from_number = null;

    // ── Email ────────────────────────────────────────────────────────────

    /**
     * Which transport actually sends. Selected here rather than pinned in
     * `.env` because the provider is an operational choice, not a deployment
     * one — an install moving from Mailgun to SES should not need a redeploy.
     *
     * Null means "use whatever .env says", which is the correct default for an
     * install that has never opened this page: taking over the mailer the
     * moment the settings row exists would silently repoint mail on upgrade.
     */
    public ?string $mail_provider = null;

    /** Mailgun. Domain is not secret; the key is. */
    public ?string $mailgun_domain = null;

    public ?string $mailgun_secret = null;

    /**
     * Mailgun's US and EU stacks are separate and a domain verified in one
     * 401s against the other, so the endpoint is a field rather than an
     * assumption.
     */
    public ?string $mailgun_endpoint = null;

    /** Postmark. */
    public ?string $postmark_token = null;

    /** Amazon SES. Region is not secret. */
    public ?string $ses_key = null;

    public ?string $ses_secret = null;

    public ?string $ses_region = null;

    /** Who mail appears to come from. Overrides MAIL_FROM_* when set. */
    public ?string $mail_from_address = null;

    public ?string $mail_from_name = null;

    /**
     * Where replies go. Overrides MAIL_REPLY_TO_* when set; when blank, the
     * Contact settings' support email is used before falling back to `.env`.
     *
     * A separate field from the From address because they answer different
     * questions: From must sit on the provider-verified domain or DMARC fails,
     * and that address is normally a no-reply with no inbox. Reply-To is not
     * checked by SPF, DKIM or DMARC, so it can be the real support mailbox.
     */
    public ?string $mail_reply_to_address = null;

    public ?string $mail_reply_to_name = null;

    /**
     * The master switch. Off means nothing is sent, and callers are told so
     * rather than silently succeeding — a funnel that believes it emailed a
     * plan it never sent is worse than one that knows it did not.
     */
    public bool $email_enabled = false;

    // ── SMS ──────────────────────────────────────────────────────────────

    public bool $sms_enabled = false;

    /** Optional double opt-in confirmation message sent after signup. */
    public ?string $sms_opt_in_message = null;

    // ── Voice ─────────────────────────────────────────────────────────────

    public bool $voice_enabled = false;

    // ── Video (Telehealth) ────────────────────────────────────────────────

    /**
     * When enabled, the patient portal displays a video consult join link.
     * Requires PRX to expose the patient video token endpoint.
     */
    public bool $video_enabled = false;

    public static function group(): string
    {
        return 'communication';
    }

    /**
     * @return array<int, string>
     */
    public static function encrypted(): array
    {
        return [
            'twilio_account_sid',
            'twilio_auth_token',
            'mailgun_secret',
            'postmark_token',
            'ses_key',
            'ses_secret',
        ];
    }
}
