<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Events\Patient\AccountCreated;
use App\Events\Patient\ClaimLinkRequested;
use App\Events\Patient\EmailVerified;
use App\Events\Patient\PasswordChanged;
use App\Events\Patient\RecordClaimed;
use App\Workflows\WorkflowRegistry;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The patient account events a workflow can hang follow-ups on.
 *
 * 🔴 None may carry a link token or a password. The links are credentials and
 * are delivered by the system precisely so they never enter a workflow context,
 * a queued run or a run log. An event whose only public property is the patient
 * cannot leak one, however a workflow is later configured.
 */
class PatientWorkflowEventsTest extends TestCase
{
    private const EVENTS = [
        'patient.claim_link_requested' => ClaimLinkRequested::class,
        'patient.record_claimed' => RecordClaimed::class,
        'patient.email_verified' => EmailVerified::class,
        'patient.account_created' => AccountCreated::class,
        'patient.password_changed' => PasswordChanged::class,
    ];

    public function test_each_is_registered_as_a_trigger_on_the_patient_subject(): void
    {
        $registry = app(WorkflowRegistry::class);

        foreach (self::EVENTS as $key => $class) {
            $event = $registry->event($key);

            $this->assertNotNull($event, "{$key} is not registered.");
            $this->assertSame($class, $event['event'], "{$key} maps to the wrong class.");
            $this->assertSame('patient', $event['subject']);
        }
    }

    public function test_each_carries_the_patient_and_nothing_else(): void
    {
        foreach (self::EVENTS as $class) {
            $properties = array_map(
                fn (ReflectionProperty $p) => $p->getName(),
                (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC),
            );

            $this->assertSame(['patient'], $properties, "{$class} carries more than the patient.");
        }
    }
}
