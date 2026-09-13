<?php

namespace App\Services\Patient;

use App\Models\Patient;
use App\Models\PatientRecoveryCode;
use App\Settings\BrandSettings;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * The TOTP and recovery-code primitives behind patient two-step verification.
 *
 * Built on `pragmarx/google2fa` directly rather than Filament's MFA component,
 * which is bound to the admin panel's own user, keeps replay state in the cache,
 * accepts codes ±4 minutes old and bcrypts recovery codes.
 *
 * ── Codes ────────────────────────────────────────────────────────────────────
 *
 * RFC 6238, 30-second steps, window ±1 step (three codes valid at any instant).
 * Secrets are 32 base32 characters (160 bits). A code is accepted only by a
 * conditional UPDATE that moves `two_factor_last_timestep` forward, so the same
 * code cannot be used twice — not even by two requests in the same instant.
 *
 * ── Recovery codes ───────────────────────────────────────────────────────────
 *
 * Eight codes, 16 symbols each from a 31-symbol alphabet without look-alikes
 * (~79 bits), shown once as XXXX-XXXX-XXXX-XXXX. Stored as sha256 of the
 * normalised form and consumed by a conditional UPDATE on `used_at`.
 */
class TwoFactor
{
    public const WINDOW = 1;

    public const SECRET_LENGTH = 32;

    public const RECOVERY_CODE_COUNT = 8;

    /** No 0/O, 1/I/L: 23 letters + 8 digits = 31 symbols, ~4.95 bits each. */
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly BrandSettings $brand,
    ) {}

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(self::SECRET_LENGTH);
    }

    /** The `otpauth://` URI an authenticator app scans. Issuer is the brand, never APP_NAME. */
    public function otpauthUri(Patient $patient, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($this->brand->name ?: 'Patient portal', $patient->email, $secret);
    }

    /** The URI as a QR code, as an SVG data URI ready for an <img>. Rendered, never stored. */
    public function qrCodeDataUri(string $otpauthUri): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel' => QRCode::ECC_M,
            'outputBase64' => true,
        ])))->render($otpauthUri);
    }

    /**
     * The time step a code is valid for against `$secret`, or null.
     *
     * `$after` is the last accepted step; only a later one is returned.
     */
    public function matchingTimestep(string $secret, mixed $code, ?int $after): ?int
    {
        $code = is_string($code) || is_int($code) ? preg_replace('/\s+/', '', (string) $code) : '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        // With a non-null old timestep, verifyKeyNewer returns the matching
        // step as an int (or false); 0 stands for "nothing accepted yet".
        $step = $this->google2fa->verifyKeyNewer($secret, $code, $after ?? 0, self::WINDOW);

        return is_int($step) && $step > ($after ?? 0) ? $step : null;
    }

    /**
     * Accept a TOTP code for the patient's confirmed secret, once.
     *
     * True only when this call moved the patient's last accepted step forward.
     */
    public function acceptCode(Patient $patient, mixed $code): bool
    {
        if (! $patient->hasTwoFactor()) {
            return false;
        }

        $step = $this->matchingTimestep($patient->two_factor_secret, $code, $patient->two_factor_last_timestep);

        if ($step === null) {
            return false;
        }

        $won = Patient::query()
            ->whereKey($patient->getKey())
            ->where(fn ($q) => $q->whereNull('two_factor_last_timestep')->orWhere('two_factor_last_timestep', '<', $step))
            ->update(['two_factor_last_timestep' => $step]);

        if ($won !== 1) {
            return false;
        }

        $patient->forceFill(['two_factor_last_timestep' => $step])->syncOriginalAttribute('two_factor_last_timestep');

        return true;
    }

    /** Spend a recovery code, once. */
    public function acceptRecoveryCode(Patient $patient, mixed $input): bool
    {
        $normalised = self::normaliseRecoveryCode($input);

        if ($normalised === null) {
            return false;
        }

        $code = PatientRecoveryCode::query()
            ->where('patient_id', $patient->getKey())
            ->where('code_hash', hash('sha256', $normalised))
            ->whereNull('used_at')
            ->first();

        if ($code === null) {
            return false;
        }

        return PatientRecoveryCode::query()
            ->whereKey($code->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }

    /** A TOTP code or a recovery code — whichever the input looks like. */
    public function acceptEither(Patient $patient, mixed $input): ?string
    {
        if ($this->acceptCode($patient, $input)) {
            return 'totp';
        }

        return $this->acceptRecoveryCode($patient, $input) ? 'recovery_code' : null;
    }

    /**
     * Replace every recovery code with a fresh set and return the plain codes.
     * The only moment they exist outside a hash.
     *
     * @return list<string>
     */
    public function replaceRecoveryCodes(Patient $patient): array
    {
        $codes = [];

        while (count($codes) < self::RECOVERY_CODE_COUNT) {
            $codes[self::newRecoveryCode()] = true;
        }

        $codes = array_keys($codes);

        DB::transaction(function () use ($patient, $codes): void {
            PatientRecoveryCode::query()->where('patient_id', $patient->getKey())->delete();

            PatientRecoveryCode::query()->insert(array_map(fn (string $code): array => [
                'patient_id' => $patient->getKey(),
                'code_hash' => hash('sha256', self::normaliseRecoveryCode($code)),
                'created_at' => now(),
            ], $codes));
        });

        return $codes;
    }

    public function remainingRecoveryCodes(Patient $patient): int
    {
        return PatientRecoveryCode::query()->where('patient_id', $patient->getKey())->whereNull('used_at')->count();
    }

    /** Upper-case, separators stripped; null unless it is the right length and alphabet. */
    public static function normaliseRecoveryCode(mixed $input): ?string
    {
        if (! is_string($input)) {
            return null;
        }

        $code = Str::upper(preg_replace('/[\s-]+/', '', $input));

        return preg_match('/^['.self::RECOVERY_ALPHABET.']{16}$/', $code) ? $code : null;
    }

    private static function newRecoveryCode(): string
    {
        $symbols = '';

        for ($i = 0; $i < 16; $i++) {
            $symbols .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
        }

        return implode('-', str_split($symbols, 4));
    }
}
