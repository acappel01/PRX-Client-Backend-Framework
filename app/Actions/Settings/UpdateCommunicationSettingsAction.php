<?php

namespace App\Actions\Settings;

use App\Actions\Concerns\Transacts;
use App\Data\Settings\CommunicationSettingsData;
use App\Services\Cms\ConfigCache;
use App\Settings\CommunicationSettings;

class UpdateCommunicationSettingsAction
{
    use Transacts;

    public function __construct(private CommunicationSettings $settings) {}

    public function execute(CommunicationSettingsData $data): CommunicationSettings
    {
        return $this->tx(function () use ($data) {
            $this->settings->twilio_account_sid = $data->twilio_account_sid;
            $this->settings->twilio_auth_token = $data->twilio_auth_token;
            $this->settings->twilio_from_number = $data->twilio_from_number;
            $this->settings->sms_enabled = $data->sms_enabled;
            $this->settings->sms_opt_in_message = $data->sms_opt_in_message;
            $this->settings->voice_enabled = $data->voice_enabled;
            $this->settings->video_enabled = $data->video_enabled;

            $this->settings->email_enabled = $data->email_enabled;
            $this->settings->mail_provider = $data->mail_provider;
            $this->settings->mailgun_domain = $data->mailgun_domain;
            $this->settings->mailgun_secret = $data->mailgun_secret;
            $this->settings->mailgun_endpoint = $data->mailgun_endpoint;
            $this->settings->postmark_token = $data->postmark_token;
            $this->settings->ses_key = $data->ses_key;
            $this->settings->ses_secret = $data->ses_secret;
            $this->settings->ses_region = $data->ses_region;
            $this->settings->mail_from_address = $data->mail_from_address;
            $this->settings->mail_from_name = $data->mail_from_name;
            $this->settings->mail_reply_to_address = $data->mail_reply_to_address;
            $this->settings->mail_reply_to_name = $data->mail_reply_to_name;

            $this->settings->save();

            // Invalidates BOTH caches between here and a visitor: this app's
            // own config entry and the decoupled frontend's fetch cache.
            // Clearing only the first left an edit invisible for the whole
            // ISR window — see ConfigCache.
            ConfigCache::invalidate();

            return $this->settings;
        });
    }
}
