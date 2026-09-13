<?php

namespace App\Services\Patient;

use Illuminate\Support\Carbon;

/**
 * Ranks a patient's outstanding tasks into the portal's action stack.
 *
 * WHY THIS LIVES HERE AND NOT IN THE PORTAL. The tiers, their triggers and the
 * sort are clinical and commercial POLICY, not presentation. Two white-label
 * deployments may legitimately disagree about what is urgent — one surfaces lab
 * kits aggressively, another does not — and that difference has to be
 * configuration rather than a fork of a frontend. Computing it in a browser
 * also makes the policy unauditable and guarantees drift the first time a rule
 * changes on one deployment and not the other.
 *
 * The portal renders. It never decides what is urgent.
 *
 * THE FOUR TIERS
 *
 *   0 · Live      a visit joinable now, or within ±15 minutes.
 *   1 · Blocking  the patient is the reason nothing is moving.
 *   2 · Time-boxed something expires; the deadline is the point.
 *   3 · Routine   worth knowing, not worth interrupting for.
 *
 * Sort is tier ascending, then soonest deadline first. A task with no deadline
 * sorts last within its tier — it is not more urgent for being open-ended.
 *
 * TWO RULES THAT MAKE THE STACK MEAN ANYTHING
 *
 * - **At most one tier-0 task.** Two simultaneous live emergencies is a design
 *   failure, not a state to render, and a second one steals the attention the
 *   treatment is built to command. The soonest wins; the rest fall to tier 2.
 * - **A blocking task always says why.** "Finish your intake" is a chore.
 *   "Your provider can't review your screening until this is submitted" is a
 *   reason, and it is the difference between a task that gets done and one
 *   that gets ignored.
 */
class PatientActionStackService
{
    public const TIER_LIVE = 0;

    public const TIER_BLOCKING = 1;

    public const TIER_TIMEBOXED = 2;

    public const TIER_ROUTINE = 3;

    /** A visit is "live" from 15 minutes before its start until 15 after. */
    private const LIVE_WINDOW_MINUTES = 15;

    /** An appointment inside this window is time-boxed rather than routine. */
    private const IMMINENT_HOURS = 48;

    /**
     * The patient is the blocker in these states.
     *
     * `on_hold` is deliberately ABSENT even though a held encounter often is
     * waiting on the patient. The status is overloaded: the state machine also
     * parks sandbox and safeguarded encounters there for an admin to release,
     * and surfacing those as "you need to do something" would be a lie the
     * patient cannot act on. The reliable signal is `needs_documents` — see
     * `outstandingDocuments()`.
     */
    private const BLOCKING_STATUSES = [
        'pending_intake',
        'requires_information',
        'awaiting_patient_review',
        'cart',
    ];

