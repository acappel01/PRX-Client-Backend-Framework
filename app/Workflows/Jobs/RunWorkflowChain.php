<?php

namespace App\Workflows\Jobs;

use App\Workflows\WorkflowContext;
use App\Workflows\WorkflowDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one workflow chain off the request thread.
 *
 * ─── Why the chain is queued at its ROOT and nowhere else ───────────────
 *
 * The whole trigger → match → run → action chain used to execute inside the
 * request that caused it, so a webhook action on `lead.created` held the public
 * `POST /api/v1/leads` open until the far end answered. That was tolerable while
 * every action was fast; it stops being tolerable the moment an action talks to
 * a CRM.
 *
 * ONLY THE OUTERMOST TRIGGER IS QUEUED. Once this job is running, the chain it
 * starts runs INLINE inside it, exactly as it used to inside the request — see
 * WorkflowDispatcher::queue(). That is not a shortcut, it is the only version
 * that is correct: the dispatcher's re-entry guard is per-process state, and an
 * action's own write re-enters the dispatcher from INSIDE run(). Push each hop
 * onto the queue instead and every hop gets its own COPY of that bookkeeping,
 * two actions raising triggers fork the chain into jobs whose claim sets
 * diverge, and release-on-skip in one branch is invisible to the other. The
 * guard would have to become a distributed lock to buy nothing.
 *
 * So the shape is: one job per chain, and the chain is a chain again once it is
 * inside.
 *
 * ─── Why the subject travels as a SNAPSHOT, not as a model reference ────
 *
 * `SerializesModels` re-fetches by key when the job wakes, which breaks two
 * ways here. `model_deleted` has no row left to fetch, so the one trigger whose
 * entire purpose is "this record is gone" would fail at unserialize and never
 * run. And when the row HAS moved between enqueue and run, conditions would
 * evaluate against the new state while `_original.*` still describes the old
 * write — a context stitched from two moments, in which "became quiz_complete
 * from new" can skip a transition that genuinely happened.
 *
 * The attributes are therefore carried as they were AT THE TRIGGER, and
 * rehydrated with `newFromBuilder()`, which marks the model as existing and
 * syncs original to those values. Two consequences worth knowing:
 *
 *   * An `update_field` action still writes only the field it sets — Eloquent
 *     sends the dirty attributes, not the row — so a stale snapshot cannot
 *     clobber a column something else moved meanwhile.
 *   * If the subject was DELETED between enqueue and run, that write updates
 *     zero rows and says nothing. A no-op is the right outcome; the silence is
 *     the part to remember.
 *
 * An action that genuinely needs the freshest state should re-read inside its
 * own handler. That is a per-action decision, and this is the wrong layer to
 * make it for everybody.
 *
 * ─── Why it does not retry ─────────────────────────────────────────────
 *
 * `$tries = 1` on purpose. Per-action failures are already caught and recorded
 * as `workflow_action_runs` rows by the runner, so a failure that reaches THIS
 * level means the chain itself crashed part-way — and a retry would re-run the
 * actions that had already completed. Webhooks and CRM pushes are not
 * idempotent; at-least-once is the wrong default for an engine whose actions
 * are side effects on other people's systems. Anything that wants retries
 * should ask for them per action, where the handler knows whether repeating is
 * safe.
 */
class RunWorkflowChain implements ShouldQueue
{
    /**
     * NOTE FOR ANYONE ADDING A PROPERTY: this trait composes `SerializesModels`,
     * which replaces any Model-typed property with a reference that is RE-FETCHED
     * on unserialize. Every property below is deliberately a scalar or an array
     * for that reason — a Model-typed one would silently reinstate exactly the
     * re-fetch behaviour the class doc above explains we cannot have.
     */
    use Queueable;

    /** @see the class doc — a retry re-sends side effects that already landed. */
    public int $tries = 1;

    /**
     * @param  class-string<Model>|null  $subjectClass
     * @param  array<string, mixed>  $subjectAttributes  Raw attributes as they were at the trigger.
     * @param  array<string, mixed>  $original
     * @param  list<string>  $changed
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $triggerType,
        public readonly string $triggerTarget,
        public readonly ?string $subjectClass,
        public readonly ?string $subjectKey,
        public readonly array $subjectAttributes,
        public readonly array $original,
        public readonly array $changed,
        public readonly array $payload,
    ) {
        $this->onQueue('workflows');
    }

    /** Build a job from the context the trigger produced. */
    public static function forContext(WorkflowContext $context): self
    {
        $subject = $context->subject;

        // The payload sits in Redis and, when a chain crashes, in `failed_jobs`
        // where Horizon displays it. A model's `$hidden` columns — a patient's
        // password hash and remember token — have no business in either. Nothing
        // downstream reads them: conditions and mappers are allow-listed, and
        // `update_field` saves only what it dirtied.
        $hidden = $subject === null ? [] : array_flip($subject->getHidden());

        return new self(
            triggerType: $context->triggerType,
            triggerTarget: $context->triggerTarget,
            subjectClass: $subject === null ? null : $subject::class,
            subjectKey: $context->subjectKey,
            subjectAttributes: $subject === null ? [] : array_diff_key($subject->getAttributes(), $hidden),
            original: array_diff_key($context->original, $hidden),
            changed: $context->changed,
            payload: $context->payload,
        );
    }

    public function handle(WorkflowDispatcher $dispatcher): void
    {
        // The job IS the root of its chain. A worker is long-lived and the
        // dispatcher is a singleton within it, so if some earlier chain ever
        // escaped its own `finally` this would start part-way down and quietly
        // exhaust MAX_DEPTH for every lead that followed until the worker
        // recycled. The invariant holds today; this makes a future break loud
        // and self-healing instead of invisible.
        $dispatcher->assertIdle();

        $dispatcher->dispatch($this->triggerType, $this->triggerTarget, new WorkflowContext(
            triggerType: $this->triggerType,
            triggerTarget: $this->triggerTarget,
            subject: $this->rehydrate(),
            subjectKey: $this->subjectKey,
            original: $this->original,
            changed: $this->changed,
            payload: $this->payload,
        ));
    }

    /**
     * Loud, because a chain that died here left no run row explaining itself.
     *
     * The runner records everything it can reach; this hook covers what it
     * cannot — the chain falling over before or between runs.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('Workflow chain failed before it finished.', [
            'trigger_type' => $this->triggerType,
            'trigger_target' => $this->triggerTarget,
            'subject_type' => $this->subjectClass,
            'subject_id' => $this->subjectAttributes['id'] ?? null,
            'exception' => $e?->getMessage(),
        ]);
    }

    private function rehydrate(): ?Model
    {
        if ($this->subjectClass === null || ! is_a($this->subjectClass, Model::class, true)) {
            return null;
        }

        // newFromBuilder(), not new + fill: it marks the model as existing and
        // syncs original to these values, so the instance behaves like one that
        // was just read — casts apply, getKey() works, and a save writes an
        // UPDATE rather than an INSERT.
        return (new $this->subjectClass)->newFromBuilder($this->subjectAttributes);
    }
}
