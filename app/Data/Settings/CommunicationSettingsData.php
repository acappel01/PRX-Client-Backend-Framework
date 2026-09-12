<?php

namespace App\Data\Settings;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Everything the Communication settings page saves.
 *
 * The Email fields were on the page from the day they were added but missing
 * here and from the action, so "Send email", the provider and the From address
 * all reported "saved" and wrote nothing. A form field absent from this class
 * is dropped without a word — ManageCommunicationEmailSaveTest drives the real
 * form for that reason.
 */
class CommunicationSettingsData extends Data
{
    public function __construct(
        #[Max(64)]
        public ?string $twilio_account_sid = null,
        #[Max(64)]
        public ?string $twilio_auth_token = null,
        #[Max(20)]
        public ?string $twilio_from_number = null,
        public bool $sms_enabled = false,
        #[Max(500)]
        public ?string $sms_opt_in_message = null,
        public bool $voice_enabled = false,
        public bool $video_enabled = false,

        public bool $email_enabled = false,
        #[In(['mailgun', 'postmark', 'ses', 'smtp'])]
        public ?string $mail_provider = null,
        #[Max(255)]
        public ?string $mailgun_domain = null,
        #[Max(255)]
        public ?string $mailgun_secret = null,
        #[Max(255)]
        public ?string $mailgun_endpoint = null,
        #[Max(255)]
        public ?string $postmark_token = null,
        #[Max(255)]
        public ?string $ses_key = null,
        #[Max(255)]
        public ?string $ses_secret = null,
        #[Max(64)]
        public ?string $ses_region = null,
        #[Email, Max(255)]
        public ?string $mail_from_address = null,
        #[Max(255)]
        public ?string $mail_from_name = null,
        #[Email, Max(255)]
        public ?string $mail_reply_to_address = null,
        #[Max(255)]
        public ?string $mail_reply_to_name = null,
    ) {}
}
