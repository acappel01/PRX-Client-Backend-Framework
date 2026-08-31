<?php

namespace Tests\Feature\Leads;

use App\Enums\BillingPeriod;
use App\Enums\Catalog\SexEligibility;
use App\Enums\CatalogStatus;
use App\Enums\Quiz\QuizQuestionKind;
use App\Models\Catalog\Ingredient;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Kb\HealthGoal;
use App\Models\Lead;
use App\Models\Quiz\Quiz;
use App\Models\Quiz\QuizQuestion;
use App\Models\Quiz\QuizStep;
use App\Settings\CommunicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/v1/leads/{uuid}/plan — the saved report.
 *
 * Two of these pin properties that are invisible when the endpoint "works":
 * that reserved answers are resolved by question KIND rather than by slug, and
 * that the eligibility gate runs on the quiz's answers rather than on the lead
 * columns. Both failure modes are silent and permissive — they do not throw,
 * they show a restricted visitor the full shelf — so only a test that makes the
 * slug wrong, and one that leaves the columns empty, can tell the rule from its
 * absence.
 */
class LeadPlanEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function goal(string $slug, array $ingredients = [], bool $active = true): HealthGoal
    {
        $goal = HealthGoal::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'is_active' => $active,
        ]);

        foreach ($ingredients as $ingredient) {
            $goal->ingredients()->attach($ingredient->id, ['relevance_weight' => 50]);
        }

        return $goal;
    }

    /**
     * A quiz carrying the three reserved questions under the slugs given, so a
     * test can choose slugs that do NOT match the kind names.
     *
     * @param  array<string, string>  $slugs  kind value => question slug
     */
    private function quiz(array $slugs): Quiz
    {
        $quiz = Quiz::create(['name' => 'Intake', 'slug' => 'intake', 'is_active' => true]);
        $step = QuizStep::create([
            'quiz_id' => $quiz->id,
            'slug' => 'step-1',
            'name' => 'Step 1',
            'position' => 1,
            'is_active' => true,
        ]);

        $position = 1;

        foreach ($slugs as $kind => $slug) {
            QuizQuestion::create([
                'quiz_step_id' => $step->id,
                'slug' => $slug,
                'kind' => $kind,
                'prompt' => 'Q',
                'position' => $position++,
                'is_active' => true,
            ]);
        }

        return $quiz->refresh();
    }

    /**
     * A quiz lead with NO identifying columns set, so anything the eligibility
     * gate does must have come from `quiz_answers`.
     */
    private function quizLead(Quiz $quiz, array $answers): Lead
    {
        return Lead::factory()->create([
            'quiz_id' => $quiz->id,
            'quiz_answers' => $answers,
            'quiz_completed_at' => now(),
            'gender' => null,
            'date_of_birth' => null,
            'age' => null,
        ]);
    }

    public function test_it_resolves_a_plan_from_the_leads_stored_answers(): void
    {
        $unisex = Ingredient::factory()->create(['sex_eligibility' => SexEligibility::Any]);
        $product = Product::factory()->create();
        $product->ingredients()->attach($unisex->id);
        $this->goal('weight', [$unisex]);

        $quiz = $this->quiz([
            QuizQuestionKind::HealthGoals->value => 'health_goals',
            QuizQuestionKind::Sex->value => 'sex',
            QuizQuestionKind::Age->value => 'age',
        ]);

        $lead = $this->quizLead($quiz, [
            'health_goals' => ['weight'],
            'sex' => 'female',
            'age' => 45,
        ]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/plan")
            ->assertOk()
            ->assertJsonPath('meta.goal_count', 1)
            ->assertJsonPath('meta.filtered', true)
            ->assertJsonPath('data.0.goal.slug', 'weight')
            ->assertJsonPath('data.0.outcome', 'matched')
            ->assertJsonPath('data.0.products.0.slug', $product->slug);
    }

    /**
     * THE SLUG IS OPERATOR-EDITABLE; THE KIND IS NOT.
     *
     * Every slug here is deliberately unlike the kind's name. An implementation
     * that reads `$answers['health_goals']` — the shape the live data happens to
     * have — returns no goals and this fails. Nothing else in the suite would
     * notice, because renaming a question in the admin breaks no constraint.
     */
    public function test_it_resolves_reserved_answers_by_kind_not_by_slug(): void
    {
        $male = Ingredient::factory()->create(['sex_eligibility' => SexEligibility::Male]);
        $product = Product::factory()->create();
        $product->ingredients()->attach($male->id);
        $this->goal('hormones', [$male]);

        $quiz = $this->quiz([
            QuizQuestionKind::HealthGoals->value => 'what_brings_you_here',
            QuizQuestionKind::Sex->value => 'about_you_1',
            QuizQuestionKind::Age->value => 'about_you_2',
        ]);

        $lead = $this->quizLead($quiz, [
            'what_brings_you_here' => ['hormones'],
            'about_you_1' => 'female',
            'about_you_2' => 45,
        ]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/plan")
            ->assertOk()
            // The goal resolved at all: the slug lookup found it.
            ->assertJsonPath('meta.goal_count', 1)
            // And sex/age resolved too, so the gate ran and excluded the
            // male-only ingredient rather than passing it through.
            ->assertJsonPath('meta.filtered', true)
            ->assertJsonPath('data.0.outcome', 'restricted')
            ->assertJsonPath('data.0.products', []);
    }

    /**
     * THE SAFETY TEST. A marketing-quiz lead has null `gender` and null `age`
     * columns — those are populated by the clinical intake at checkout, which
     * this visitor has not reached. Reading the columns alone yields an EMPTY
     * profile, and an empty profile is permissive: the gate does not run, and a
     * female visitor is shown a male-only protocol with no error anywhere.
     *
     * So this asserts `restricted` on a lead whose columns are explicitly null.
     * Delete the quiz-answer half of QuizProfile and the outcome flips to
     * `matched` — which is the bug, stated as an assertion.
     */
    public function test_the_gate_runs_on_quiz_answers_when_the_lead_columns_are_empty(): void
    {
        $male = Ingredient::factory()->create(['sex_eligibility' => SexEligibility::Male]);
        $product = Product::factory()->create();
        $product->ingredients()->attach($male->id);
        $this->goal('hormones', [$male]);

        $quiz = $this->quiz([
            QuizQuestionKind::HealthGoals->value => 'health_goals',
            QuizQuestionKind::Sex->value => 'sex',
            QuizQuestionKind::Age->value => 'age',
        ]);

        $lead = $this->quizLead($quiz, [
            'health_goals' => ['hormones'],
            'sex' => 'female',
            'age' => 45,
        ]);

        $this->assertNull($lead->gender, 'guard: the column must be empty for this test to mean anything');
        $this->assertNull($lead->effectiveAge(), 'guard: the column must be empty for this test to mean anything');

        $this->getJson("/api/v1/leads/{$lead->uuid}/plan")
            ->assertOk()
            ->assertJsonPath('meta.filtered', true)
            ->assertJsonPath('data.0.outcome', 'restricted');
    }

    /** The lead columns still fill a gap the quiz left — they are the fallback. */
    public function test_it_falls_back_to_the_lead_columns_when_the_quiz_did_not_ask(): void
    {
        $male = Ingredient::factory()->create(['sex_eligibility' => SexEligibility::Male]);
        $product = Product::factory()->create();
        $product->ingredients()->attach($male->id);
        $this->goal('hormones', [$male]);

        // A quiz with no sex question at all.
        $quiz = $this->quiz([QuizQuestionKind::HealthGoals->value => 'health_goals']);

        $lead = Lead::factory()->create([
            'quiz_id' => $quiz->id,
            'quiz_answers' => ['health_goals' => ['hormones']],
            'quiz_completed_at' => now(),
            'gender' => 'female',
            'date_of_birth' => null,
            'age' => 45,
        ]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/plan")
            ->assertOk()
            ->assertJsonPath('meta.filtered', true)
            ->assertJsonPath('data.0.outcome', 'restricted');
    }

    public function test_a_goal_nobody_has_mapped_reads_as_unmapped(): void
    {
        $this->goal('brand-new');

        $quiz = $this->quiz([
            QuizQuestionKind::HealthGoals->value => 'health_goals',
            QuizQuestionKind::Sex->value => 'sex',
        ]);

        $lead = $this->quizLead($quiz, ['health_goals' => ['brand-new'], 'sex' => 'female']);

        $this->getJson("/api/v1/leads/{$lead->uuid}/plan")
            ->assertOk()
            ->assertJsonPath('data.0.outcome', 'unmapped')
            ->assertJsonPath('data.0.excluded_count', 0);
    }

    /**
     * A goal withdrawn since the quiz was taken must stop being recommended.
     * The answers still name it; `active()` is what decides, and it is checked
     * on every read precisely because the plan is recomputed rather than saved.
     */
    public function test_a_goal_withdrawn_since_the_quiz_is_dropped(): void
    {
        $unisex = Ingredient::factory()->create(['sex_eligibility' => SexEligibility::Any]);
        $product = Product::factory()->create();
        $product->ingredients()->attach($unisex->id);
        $this->goal('weight', [$unisex], active: false);

        $quiz = $this->quiz([QuizQuestionKind::HealthGoals->value => 'health_goals']);
        $lead = $this->quizLead($quiz, ['health_goals' => ['weight']]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/plan")
            ->assertOk()
            ->assertJsonPath('meta.goal_count', 0)
            ->assertJsonPath('data', []);
    }

    /**
     * A checkout lead never took the quiz. That is a normal shape, not a broken
     * one — and `quiz_completed_at` is what lets the page tell it apart from a
     * quiz that matched nothing, which needs completely different words.
     */
    public function test_a_lead_that_never_took_the_quiz_returns_no_goals(): void
    {
        $lead = Lead::factory()->create(['quiz_id' => null, 'quiz_answers' => null]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/plan")
            ->assertOk()
            ->assertJsonPath('meta.goal_count', 0)
            ->assertJsonPath('meta.quiz_completed_at', null)
            ->assertJsonPath('data', []);
    }

    /**
     * A package must carry `price_from` here, because that is the one figure a
     * card is allowed to lead with.
     *
     * PackageResource emits it only when the `plans` relation is loaded and
     * omits it silently otherwise — so dropping the eager load does not break a
     * request, it just leaves this surface computing its figure from a
     * different input than every catalogue card. The same item, two numbers,
     * which is the defect the shared card price rule was written to end.
     *
     * ASSERTING THE KEY IS POPULATED, RATHER THAN MERELY PRESENT, IS THE POINT:
     * `null` is exactly what the missing load produces, and the frontend then
     * falls back to `price.effective` — the package's own $399 — while every
     * catalogue card shows $279.99. Same item, two numbers, which is the defect
     * the shared card price rule exists to have ended.
     */
    public function test_a_package_carries_the_price_from_figure_cards_lead_with(): void
    {
        $unisex = Ingredient::factory()->create(['sex_eligibility' => SexEligibility::Any]);
        $product = Product::factory()->create();
        $product->ingredients()->attach($unisex->id);
        $goal = $this->goal('weight', [$unisex]);

        $package = Package::factory()->create(['retail_price' => 399]);
        $package->products()->attach($product->id);
        $package->healthGoals()->attach($goal->id);
        Plan::factory()->create([
            'package_id' => $package->id,
            'retail_price' => 279.99,
            'billing_period' => BillingPeriod::Monthly,
            'status' => CatalogStatus::Published,
        ]);

        $quiz = $this->quiz([QuizQuestionKind::HealthGoals->value => 'health_goals']);
        $lead = $this->quizLead($quiz, ['health_goals' => ['weight']]);

        $response = $this->getJson("/api/v1/leads/{$lead->uuid}/plan")->assertOk();

        $this->assertNotNull(
            $response->json('data.0.packages.0.price_from.amount'),
            'price_from is null — the plans relation was not eager loaded, so this surface '
            .'will disagree with every catalogue card about the same package.',
        );

        // And it is the cheapest way in — the $279.99 monthly plan, not the
        // package's own $399. "As low as" is a floor, and the report links a
        // stack to its own page precisely because that floor names a plan the
        // visitor has not chosen yet.
        $response->assertJsonPath('data.0.packages.0.price_from.amount', 279.99);

        // THE FIGURE MUST NAME ITS SOURCE. A stack is not added from here — it
        // links out — but the id is what a plan picker would open on, and it is
        // the only thing distinguishing "this figure is a plan" from "this
        // figure is the item itself", which decides whether a rebill exists.
        $response->assertJsonPath(
            'data.0.packages.0.price_from.plan_id',
            $package->plans()->first()->id,
        );
    }

    /**
     * THE PLAN PICKER'S INPUT, PINNED.
     *
     * The report offers a stack's terms in a modal rather than sending the
     * visitor to the stack's own page, and it can only do that because the
     * plans are already in THIS payload — the frontend never fetches content
     * from the browser. Nothing declares that dependency today: `PackageResource`
     * emits `plans` on a bare `whenLoaded`, so the key is present because
     * `ProtocolPresenter` happens to eager load it, and a load removed for an
     * unrelated reason would empty every picker on the report with no error
     * anywhere — the card would fall back to its link-out and the feature would
     * simply stop existing.
     *
     * ASSERTING THE ARRAY IS POPULATED, NOT MERELY PRESENT, IS THE POINT.
     * `whenLoaded` omits the key when the relation is not loaded, so a `has`
     * assertion passes on the broken shape as readily as on the working one.
     *
     * AND THE PICKER MUST BE ABLE TO OPEN ON `price_from.plan_id`. That id is
     * the plan the card's "as low as" figure came from, so it is what the modal
     * preselects; if it could name a plan outside this array the modal would
     * open on nothing while the card quoted a number. It cannot, because
     * `catalogPriceFrom()` chooses from the same loaded collection this key
     * serializes — this asserts the invariant rather than trusting the shared
     * origin to stay shared.
     */
    public function test_a_package_carries_the_plans_the_report_offers_a_term_from(): void
    {
        $unisex = Ingredient::factory()->create(['sex_eligibility' => SexEligibility::Any]);
        $product = Product::factory()->create();
        $product->ingredients()->attach($unisex->id);
        $goal = $this->goal('weight', [$unisex]);

        $package = Package::factory()->create(['retail_price' => 399]);
        $package->products()->attach($product->id);
        $package->healthGoals()->attach($goal->id);

        $monthly = Plan::factory()->create([
            'package_id' => $package->id,
            'retail_price' => 279.99,
            'billing_period' => BillingPeriod::Monthly,
            'status' => CatalogStatus::Published,
            'position' => 1,
        ]);
        $quarterly = Plan::factory()->create([
            'package_id' => $package->id,
            'retail_price' => 799,
            'billing_period' => BillingPeriod::Quarterly,
            'term_months' => 3,
            'status' => CatalogStatus::Published,
            'position' => 2,
        ]);

        // A withdrawn plan must not reach a picker. The presenter constrains the
        // load to published, and the modal renders whatever it is handed.
        Plan::factory()->create([
            'package_id' => $package->id,
            'status' => CatalogStatus::Draft,
            'position' => 3,
        ]);

        $quiz = $this->quiz([QuizQuestionKind::HealthGoals->value => 'health_goals']);
        $lead = $this->quizLead($quiz, ['health_goals' => ['weight']]);

        $response = $this->getJson("/api/v1/leads/{$lead->uuid}/plan")->assertOk();

        $plans = $response->json('data.0.packages.0.plans');

        $this->assertIsArray($plans, 'the plans key is absent — the relation was not eager loaded, '
            .'so the report can only link a stack out and the plan picker has nothing to offer.');
        $this->assertCount(2, $plans, 'the picker must be offered the published plans and only those.');
        $this->assertEqualsCanonicalizing(
            [$monthly->id, $quarterly->id],
            array_column($plans, 'id'),
        );

        // The fields the picker actually renders. A plan with no price is a
        // radio option a visitor cannot evaluate.
        $this->assertNotNull($plans[0]['name']);
        $this->assertNotNull($plans[0]['price']['effective']);

        // The modal opens on this one, so it has to be in the list above.
        $planId = $response->json('data.0.packages.0.price_from.plan_id');

        $this->assertSame($monthly->id, $planId);
        $this->assertContains($planId, array_column($plans, 'id'));
    }

    /**
     * THE ASYMMETRY THE REPORT HAS TO GUARD AGAINST, STATED.
     *
     * `ProductResource` route-gates `plans` to the product SHOW route, so a
     * product on this payload carries its `price_from` figure but not the plans
     * behind it. Today that costs nothing: no product quotes a plan, so every
     * product on the report adds in one tap and the picker is never asked for.
     * The day one does, the report would otherwise open a modal with no terms
     * in it — which is why the card asks whether the plans are actually THERE
     * rather than assuming the kind implies them.
     *
     * This is a record of the current contract, not a preference. Widening the
     * gate to this route is a deliberate decision and should fail here first.
     */
    public function test_a_product_carries_its_figure_but_not_its_plans_here(): void
    {
        $unisex = Ingredient::factory()->create(['sex_eligibility' => SexEligibility::Any]);
        $product = Product::factory()->create(['retail_price' => 249]);
        $product->ingredients()->attach($unisex->id);
        $this->goal('weight', [$unisex]);

        Plan::factory()->create([
            'product_id' => $product->id,
            'retail_price' => 199,
            'billing_period' => BillingPeriod::Monthly,
            'status' => CatalogStatus::Published,
        ]);

        $quiz = $this->quiz([QuizQuestionKind::HealthGoals->value => 'health_goals']);
        $lead = $this->quizLead($quiz, ['health_goals' => ['weight']]);

        $response = $this->getJson("/api/v1/leads/{$lead->uuid}/plan")->assertOk();

        // The figure is there and it names its source, so the card knows the
        // quoted number is a plan's rate rather than the product's own price.
        $response->assertJsonPath('data.0.products.0.price_from.amount', 199);
        $this->assertNotNull($response->json('data.0.products.0.price_from.plan_id'));

        // The terms behind it are not.
        $this->assertArrayNotHasKey('plans', $response->json('data.0.products.0'));
    }

    /**
     * The results-page words come from the quiz, so an operator can change what
     * a visitor who matched nothing is told without a deploy. An unauthored
     * field stays null rather than acquiring a default in a component.
     */
    public function test_it_serves_the_operators_result_copy_and_leaves_unauthored_fields_null(): void
    {
        $quiz = $this->quiz([QuizQuestionKind::HealthGoals->value => 'health_goals']);
        $quiz->update([
            'result_heading' => 'Your protocol',
            'result_unmapped_body' => '<p>We are still building this one out.</p>',
        ]);

        $lead = $this->quizLead($quiz, ['health_goals' => []]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/plan")
            ->assertOk()
            ->assertJsonPath('meta.copy.heading', 'Your protocol')
            ->assertJsonPath('meta.copy.unmapped', '<p>We are still building this one out.</p>')
            ->assertJsonPath('meta.copy.restricted', null)
            ->assertJsonPath('meta.copy.empty', null);
    }

    /**
     * `email_pending` must consult whether sending is POSSIBLE, not merely
     * whether the visitor consented. With email switched off — the live state
     * on this install — a page told only about the tick would promise a
     * delivery that will never happen, on the screen a visitor reads most
     * carefully.
     */
    public function test_email_pending_is_false_when_sending_is_switched_off(): void
    {
        $settings = app(CommunicationSettings::class);
        $settings->email_enabled = false;
        $settings->save();

        $lead = Lead::factory()->create([
            'email_consent' => true,
            'plan_sent_at' => null,
        ]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/plan")
            ->assertOk()
            ->assertJsonPath('meta.email_pending', false);
    }

    /**
     * The "true" case must be a lead a send is ACTUALLY COMING FOR.
     *
     * The first version of this test used a bare factory lead, which carries no
     * quiz — and the only thing that sends this email is the SendPlanEmail
     * listener on QuizCompleted, which fires solely for a lead submitted WITH a
     * quiz. So the green assertion was itself the bug: it pinned "we will email
     * you shortly" onto exactly the visitor nothing would ever email. A test
     * whose passing case is the defect cannot fail on it.
     */
    public function test_email_pending_is_true_only_when_a_send_is_genuinely_coming(): void
    {
        $settings = app(CommunicationSettings::class);
        $settings->email_enabled = true;
        $settings->save();
        config()->set('mail.default', 'smtp');

        $quiz = $this->quiz([QuizQuestionKind::HealthGoals->value => 'health_goals']);

        $expecting = $this->quizLead($quiz, ['health_goals' => []]);
        $expecting->forceFill(['email_consent' => true, 'plan_sent_at' => null])->save();

        $this->getJson("/api/v1/leads/{$expecting->uuid}/plan")
            ->assertJsonPath('meta.email_pending', true);

        // No consent means no send, and the page must say nothing about email.
        $declined = $this->quizLead($quiz, ['health_goals' => []]);
        $declined->forceFill(['email_consent' => false])->save();

        $this->getJson("/api/v1/leads/{$declined->uuid}/plan")
            ->assertJsonPath('meta.email_pending', false);

        // Already delivered is a different sentence, not a pending one.
        $sent = $this->quizLead($quiz, ['health_goals' => []]);
        $sent->forceFill(['email_consent' => true, 'plan_sent_at' => now()])->save();

        $this->getJson("/api/v1/leads/{$sent->uuid}/plan")
            ->assertJsonPath('meta.email_pending', false);
    }

    /**
     * A CHECKOUT LEAD IS PROMISED NOTHING. It reaches this page through a
     * recovery link — a case this endpoint deliberately supports — but no
     * QuizCompleted was ever dispatched for it, so no plan email is queued,
     * scheduled, or possible. Consent alone must not turn the promise on.
     */
    public function test_email_pending_is_false_for_a_lead_that_never_took_the_quiz(): void
    {
        $settings = app(CommunicationSettings::class);
        $settings->email_enabled = true;
        $settings->save();
        config()->set('mail.default', 'smtp');

        $lead = Lead::factory()->create([
            'quiz_id' => null,
            'quiz_answers' => null,
            'quiz_completed_at' => null,
            'email_consent' => true,
            'plan_sent_at' => null,
        ]);

        $this->getJson("/api/v1/leads/{$lead->uuid}/plan")
            ->assertOk()
            ->assertJsonPath('meta.email_pending', false);
    }

    public function test_an_unknown_uuid_is_a_404_not_a_500(): void
    {
        $this->getJson('/api/v1/leads/'.Str::uuid()->toString().'/plan')
            ->assertNotFound();
    }
}
