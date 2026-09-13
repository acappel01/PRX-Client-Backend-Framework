<?php

namespace App\Listeners\Quiz;

use App\Events\Quiz\QuizCompleted;
use App\Mail\PlanReadyMail;
use App\Services\Mail\MailConfigurator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the plan, if and only if we actually can.
 *
 * THREE CONDITIONS, and each is a different kind of "no":
 *
 * - The visitor consented. Without that there is nothing to discuss.
 * - The operator enabled email. A configured mailer is not permission.
 * - A real transport is selected. `log` and `array` are working mailers that
 *   reach nobody, and a funnel that tells someone "we've sent it" on that
 *   basis is lying with a green tick.
 *
 * `plan_sent_at` is stamped ONLY after the send returns, so the plan page
 * reads a fact rather than an intention. A failure leaves it null, the page
 * keeps saying the report is being prepared, and the row is visibly
 * un-emailed to whoever looks — which is the state we want to be in when
 * something is broken.
 *
 * Queued, and deliberately failing quietly: a marketing send must never be the
 * reason a lead is lost. The exception is logged with the lead's UUID so it is
 * traceable without putting an email address in the log.
 */
class SendPlanEmail implements ShouldQueue
{
    public function __construct(private readonly MailConfigurator $mail) {}

    public function handle(QuizCompleted $event): void
    {
        $lead = $event->lead;

        if (! $lead->email_consent || blank($lead->email)) {
            return;
        }

        if (! $this->mail->canSend()) {
            Log::info('Plan email skipped: sending is not enabled or no real transport is selected.', [
                'lead_uuid' => $lead->uuid,
            ]);

            return;
        }

        try {
            Mail::to($lead->email)->send(new PlanReadyMail($lead, $this->planUrl($lead)));

            $lead->forceFill(['plan_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::error('Plan email failed to send.', [
                'lead_uuid' => $lead->uuid,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The frontend owns URL patterns, so the one place this backend needs to
     * know one is configured rather than assumed. Falls back to the app URL,
     * which is wrong but visible, instead of building a link to nowhere.
     *
     * Not MailBrand::url(), on purpose: the header only names the brand and may
     * point at its canonical site; this button must reach a frontend route.
     */
    private function planUrl(object $lead): string
    {
        $base = rtrim((string) (config('cms.frontend.url') ?: config('app.url')), '/');

        return "{$base}/plan/{$lead->uuid}";
    }
}
