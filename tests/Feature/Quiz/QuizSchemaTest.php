<?php

namespace Tests\Feature\Quiz;

use App\Cms\Support\VisibleWhen;
use App\Enums\CatalogStatus;
use App\Enums\Quiz\QuizQuestionKind;
use App\Models\Catalog\Package;
use App\Models\Catalog\Plan;
use App\Models\Catalog\Product;
use App\Models\Kb\HealthGoal;
use App\Models\Quiz\Quiz;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The quiz as data: what the walker is handed, and the branching vocabulary
 * it shares with the CMS.
 */
class QuizSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function quiz(): Quiz
    {
        return Quiz::create(['name' => 'T', 'slug' => 'test-quiz', 'is_active' => true, 'is_default' => true]);
    }

    private function step(Quiz $quiz, string $slug = 's1'): object
    {
        return $quiz->steps()->create(['slug' => $slug, 'name' => 'Step', 'position' => 1, 'is_active' => true]);
    }

    public function test_health_goals_resolve_from_the_goals_table_not_authored_options(): void
    {
        // The point of the reserved kind: a goal added here shows up in the
        // quiz with no edit to the quiz, and one withdrawn from intake stops
        // being offered without orphaning anything.
        HealthGoal::create(['name' => 'Weight', 'slug' => 'weight', 'prompt' => 'Lose weight', 'show_in_quiz' => true]);
        HealthGoal::create(['name' => 'Hidden', 'slug' => 'hidden', 'show_in_quiz' => false]);

        $quiz = $this->quiz();
        $this->step($quiz)->questions()->create([
            'slug' => 'health_goals', 'kind' => QuizQuestionKind::HealthGoals,
            'prompt' => 'Goals?', 'position' => 1, 'is_active' => true,
        ]);

        $options = $this->getJson('/api/v1/quiz')->assertOk()
            ->json('data.steps.0.questions.0.options');

        $this->assertSame(['weight'], array_column($options, 'value'));
        // prompt wins over name — the same rule HealthGoalResource applies.
        $this->assertSame('Lose weight', $options[0]['label']);
    }

    public function test_a_price_figure_is_computed_live_and_never_authored(): void
    {
        // The package's own price is explicit and sits INSIDE the plan span, so
        // the plans decide the range. It used to be whatever the factory rolled,
        // which passed only while the builder ignored it.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'tier' => 'protocol',
            'retail_price' => 250,
            'sale_price' => null,
        ]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'retail_price' => 100, 'sale_price' => null]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'retail_price' => 500, 'sale_price' => 400]);

        $quiz = $this->quiz();
        $question = $this->step($quiz)->questions()->create([
            'slug' => 'start', 'kind' => QuizQuestionKind::SingleSelect,
            'prompt' => 'Where?', 'position' => 1, 'is_active' => true,
        ]);
        $question->options()->create(['value' => 'protocol', 'label' => 'Protocol', 'price_source' => 'packages:protocol', 'position' => 1]);
        $question->options()->create(['value' => 'advise', 'label' => 'Advise me', 'position' => 2]);

        $options = $this->getJson('/api/v1/quiz')->assertOk()
            ->json('data.steps.0.questions.0.options');

        // The CHEAPEST WAY IN across the package's own price and its monthly
        // plans — 100, not the 250 own price and not the 400 upper plan. This
        // used to be a min/max RANGE whose two ends were in different billing
        // units; see QuizSchemaBuilder::priceFrom for why that had to go.
        //
        // assertEquals, not assertSame: JSON renders 100.0 as 100, so the
        // decoded value is an int.
        $this->assertEquals(['amount' => 100, 'currency' => 'USD'], $options[0]['price_from']);
        // No source means no figure — not a zero, which would render as "$0"
        // and read as free.
        $this->assertNull($options[1]['price_from']);
    }

    public function test_a_package_on_sale_below_its_plans_lowers_the_quiz_figure(): void
    {
        // A package buyable on its own for less than any plan must move the
        // figure a visitor is quoted, or the quiz advertises a price higher
        // than the cheapest thing on the page it sends them to. The quiz no
        // longer computes this itself — it runs the shared card-price
        // expression, so this and the listings cannot disagree — but the case
        // is kept because it is the one an implementation reading plans ALONE
        // gets wrong, and that is what the quiz used to do.
        //
        // Original wording, for the record: the quiz computes its range LIVE
        // rather than reading PackageResource, so the two implementations have
        // to be fixed in lockstep — or the quiz advertises a price higher than the
        // cheapest thing on the page it sends them to.
        $package = Package::factory()->create([
            'status' => CatalogStatus::Published,
            'tier' => 'protocol',
            'retail_price' => 500,
            'sale_price' => 60,
        ]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'retail_price' => 100, 'sale_price' => null]);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'retail_price' => 400, 'sale_price' => null]);

        $quiz = $this->quiz();
        $question = $this->step($quiz)->questions()->create([
            'slug' => 'start', 'kind' => QuizQuestionKind::SingleSelect,
            'prompt' => 'Where?', 'position' => 1, 'is_active' => true,
        ]);
        $question->options()->create(['value' => 'protocol', 'label' => 'Protocol', 'price_source' => 'packages:protocol', 'position' => 1]);

        $options = $this->getJson('/api/v1/quiz')->assertOk()
            ->json('data.steps.0.questions.0.options');

        $this->assertEquals(['amount' => 60, 'currency' => 'USD'], $options[0]['price_from']);
    }

    /**
     * THE `products` BRANCH NEEDS ITS OWN TEST, because the two branches differ
     * only by which model they name.
     *
     * A `Package`-for-`Product` slip in `QuizSchemaBuilder::priceFrom()` would
     * still return a plausible number — the other branch's — and every other
     * test in this file exercises packages, so nothing would notice. Asserting
     * a product-only figure that no package could produce is what separates
     * them.
     *
     * This branch is also the one whose live behaviour changed most: it used to
     * return NULL, because the old implementation queried plans alone and a
     * product's own price could not appear in the figure at all.
     */
    public function test_the_products_source_prices_products_and_not_packages(): void
    {
        $product = Product::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 149.00,
            'sale_price' => null,
        ]);

        // A package far cheaper than the product: if the branch resolved to
        // packages, the figure would be 12 rather than 149.
        Package::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 12.00,
            'sale_price' => null,
        ]);

        $quiz = $this->quiz();
        $question = $this->step($quiz)->questions()->create([
            'slug' => 'start', 'kind' => QuizQuestionKind::SingleSelect,
            'prompt' => 'Where?', 'position' => 1, 'is_active' => true,
        ]);
        $question->options()->create(['value' => 'one', 'label' => 'One peptide', 'price_source' => 'products', 'position' => 1]);

        $options = $this->getJson('/api/v1/quiz')->assertOk()
            ->json('data.steps.0.questions.0.options');

        $this->assertEquals(['amount' => 149, 'currency' => 'USD'], $options[0]['price_from']);
    }

    public function test_a_tier_matching_no_package_yields_no_figure_rather_than_every_package(): void
    {
        $package = Package::factory()->create(['status' => CatalogStatus::Published, 'tier' => 'protocol']);
        Plan::factory()->create(['package_id' => $package->id, 'status' => CatalogStatus::Published, 'retail_price' => 100]);

        $quiz = $this->quiz();
        $question = $this->step($quiz)->questions()->create([
            'slug' => 'start', 'kind' => QuizQuestionKind::SingleSelect,
            'prompt' => 'Where?', 'position' => 1, 'is_active' => true,
        ]);
        $question->options()->create(['value' => 'stack', 'label' => 'Stack', 'price_source' => 'packages:stack', 'position' => 1]);

        $this->assertNull(
            $this->getJson('/api/v1/quiz')->json('data.steps.0.questions.0.options.0.price_from')
        );
    }

    public function test_no_configured_quiz_is_a_404_not_an_empty_schema(): void
    {
        // An empty schema renders a wizard with no questions and a working
        // Continue button, which looks broken rather than absent.
        $this->getJson('/api/v1/quiz')->assertStatus(404);
    }

    public function test_the_answer_key_is_unique_across_the_quiz_not_the_step(): void
    {
        $quiz = $this->quiz();
        $this->step($quiz, 's1')->questions()->create([
            'slug' => 'dupe', 'kind' => QuizQuestionKind::Text, 'prompt' => 'A', 'position' => 1, 'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        $this->step($quiz, 's2')->questions()->create([
            'slug' => 'dupe', 'kind' => QuizQuestionKind::Text, 'prompt' => 'B', 'position' => 1, 'is_active' => true,
        ]);
    }

    public function test_only_one_quiz_can_be_default(): void
    {
        $first = $this->quiz();
        $second = Quiz::create(['name' => 'Second', 'slug' => 'second', 'is_active' => true, 'is_default' => true]);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame($second->id, Quiz::resolveDefault()->id);
    }

    /**
     * `contains` is the operator the quiz added to the CMS's condition
     * vocabulary. It is membership, not substring — branching on
     * `health_goals: ["a","b"]` cannot be expressed with equals, and casting
     * an array to a string to compare it would fatal.
     */
    public function test_visible_when_contains_matches_membership_in_a_multi_answer(): void
    {
        $answers = ['health_goals' => ['weight-management', 'sleep'], 'sex' => 'female'];
        $get = fn (string $field): mixed => $answers[$field] ?? null;

        $this->assertTrue(VisibleWhen::passes([['field' => 'health_goals', 'operator' => 'contains', 'value' => 'sleep']], $get));
        $this->assertFalse(VisibleWhen::passes([['field' => 'health_goals', 'operator' => 'contains', 'value' => 'libido']], $get));
        $this->assertTrue(VisibleWhen::passes([['field' => 'health_goals', 'operator' => 'not_contains', 'value' => 'libido']], $get));

        // Degrades to equality on a scalar, so a condition survives a question
        // being changed from multi- to single-select.
        $this->assertTrue(VisibleWhen::passes([['field' => 'sex', 'operator' => 'contains', 'value' => 'female']], $get));

        // An unanswered question matches nothing rather than erroring.
        $this->assertFalse(VisibleWhen::passes([['field' => 'missing', 'operator' => 'contains', 'value' => 'x']], $get));
    }

    public function test_the_existing_equals_operators_are_unchanged(): void
    {
        // Regression guard: VisibleWhen is shared with the CMS's flexible
        // section types, which author equals/not_equals conditions today.
        $get = fn (string $f): mixed => ['mode' => 'manual', 'count' => 4][$f] ?? null;

        $this->assertTrue(VisibleWhen::passes([['field' => 'mode', 'operator' => 'equals', 'value' => 'manual']], $get));
        $this->assertFalse(VisibleWhen::passes([['field' => 'mode', 'operator' => 'equals', 'value' => 'featured']], $get));
        $this->assertTrue(VisibleWhen::passes([['field' => 'count', 'value' => '4']], $get));
        $this->assertTrue(VisibleWhen::passes([['field' => 'mode', 'operator' => 'not_equals', 'value' => 'featured']], $get));
    }
}
