<?php

namespace App\Jobs\Patient;

use App\Actions\Patient\RequestAccountLinkAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Decide and send an anonymous account link, after the request has answered.
 *
 * The request answers a uniform 202 before any of this runs, so how long it
 * took cannot say whether the address has an account or an order. See
 * RequestAccountLinkAction.
 *
 * ENCRYPTED because the payload holds an email address and an IP, both PII,
 * sitting in Redis until a worker takes it. It holds NO token: the credential is
 * minted inside the worker and exists only in its memory and the email.
 *
 * ONE TRY, set here rather than trusted to the worker config: a retry after a
 * send that actually went out would mail the person twice and supersede the
 * link they already have. A failure is logged and the person asks again.
 */
class SendAccountLinkJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $email,
        public readonly ?string $ip = null,
    ) {}

    public function handle(RequestAccountLinkAction $action): void
    {
        try {
            $action->execute($this->email, $this->ip);
        } catch (Throwable $e) {
            // A database exception's message embeds its bindings — here, the
            // address. Left to propagate, it would be written in plain text to
            // `failed_jobs`, the Horizon failed-job screen and the log, undoing
            // the payload encryption. Record the class only.
            Log::error('Account link job failed.', ['exception' => $e::class]);

            $this->fail(new RuntimeException('Account link job failed: '.$e::class));
        }
    }
}
