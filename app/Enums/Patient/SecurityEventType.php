<?php

namespace App\Enums\Patient;

/**
 * What happened to a patient account. Stored as a string column, not a database
 * enum, so a later increment (two-factor, trusted devices) adds a case here
 * without a migration.
 *
 * Reserved for later increments, deliberately not built yet:
 * `step_up_succeeded`.
 */
enum SecurityEventType: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case SessionExpired = 'session_expired';
    case SessionsRevoked = 'sessions_revoked';
    case ClaimLinkSent = 'claim_link_sent';
    case ResetLinkSent = 'reset_link_sent';
    case CreateAccountLinkSent = 'create_account_link_sent';
    case AccountCreated = 'account_created';
    case EmailVerified = 'email_verified';
    case RecordClaimed = 'record_claimed';
    case PasswordChanged = 'password_changed';
    case EmailChanged = 'email_changed';
    case ChartLinkChanged = 'chart_link_changed';
    case AccountDeleted = 'account_deleted';
    case AccountRestored = 'account_restored';
    case AccountPurged = 'account_purged';
    case TwoFactorChallenged = 'two_factor_challenged';
    case TwoFactorChallengeFailed = 'two_factor_challenge_failed';
    case TwoFactorEnrolled = 'two_factor_enrolled';
    case TwoFactorRemoved = 'two_factor_removed';
    case RecoveryCodeUsed = 'recovery_code_used';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case DeviceTrusted = 'device_trusted';
    case DeviceRevoked = 'device_revoked';

    /** Written for the patient as much as the operator — the portal shows it. */
    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Signed in',
            self::LoginFailed => 'Sign-in attempt failed',
            self::Logout => 'Signed out',
            self::SessionExpired => 'Signed out automatically',
            self::SessionsRevoked => 'Signed out of other sessions',
            self::ClaimLinkSent => 'Link to connect a record sent',
            self::ResetLinkSent => 'Password reset link sent',
            self::CreateAccountLinkSent => 'Account creation link sent',
            self::AccountCreated => 'Account created',
            self::EmailVerified => 'Email address verified',
            self::RecordClaimed => 'Record connected',
            self::PasswordChanged => 'Password changed',
            self::EmailChanged => 'Email address changed',
            self::ChartLinkChanged => 'Record link changed',
            self::AccountDeleted => 'Account deleted',
            self::AccountRestored => 'Account restored',
            self::AccountPurged => 'Account permanently deleted',
            self::TwoFactorChallenged => 'Password accepted, code requested',
            self::TwoFactorChallengeFailed => 'Wrong verification code',
            self::TwoFactorEnrolled => 'Two-step verification turned on',
            self::TwoFactorRemoved => 'Two-step verification turned off',
            self::RecoveryCodeUsed => 'Recovery code used',
            self::RecoveryCodesRegenerated => 'New recovery codes created',
            self::DeviceTrusted => 'Browser trusted',
            self::DeviceRevoked => 'Trusted browsers removed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LoginFailed, self::AccountDeleted, self::AccountPurged, self::TwoFactorChallengeFailed, self::TwoFactorRemoved => 'danger',
            self::RecoveryCodeUsed, self::RecoveryCodesRegenerated => 'warning',
            self::TwoFactorEnrolled => 'success',
            self::SessionsRevoked, self::PasswordChanged, self::EmailChanged, self::ChartLinkChanged => 'warning',
            self::LoginSucceeded, self::AccountCreated, self::RecordClaimed, self::EmailVerified, self::AccountRestored => 'success',
            default => 'gray',
        };
    }
}
