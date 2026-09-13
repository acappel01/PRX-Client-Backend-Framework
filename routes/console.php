<?php

use App\Models\Commerce\Cart;
use App\Models\PatientAuthChallenge;
use App\Models\PatientSecurityEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| These need `schedule:run` on a per-minute cron. Without it nothing here
| runs, and nothing says so — the app keeps serving and the work silently
| never happens.
|
| NOTE THE DISTINCTION, because it is easy to conflate: QUEUED work (jobs,
| workflows, integration pushes, cache revalidation) is processed by Horizon
| and does not depend on this file at all. The scheduler covers only
| time-based recurring work.
|
| `onOneServer()` is deliberate throughout. This box hosts several Laravel
| apps and may one day be more than one box; a duplicate prune or snapshot is
| wasteful at best.
|
*/

// Horizon's Metrics tab is built entirely from these snapshots. Without it
// the dashboard renders empty forever — no throughput, no runtime trends —
// which is exactly how this install ran with no queue visibility at all.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer();

// `failed_jobs` grows without bound. A week is long enough to investigate a
// failure and short enough that the table never becomes the problem.
Schedule::command('queue:prune-failed --hours=168')
    ->weekly()
    ->onOneServer();

// Batched-job bookkeeping, same reasoning.
Schedule::command('queue:prune-batches --hours=168')
    ->weekly()
    ->onOneServer();

// Abandoned carts, 90 days. Every anonymous visitor mints a row and nothing
// else removes it, so this table grows with traffic. The window and the two
// guards live on the model — see Cart::prunable(), particularly the note
// about what has to change when internal affiliate tracking lands.
//
// Explicit --model so this reaps ONLY carts. `model:prune` with no argument
// discovers every Prunable model in the app, which would quietly widen the
// blast radius the day someone adds the trait somewhere else.
Schedule::command('model:prune', ['--model' => [Cart::class]])
    ->dailyAt('03:20')
    ->onOneServer();

// Patient security history, by the operator's retention setting (Settings →
// Patient portal). MassPrunable: a query-level delete of whole rows, the one
// removal the append-only model allows. Its own entry, not added to the cart
// list above, so either can be changed without reasoning about the other.
Schedule::command('model:prune', ['--model' => [PatientSecurityEvent::class]])
    ->dailyAt('03:30')
    ->onOneServer();

// Two-step sign-in challenges live five minutes; the dead ones go after a day.
Schedule::command('model:prune', ['--model' => [PatientAuthChallenge::class]])
    ->dailyAt('03:35')
    ->onOneServer();

// Signatures on that history. Weekly is enough to learn that a row was edited;
// it cannot say anything about a row that was deleted.
Schedule::command('patient-security-events:verify')
    ->weeklyOn(1, '04:00')
    ->onOneServer();

// Harmless today (no tokens exist yet) and required the moment the API moves
// behind Sanctum — an expired token left in the table is a credential that
// looks live in the admin.
Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->onOneServer();
