<?php

namespace App\Providers;

use App\Enums\Integrations\IntegrationCapability;
use App\Enums\Privacy\DataClassification;
use App\Events\Leads\LeadCreated;
use App\Events\Leads\LeadDispositionChanged;
use App\Events\Patient\AccountCreated;
use App\Events\Patient\ClaimLinkRequested;
use App\Events\Patient\EmailVerified;
use App\Events\Patient\PasswordChanged;
use App\Events\Patient\RecordClaimed;
use App\Events\Quiz\QuizCompleted;
use App\Filament\Support\IntegrationActionForms;
use App\Models\Lead;
use App\Models\Patient;
use App\Workflows\Actions\DispatchJobAction;
use App\Workflows\Actions\PushToIntegrationAction;
use App\Workflows\Actions\SendEmailAction;
use App\Workflows\Actions\SendSmsAction;
use App\Workflows\Actions\UpdateFieldAction;
use App\Workflows\Actions\WebhookAction;
use App\Workflows\WorkflowContext;
use App\Workflows\WorkflowDispatcher;
use App\Workflows\WorkflowRegistry;
use App\Workflows\WorkflowSubjectObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the workflow engine, and declares what THIS install exposes to it.
 *
 * THE SPLIT IS THE POINT. Everything in App\Workflows is generic and knows
 * nothing about leads, quizzes or any particular business. This file is the only
 * place a domain concept meets the engine — so another company deploying this
 * backend replaces the contents of `registerAtlasSubjects()` and
 * `registerAtlasEvents()` (or adds their own provider) and the engine, the admin
 * UI and the condition builder all follow, with no fork.
 *
 * The field lists below are ALLOW-LISTS, not documentation. They bound what a
 * condition may read and what an update-field action may write; anything absent
 * is invisible and unwritable. Adding a field here is a deliberate act.
 */
