<?php

namespace App\Jobs\Patient;

use App\Integrations\Messages\EmailMessage;
use App\Models\Patient;
use App\Services\Patient\PatientMail;
use App\Settings\BrandSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Your password was changed" — to the account's own address, after a reset.
 *
 * The alarm for a reset the owner did not make: if someone else held the mailbox
 * for a moment, the owner still learns of it. It carries no token and names
 * nothing anyone typed (after a takeover the account may still carry a
 * squatter's name), and points to the forgot-password page so the owner can
 * take the account straight back. Replies reach support via the global Reply-To.
 *
 * Queued, and failing quietly: the password is already changed, and a notice
 * that could not be sent must not undo or re-run that.
 */
class SendPasswordChangedNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $patientId) {}

    public function handle(PatientMail $mail, BrandSettings $brand): void
    {
        $patient = Patient::find($this->patientId);

        if ($patient === null) {
            return;
        }

        try {
            $delivery = $mail->deliveryOrFail('Password changed notice');

            $name = $brand->name;
            $forgot = $mail->portalLink('forgot_path');

            $body = <<<TEXT
                Hi,

                The password for your {$name} account was just changed, and every device signed in to it has been signed out.

                If this was you, there is nothing else to do.

                If it was not, set a new password now at {$forgot} and reply to this email to let us know.
                TEXT;

            $mail->send($delivery, new EmailMessage(
                to: $patient->email,
                subject: "{$name}: your password was changed",
                body: $body,
                trackLinks: false,
            ));
        } catch (Throwable $e) {
            Log::error('Password changed notice failed to send.', [
                'patient_id' => $patient->getKey(),
                'exception' => $e::class,
            ]);
        }
    }
}
