<?php

namespace Tests\Feature\Workflows;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadDisposition;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowRun;
use App\Workflows\Jobs\RunWorkflowChain;
use App\Workflows\WorkflowDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The workflow chain runs off the request thread.
 *
 * WHAT THESE TESTS ARE ACTUALLY GUARDING is not "a job was pushed" — that is
 * one assertion and the least interesting one. It is that queueing did not
 * quietly undo the two things phase 2 got right:
 *
 *   * the `$afterCommit` rescue (item 19), which now has to survive being
 *     carried through a job payload rather than read off a live model. A test
 *     that does not wrap its write in a transaction CANNOT see that break —
 *     outside one, `getOriginal()` and the snapshot agree and the test passes
 *     either way. That is the whole lesson of the last session.
 *
 *   * the loop guard, which is per-process state. It only keeps working because
 *     a chain is queued at its root and runs inline from there. If a nested
 *     trigger ever starts queueing itself, the guard is split across workers and
 *     the infinite loop comes back.
 *
 * The suite runs on the `sync` queue driver (phpunit.xml), which executes jobs
 * inline at the dispatch point — but through a real serialize/unserialize of the
 * payload, so the snapshot round-trip below is genuinely exercised.
 */
class WorkflowQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        LeadDisposition::forgetMap();
        LeadDisposition::firstOrCreate(['slug' => 'quiz_complete'], ['name' => 'Quiz complete']);
        LeadDisposition::firstOrCreate(['slug' => 'nurture'], ['name' => 'Nurture']);
    }

    public function test_a_trigger_is_handed_to_the_queue_instead_of_the_request(): void
    {
        Queue::fake();

        $this->workflow(conditions: [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'quiz_complete'],
        ]);

        $lead = Lead::factory()->create(['status' => LeadStatus::New_->value]);
        $lead->update(['status' => 'quiz_complete']);

        Queue::assertPushed(RunWorkflowChain::class, 1);

        // Nothing ran on this thread. That is the point of the change: the
        // visitor's POST returns before anyone's CRM is contacted.
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function test_hidden_columns_never_enter_the_job_payload(): void
    {
        // The payload lives in Redis and, if the chain crashes, in `failed_jobs`
        // where Horizon shows it. Patients became a workflow subject for the
        // claim flow, and a Patient row carries a password hash. Assert on the
        // SERIALISED job, which is what actually leaves the process.
        $patient = \App\Models\Patient::factory()->create([
            'password' => 'correct horse battery staple',
            'remember_token' => 'remember-me-token-value',
        ]);
        $patient->password = 'a different password entirely';
        $patient->syncOriginal();

        $job = RunWorkflowChain::forContext(new \App\Workflows\WorkflowContext(
            triggerType: 'model_updated',
            triggerTarget: 'patient',
            subject: $patient,
            subjectKey: 'patient',
            original: $patient->getOriginal(),
            changed: ['password'],
        ));

        $payload = serialize($job);

        $this->assertStringNotContainsString($patient->getAttributes()['password'], $payload);
        $this->assertStringNotContainsString('remember-me-token-value', $payload);
        $this->assertArrayNotHasKey('password', $job->subjectAttributes);
        $this->assertArrayNotHasKey('password', $job->original);
        $this->assertSame($patient->email, $job->subjectAttributes['email']);
    }

    public function test_the_chain_lands_on_its_own_queue(): void
    {
        // Not decoration. `config/horizon.php` supervises this queue by name; a
        // job pushed anywhere else is a job no worker collects, and the symptom
        // is silence rather than an error.
        Queue::fake();

        $this->workflow(conditions: []);

        Lead::factory()->create(['status' => LeadStatus::New_->value])->update(['status' => 'nurture']);

        Queue::assertPushed(
            RunWorkflowChain::class,
            fn (RunWorkflowChain $job): bool => $job->queue === 'workflows',
        );
    }

    public function test_a_trigger_matching_no_workflow_costs_no_job(): void
    {
        // Every save of every registered subject reaches the dispatcher,
        // including admin form saves. Pushing a job for each one to discover
        // there was nothing to do would make the queue a log of non-events.
        Queue::fake();

        Lead::factory()->create(['status' => LeadStatus::New_->value])->update(['status' => 'nurture']);

        Queue::assertNothingPushed();
    }

    public function test_original_survives_the_journey_through_the_queue(): void
    {
        // THE ITEM-19 REGRESSION, ONE LAYER UP, and it is only visible inside a
        // transaction. The enqueue happens in an $afterCommit callback, so by
        // then the model's own getOriginal() already reports the NEW value; if
        // the job payload carried that instead of the ModelChangeSnapshot
        // capture, `_original.status` would equal `status` and this workflow
        // could never match. Outside a transaction the two agree and the test
        // proves nothing at all.
        $this->workflow(conditions: [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'quiz_complete'],
            ['field' => '_original.status', 'operator' => 'equals', 'value' => LeadStatus::New_->value],
        ]);

        $lead = Lead::factory()->create(['status' => LeadStatus::New_->value]);

        DB::transaction(fn () => $lead->update(['status' => 'quiz_complete']));

        $this->assertSame(
            WorkflowRun::STATUS_COMPLETED,
            WorkflowRun::query()->latest('id')->first()?->status,
        );
    }

    public function test_the_subject_survives_the_journey_and_is_still_writable(): void
    {
        // The subject is rehydrated from an attribute snapshot rather than
        // re-fetched, so this pins that the rehydrated instance still behaves
        // like a persisted model: an update_field action must produce an UPDATE,
        // not an INSERT and not a no-op.
        $workflow = $this->workflow(conditions: [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'quiz_complete'],
        ]);
        $this->action($workflow, 'update_field', ['field' => 'first_name', 'value' => 'Rehydrated']);

        $lead = Lead::factory()->create(['status' => LeadStatus::New_->value]);
        $lead->update(['status' => 'quiz_complete']);

        $this->assertSame('Rehydrated', $lead->fresh()->first_name);
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_a_deleted_subject_still_reaches_its_workflow(): void
    {
        // The trigger whose entire point is that the row is gone. Re-fetching
        // the subject by key would throw ModelNotFoundException at unserialize
        // and this trigger would never run in production, while every test that
        // did not delete anything stayed green.
        $this->workflow(trigger: 'model_deleted', conditions: []);

        $lead = Lead::factory()->create(['status' => LeadStatus::New_->value]);
        $lead->delete();

        $run = WorkflowRun::query()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame(WorkflowRun::STATUS_COMPLETED, $run->status);
    }

    public function test_a_chain_is_queued_once_and_runs_inline_from_there(): void
    {
        // The load-bearing one. Workflow A moves the lead, which raises a second
        // trigger from INSIDE the job; B reacts to it. If that nested trigger
        // queued itself, the re-entry claim taken before A's actions would be
        // invisible to B's process and the loop guard would be gone.
        //
        // So: exactly ONE job, and both runs recorded by it.
        $a = $this->workflow(slug: 'a', priority: 1, conditions: [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'nurture'],
        ]);
        $this->action($a, 'update_field', ['field' => 'status', 'value' => 'quiz_complete']);

        $b = $this->workflow(slug: 'b', priority: 2, conditions: [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'quiz_complete'],
        ]);
        $this->action($b, 'update_field', ['field' => 'first_name', 'value' => 'B ran inline']);

        $pushed = 0;
        Queue::before(function ($event) use (&$pushed): void {
            if ($event->job->resolveName() === RunWorkflowChain::class) {
                $pushed++;
            }
        });

        $lead = Lead::factory()->create(['status' => LeadStatus::New_->value]);
        $lead->update(['status' => 'nurture']);

        $this->assertSame(1, $pushed, 'the chain must be queued at its root only');
        $this->assertSame('B ran inline', $lead->fresh()->first_name);
    }

    public function test_a_workflow_that_updates_the_field_it_watches_still_terminates_when_queued(): void
    {
        // The direct cycle, re-pinned on the queued path. The guard lives in
        // per-process state, so this is the test that fails first if anyone
        // "improves" the design by queueing every hop.
        $workflow = $this->workflow(conditions: [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'nurture'],
        ]);
        $this->action($workflow, 'update_field', ['field' => 'status', 'value' => 'nurture']);

        $lead = Lead::factory()->create(['status' => LeadStatus::New_->value]);
        $lead->update(['status' => 'nurture']);

        $this->assertLessThanOrEqual(
            2,
            WorkflowRun::query()->where('status', WorkflowRun::STATUS_COMPLETED)->count(),
        );
    }

    public function test_a_dispatcher_left_mid_chain_is_reset_rather_than_inherited(): void
    {
        // A worker is long-lived and the dispatcher is a singleton inside it. If
        // a chain ever escaped its own `finally`, every later chain in that
        // worker would start deeper until MAX_DEPTH silently swallowed them all.
        // Unreachable today; the guard is here so a future break is loud.
        $dispatcher = app(WorkflowDispatcher::class);

        $depth = new \ReflectionProperty($dispatcher, 'depth');
        $seen = new \ReflectionProperty($dispatcher, 'seen');

        $depth->setValue($dispatcher, 3);
        $seen->setValue($dispatcher, ['1:App\\Models\\Lead:9' => true]);

        $dispatcher->assertIdle();

        $this->assertSame(0, $depth->getValue($dispatcher));

        // Both halves. A leaked CLAIM is the quieter of the two failures: depth
        // eventually stops chains outright, but a stale fingerprint suppresses
        // one particular workflow for one particular record and writes a
        // "withheld to prevent a loop" row that looks entirely reasonable.
        $this->assertSame([], $seen->getValue($dispatcher));
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function workflow(
        string $slug = 'wf',
        array $conditions = [],
        int $priority = 0,
        string $trigger = 'model_updated',
        string $target = 'lead',
    ): Workflow {
        return Workflow::create([
            'name' => $slug,
            'slug' => $slug,
            'trigger_type' => $trigger,
            'trigger_target' => $target,
            'conditions' => $conditions,
            'is_active' => true,
            'priority' => $priority,
            'stop_on_first_match' => false,
        ]);
    }

    private function action(Workflow $workflow, string $type, array $config, int $sort = 0): WorkflowAction
    {
        return WorkflowAction::create([
            'workflow_id' => $workflow->id,
            'action_type' => $type,
            'config' => $config,
            'is_active' => true,
            'sort_order' => $sort,
            'halt_on_failure' => false,
        ]);
    }
}