class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons: the registry so packages can add to it at boot, the
        // dispatcher because its loop guard is per-chain state and a fresh
        // instance per resolve would defeat it entirely.
        $this->app->singleton(WorkflowRegistry::class);
        $this->app->singleton(WorkflowDispatcher::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(WorkflowRegistry::class);

        $this->registerActions($registry);
        $this->registerAtlasSubjects($registry);
        $this->registerAtlasEvents($registry);
        $this->registerPortalSubjectsAndEvents($registry);

        $this->attachObservers($registry);
        $this->bridgeEvents($registry);
    }

    /**
     * Generic actions. Deliberately NOT 'push to Klaviyo' or 'send to GHL' —
     * those arrive as configured integration instances behind one
     * `push_to_integration` action, so the shipped enum never names a vendor.
     */
    private function registerActions(WorkflowRegistry $registry): void
    {
        $registry->registerAction(
            'update_field',
            UpdateFieldAction::class,
            'Update a field',
            'Set a field on the record that triggered this workflow. Moving a lead between dispositions is itself a trigger, so this is how stages chain together.',
        );

        $registry->registerAction(
            'webhook',
            WebhookAction::class,
            'Send a webhook',
            'POST the record to any URL. The escape hatch for a system with no first-class integration yet.',
        );

        // OFFERED ONLY WHEN THIS INSTALL HAS REGISTERED A JOB, and by default
        // none has. The allow-list starts empty on purpose — a job is arbitrary
        // code dispatched from an operator-editable row, so `registerJob()` is
        // the security boundary and nothing is on it until somebody deliberately
        // puts it there. Offering the action regardless gave an operator a step
        // whose only control was an empty dropdown.
        $registry->registerAction(
            'dispatch_job',
            DispatchJobAction::class,
            'Run a background job',
            'Dispatch one of this installation\'s registered jobs.',
            available: fn (): bool => $registry->jobOptions() !== [],
        );

        // ── Capability-routed, and therefore conditional ──────────────
        //
        // These three appear in the palette only while some enabled integration
        // provides what they need. That is the whole design: enabling a vendor
        // is what makes its actions available, and no vendor is named in any of
        // the keys, labels or handlers below.

        $registry->registerAction(
            'send_email',
            SendEmailAction::class,
            'Send an email',
            'Emails the person this workflow is about, through whichever integration you have enabled '
            .'for transactional email.',
            capability: IntegrationCapability::TransactionalEmail,
            configSchema: fn (): array => IntegrationActionForms::sendEmail(),
        );

        $registry->registerAction(
            'send_sms',
            SendSmsAction::class,
            'Send an SMS',
            'Texts the person this workflow is about. Only available once an SMS provider is configured.',
            capability: IntegrationCapability::Sms,
            configSchema: fn (): array => IntegrationActionForms::sendSms(),
        );

        $registry->registerAction(
            'push_to_integration',
            PushToIntegrationAction::class,
            'Send to an integration',
            'Push the record to a CRM or marketing platform you have configured, mapping the fields '
            .'you choose. Health fields are checked against that destination\'s permissions before '
            .'anything is sent.',
            capability: IntegrationCapability::Crm,
            configSchema: fn (): array => IntegrationActionForms::pushToIntegration(),
        );
    }

    private function registerAtlasSubjects(WorkflowRegistry $registry): void
    {
        // THE CLASSIFICATIONS ARE ATLAS'S, NOT THE ENGINE'S. What counts as health
        // data depends on what the install collects and why: `age` and `gender`
        // are ordinary demographics in a shop and clinical inputs here, because
        // this install uses them to gate which treatments may be recommended. So
        // they are declared where the domain is known, and the generic layer only
        // enforces what it is told.
        $registry->registerSubject('lead', Lead::class, 'Lead', [
            'status' => 'Disposition',
            'email' => ['label' => 'Email', 'class' => DataClassification::Sensitive],
            'phone' => ['label' => 'Phone', 'class' => DataClassification::Sensitive],
            'first_name' => ['label' => 'First name', 'class' => DataClassification::Sensitive],
            'last_name' => ['label' => 'Last name', 'class' => DataClassification::Sensitive],
            // Clinical here: both feed the eligibility gate that decides which
            // ingredients a person may be recommended.
            'age' => ['label' => 'Age', 'class' => DataClassification::Phi],
            'gender' => ['label' => 'Sex', 'class' => DataClassification::Phi],
            'country' => 'Country',
            'state' => 'State',
            'checkout_path' => 'Checkout path',
            'email_consent' => 'Email consent',
            'sms_consent' => 'SMS consent',
            'utm_source' => 'UTM source',
            'utm_medium' => 'UTM medium',
            'utm_campaign' => 'UTM campaign',
            'cart_subtotal' => 'Cart subtotal',
            // The quiz a lead answered. The ANSWERS are not registered here and
            // must not be — they are per-question, classified per question, and
            // reach the mapper through QuizAnswerFields rather than as one opaque
            // blob. Registering `quiz_answers` would classify the container and
            // let every answer inside it inherit that single verdict.
            'quiz_id' => 'Quiz',
        ]);
    }

    private function registerAtlasEvents(WorkflowRegistry $registry): void
    {
        $registry->registerEvent('lead.created', LeadCreated::class, 'Lead captured (any source)', 'lead');
        $registry->registerEvent('lead.disposition_changed', LeadDispositionChanged::class, 'Lead moved disposition', 'lead', changedField: 'status');
        $registry->registerEvent('quiz.completed', QuizCompleted::class, 'Quiz completed', 'lead');
    }

    /**
     * The patient account, and the moments in its life a workflow may follow.
     *
     * NOT in the Atlas methods above: the patient portal is part of the shipped
     * product, so an install replacing Atlas's lead and quiz registrations must
     * not lose these by doing so.
     *
     * `prx_patient_chart_id` is deliberately absent. A chart id is not clinical,
     * but it IS identifying, and this list is what a field mapper may send to a
     * third party. So are `password` and every token column, which is what
     * failing closed on an unregistered field protects.
     *
     * 🔴 None of these events carries the claim token, and none may. The link is
     * delivered by the system, not by a workflow — see ClaimLinkRequested.
     */
    private function registerPortalSubjectsAndEvents(WorkflowRegistry $registry): void
    {
        $registry->registerSubject('patient', Patient::class, 'Patient account', [
            'email' => ['label' => 'Email', 'class' => DataClassification::Sensitive],
            'first_name' => ['label' => 'First name', 'class' => DataClassification::Sensitive],
            'last_name' => ['label' => 'Last name', 'class' => DataClassification::Sensitive],
            'phone' => ['label' => 'Phone', 'class' => DataClassification::Sensitive],
            'email_verified_at' => 'Email verified at',
        ]);

        $registry->registerEvent('patient.claim_link_requested', ClaimLinkRequested::class, 'Patient was emailed a link to connect their record', 'patient');
        $registry->registerEvent('patient.record_claimed', RecordClaimed::class, 'Patient connected their record', 'patient');
        $registry->registerEvent('patient.email_verified', EmailVerified::class, 'Patient verified their email', 'patient');
        $registry->registerEvent('patient.account_created', AccountCreated::class, 'Patient created their account from an emailed link', 'patient');
        $registry->registerEvent('patient.password_changed', PasswordChanged::class, 'Patient reset their password', 'patient');
    }

    private function attachObservers(WorkflowRegistry $registry): void
    {
        foreach ($registry->subjects() as $definition) {
            $definition['model']::observe(WorkflowSubjectObserver::class);
        }
    }

    /**
     * Bridge domain events into the engine.
     *
     * Every registered event carries its subject on a `$lead`-style property; the
     * bridge finds the first model property rather than demanding every event in
     * the product adopt one interface. An event with no model still fires — its
     * conditions simply have nothing to read, which is correct for a trigger that
     * genuinely has no subject.
     */
    private function bridgeEvents(WorkflowRegistry $registry): void
    {
        foreach ($registry->events() as $key => $definition) {
            Event::listen($definition['event'], function (object $event) use ($key, $definition, $registry): void {
                $subject = $this->subjectOf($event, $definition, $registry);

                // WHICH FIELD `from`/`to` DESCRIBE IS DECLARED, NEVER GUESSED.
                // Assuming 'status' would make `_changed.status` fire on an event
                // about a price, in an install this codebase has never seen.
                $changedField = $definition['changed_field'] ?? null;

                $original = [];
                $changed = [];

                if ($changedField !== null && property_exists($event, 'from')) {
                    $original[$changedField] = $event->from;
                }

                if ($changedField !== null && property_exists($event, 'to')) {
                    $changed[] = $changedField;
                }

                $this->app->make(WorkflowDispatcher::class)->queue('event_fired', $key, new WorkflowContext(
                    triggerType: 'event_fired',
                    triggerTarget: $key,
                    subject: $subject,
                    subjectKey: $definition['subject'],
                    original: $original,
                    changed: $changed,
                    payload: $this->payloadOf($event),
                ));
            });
        }
    }

    /**
     * Find the model this event is about.
     *
     * Resolution order, narrowest first: the declared property, then the first
     * property whose TYPE matches the registered subject's model, and only then
     * the first model of any kind. The middle step is what stops an event
     * carrying two models — an order and a customer — from binding conditions to
     * whichever happened to be declared first, which would read null for every
     * allow-listed field and skip silently.
     */
    private function subjectOf(object $event, array $definition, WorkflowRegistry $registry): ?Model
    {
        $vars = get_object_vars($event);

        $property = $definition['subject_property'] ?? null;

        if ($property !== null && ($vars[$property] ?? null) instanceof Model) {
            return $vars[$property];
        }

        $expected = $definition['subject'] === null
            ? null
            : ($registry->subject($definition['subject'])['model'] ?? null);

        if ($expected !== null) {
            foreach ($vars as $value) {
                if ($value instanceof $expected) {
                    return $value;
                }
            }
        }

        foreach ($vars as $value) {
            if ($value instanceof Model) {
                return $value;
            }
        }

        return null;
    }

    /** Scalar properties of the event, readable in conditions as `_payload.*`. */
    private function payloadOf(object $event): array
    {
        return array_filter(
            get_object_vars($event),
            fn ($value): bool => is_scalar($value) || $value === null,
        );
    }
}