    /**
     * Build the ranked stack.
     *
     * @param  array<int, array<string, mixed>>  $encounters
     * @param  array<string, mixed>  $dashboard
     * @return array<int, array<string, mixed>>
     */
    public function build(array $encounters, array $dashboard = []): array
    {
        $tasks = [
            ...$this->fromEncounters($encounters),
            ...$this->fromOrders($dashboard['recent_orders'] ?? []),
        ];

        $tasks = $this->demoteExtraLiveTasks($tasks);

        usort($tasks, fn ($a, $b) => $this->sortKey($a) <=> $this->sortKey($b));

        return $tasks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $encounters
     * @return array<int, array<string, mixed>>
     */
    private function fromEncounters(array $encounters): array
    {
        $tasks = [];

        foreach ($encounters as $encounter) {
            $status = (string) ($encounter['status'] ?? '');
            $id = (string) ($encounter['id'] ?? '');
            $scheduled = $this->parse($encounter['scheduled_at'] ?? $encounter['scheduled_for'] ?? null);

            if ($this->isLive($encounter, $scheduled)) {
                $tasks[] = [
                    'id' => "encounter:{$id}:live",
                    'tier' => self::TIER_LIVE,
                    'title' => 'Your provider is in the waiting room',
                    'body' => 'Your visit is starting. Your camera and microphone are already allowed.',
                    'cta_label' => 'Join the visit',
                    'cta_url' => "/care/visit/{$id}",
                    'deadline' => $scheduled?->toIso8601String(),
                    'chip_label' => 'Live now',
                    'progress_pct' => null,
                ];

                continue;
            }

            $outstanding = $this->outstandingDocuments($encounter);

            if (in_array($status, self::BLOCKING_STATUSES, true) || $outstanding !== []) {
                $tasks[] = [
                    'id' => "encounter:{$id}:blocking",
                    'tier' => self::TIER_BLOCKING,
                    ...$this->blockingCopy($status, $encounter, $outstanding),
                    'cta_url' => "/care/intake/{$id}",
                    'deadline' => $this->parse($encounter['info_requested_at'] ?? null)?->toIso8601String(),
                ];

                continue;
            }

            // Compare instants rather than a diff: Carbon 3's diffInHours is
            // SIGNED, so `$scheduled->diffInHours(now())` is negative for any
            // future date and `<= 48` was true for a visit three weeks out.
            // Every upcoming visit became a time-boxed task, which is the
            // "silent urgency" failure in reverse — noise is what makes a real
            // deadline invisible.
            if ($scheduled && $scheduled->isFuture() && $scheduled->lte(now()->addHours(self::IMMINENT_HOURS))) {
                $tasks[] = [
                    'id' => "encounter:{$id}:upcoming",
                    'tier' => self::TIER_TIMEBOXED,
                    'title' => 'Your visit is coming up',
                    'body' => 'Be somewhere quiet with a good connection a few minutes before it starts.',
                    'cta_label' => 'View the visit',
                    'cta_url' => "/care/visit/{$id}",
                    'deadline' => $scheduled->toIso8601String(),
                    // The deadline is the chip, never body copy: "in 3 hours"
                    // is actionable where a timestamp makes the patient do
                    // arithmetic. The portal renders a live countdown from it.
                    'chip_label' => 'Starts soon',
                    'progress_pct' => null,
                ];
            }
        }

        return $tasks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function fromOrders(array $orders): array
    {
        $tasks = [];

        foreach ($orders as $order) {
            if (($order['workflow_status'] ?? null) !== 'awaiting_patient_approval') {
                continue;
            }

            $tasks[] = [
                'id' => 'order:'.($order['id'] ?? '').':approval',
                'tier' => self::TIER_BLOCKING,
                'title' => 'Approve your prescription before it ships',
                'body' => 'Nothing ships and nothing is charged until you approve this.',
                'cta_label' => 'Review order',
                'cta_url' => '/record/orders/'.($order['id'] ?? ''),
                'deadline' => null,
                'chip_label' => 'Blocking your Rx',
                'progress_pct' => null,
            ];
        }

        return $tasks;
    }

    /**
     * A blocking task states the consequence, not just the chore.
     *
     * @param  array<string, mixed>  $encounter
     * @return array<string, mixed>
     */
    private function blockingCopy(string $status, array $encounter, array $outstanding = []): array
    {
        // Outstanding items outrank the status, because they are the only
        // thing here that says WHAT is missing. A held encounter with three
        // named items is a task the patient can finish; the same encounter
        // described by its status is a dead end. The copy says "item", not
        // "document": the list mixes files with text answers (a licence
        // number), and all of them are satisfiable on the intake page.
        if ($outstanding !== []) {
            $count = count($outstanding);

            return [
                'title' => $count === 1
                    ? 'One more item is needed for your visit'
                    : "{$count} more items are needed for your visit",
                'body' => $encounter['info_request_message']
                    ?: 'Your provider cannot review your visit until these are provided.',
                'cta_label' => 'Provide them now',
                'chip_label' => 'Blocking your Rx',
                'progress_pct' => $this->intakeProgress($encounter),
                'outstanding' => $outstanding,
            ];
        }

        return match ($status) {
            'requires_information' => [
                'title' => 'Your provider needs more information',
                // PRX puts the provider's own words here. Prefer them over ours
                // — a generic prompt loses the one detail that makes it doable.
                'body' => $encounter['info_request_message']
                    ?: 'Your provider has asked for something before they can continue.',
                'cta_label' => 'Send it now',
                'chip_label' => 'Blocking your Rx',
                'progress_pct' => null,
            ],
            'awaiting_patient_review' => [
                'title' => 'Review what your provider proposed',
                'body' => 'Your provider has finished. Nothing moves forward until you have read it.',
                'cta_label' => 'Read and confirm',
                'chip_label' => 'Blocking your Rx',
                'progress_pct' => null,
            ],
            default => [
                'title' => 'Finish your intake',
                'body' => 'Your provider cannot review your screening until this is submitted.',
                'cta_label' => 'Resume intake',
                'chip_label' => 'Blocking your Rx',
                'progress_pct' => $this->intakeProgress($encounter),
            ],
        };
    }

    /**
     * A visit counts as live inside a window either side of its start — a
     * patient arriving five minutes early and a provider running ten minutes
     * late are both the normal case, and neither should see the visit
     * described as merely "upcoming".
     */
    private function isLive(array $encounter, ?Carbon $scheduled): bool
    {
        if (($encounter['status'] ?? null) === 'provider_in_progress') {
            return true;
        }

        if (! $scheduled) {
            return false;
        }

        return $scheduled->between(
            now()->subMinutes(self::LIVE_WINDOW_MINUTES),
            now()->addMinutes(self::LIVE_WINDOW_MINUTES),
        );
    }

    /**
     * Keep the soonest live task; demote the rest to time-boxed.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<int, array<string, mixed>>
     */
    private function demoteExtraLiveTasks(array $tasks): array
    {
        $live = array_keys(array_filter(
            $tasks,
            fn ($task) => $task['tier'] === self::TIER_LIVE
        ));

        if (count($live) <= 1) {
            return $tasks;
        }

        usort($live, function ($a, $b) use ($tasks) {
            return ($tasks[$a]['deadline'] ?? '9999') <=> ($tasks[$b]['deadline'] ?? '9999');
        });

        foreach (array_slice($live, 1) as $index) {
            $tasks[$index]['tier'] = self::TIER_TIMEBOXED;
            $tasks[$index]['chip_label'] = 'Starts soon';
        }

        return $tasks;
    }

    /** Tier ascending, then soonest deadline; no deadline sorts last. */
    private function sortKey(array $task): string
    {
        $deadline = $task['deadline']
            ? str_pad((string) Carbon::parse($task['deadline'])->getTimestamp(), 13, '0', STR_PAD_LEFT)
            : str_repeat('9', 13);

        return $task['tier'].'-'.$deadline;
    }

    /**
     * The items the provider is still waiting on.
     *
     * `metadata.needs_documents.slugs` is the authoritative marker over the API:
     * the provider TRIMS satisfied slugs out of it and unsets the key entirely
     * once nothing is outstanding, so a non-empty list means the patient really
     * does still owe something right now.
     *
     * It is the cheap signal for ranking the stack: it rides on the encounter
     * list this service already has, where the full item list needs one
     * provider call per encounter. The intake page makes that call
     * (`GET /patient/encounters/{id}/requirements`), which answers per item
     * whether it is satisfied and carries the operator's wording from
     * `PortalSettings::requirement_labels`. `info_request_message` is frequently
     * null (it was on the live encounter this was built against), so it is not
     * a substitute. Slugs are returned raw here.
     *
     * @return array<int, string>
     */
    private function outstandingDocuments(array $encounter): array
    {
        $slugs = $encounter['metadata']['needs_documents']['slugs'] ?? null;

        if (! is_array($slugs)) {
            return [];
        }

        return array_values(array_filter($slugs, 'is_string'));
    }

    private function intakeProgress(array $encounter): ?int
    {
        // `completeness_score` on the intake snapshot, 0-100. Read live at 32 on
        // a half-finished encounter. There is no `intake_progress_pct` field —
        // an earlier version of this method read one and always returned null,
        // so every blocking card rendered without its completion meter.
        $pct = $encounter['patient_intake_snapshot']['completeness_score'] ?? null;

        return is_numeric($pct) ? (int) max(0, min(100, (int) $pct)) : null;
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // A malformed timestamp from upstream must not take down the whole
            // screen — the task simply loses its deadline and sorts last.
            return null;
        }
    }
}
