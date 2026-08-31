<?php

namespace App\Services\Quiz;

use App\Enums\CatalogStatus;
use App\Enums\Quiz\QuizQuestionKind;
use App\Models\Catalog\Package;
use App\Models\Catalog\Product;
use App\Models\Kb\HealthGoal;
use App\Models\Quiz\Quiz;
use App\Models\Quiz\QuizQuestion;
use App\Models\Quiz\QuizQuestionOption;
use App\Models\Quiz\QuizStep;
use Illuminate\Support\Facades\Storage;

/**
 * Turns an authored quiz into the payload the frontend walker renders.
 *
 * Two jobs the frontend must not do for itself:
 *
 * 1. **Resolve reserved kinds.** `health_goals` reads the goals table rather
 *    than authored options, so adding or withdrawing a goal changes the quiz
 *    with no edit here. A frontend fetching goals separately would work, but
 *    it would have to know WHICH questions need that second call — the walker
 *    stays dumb precisely because everything arrives resolved.
 *
 * 2. **Compute price ranges.** An option may say where its price comes from
 *    (`products`, `packages:protocol`, `packages:stack`); the range is
 *    computed here from live plan prices and never stored. A price authored
 *    into an option goes stale the moment a plan changes, silently, next to a
 *    buying decision.
 *
 * `visible_when` is passed through UNRESOLVED, deliberately: the conditions
 * describe branching over answers that do not exist yet, so they can only be
 * evaluated in the browser as the visitor answers — and again on the server at
 * submit, against what they actually sent. Two evaluations of one rule set,
 * which is why the rule format is shared rather than reimplemented.
 */
class QuizSchemaBuilder
{
    public function build(Quiz $quiz): array
    {
        $quiz->load([
            'steps' => fn ($q) => $q->active()->orderBy('position'),
            'steps.questions' => fn ($q) => $q->active()->orderBy('position'),
            'steps.questions.options' => fn ($q) => $q->active()->orderBy('position'),
        ]);

        $steps = $quiz->steps->map(fn (QuizStep $step): array => [
            'slug' => $step->slug,
            'name' => $step->name,
            'heading' => $step->heading,
            'description' => $step->description,
            'visible_when' => $step->visible_when ?? [],
            'questions' => $step->questions->map(fn (QuizQuestion $q): array => $this->question($q))->all(),
        ])->all();

        return [
            'slug' => $quiz->slug,
            'name' => $quiz->name,
            'steps' => $steps,
            // A flat list so the walker can check completeness without
            // re-walking the tree, mirroring the provider's clinical intake
            // schema (`required_slugs`) rather than inventing a second shape.
            'required_slugs' => collect($steps)
                ->flatMap(fn (array $s): array => $s['questions'])
                ->filter(fn (array $q): bool => $q['is_required'])
                ->pluck('slug')
                ->values()
                ->all(),
        ];
    }

    private function question(QuizQuestion $question): array
    {
        return [
            'slug' => $question->slug,
            'kind' => $question->kind->value,
            'prompt' => $question->prompt,
            'help' => $question->help,
            'is_required' => $question->is_required,
            'visible_when' => $question->visible_when ?? [],
            'config' => $question->config ?? [],
            'options' => $this->options($question),
        ];
    }

    /**
     * Options as the visitor will see them — authored for the select kinds,
     * resolved from their source for the reserved ones.
     */
    private function options(QuizQuestion $question): array
    {
        if ($question->kind === QuizQuestionKind::HealthGoals) {
            return HealthGoal::query()
                ->forQuiz()
                ->orderBy('position')
                ->orderBy('name')
                ->get()
                ->map(fn (HealthGoal $goal): array => [
                    'value' => $goal->slug,
                    // The outcome-framed line, falling back to the label —
                    // the same rule HealthGoalResource applies, so the quiz
                    // and any other consumer read a goal identically.
                    'label' => $goal->prompt ?: $goal->name,
                    'description' => $goal->description,
                    'icon' => $goal->icon,
                    'is_exclusive' => false,
                    'price_from' => null,
                    'image_url' => $goal->image_path ? Storage::disk('public')->url($goal->image_path) : null,
                ])
                ->all();
        }

        if (! $question->kind->usesAuthoredOptions()) {
            return [];
        }

        return $question->options
            ->map(fn (QuizQuestionOption $option): array => [
                'value' => $option->value,
                'label' => $option->label,
                'description' => $option->description,
                'icon' => $option->icon,
                'is_exclusive' => $option->is_exclusive,
                'price_from' => $this->priceFrom($option->price_source),
                'image_url' => null,
            ])
            ->all();
    }

    /**
     * The cheapest way into whatever the option points at, as an "as low as"
     * figure — the same rule and the same expression every card on the site
     * uses.
     *
     * THIS USED TO BE A LIVE min/max RANGE AND IT WAS THE LAST SURFACE SHOWING
     * ONE. The two ends came from different billing units: on live data the
     * "full stack" option served `{from: 725, to: 6050}`, where 725 is an entry
     * price and 6050 a six-month prepay TOTAL, so the card read
     * "$725 – $6,050" and invited a visitor to read the upper number as what a
     * stack costs. That is the mixed-unit defect every other card surface was
     * fixed for; the quiz kept it because it computed its own range instead of
     * asking the shared rule.
     *
     * It also queried `Plan` ALONE, so an item's own price — the thing most of
     * this catalog is actually bought at — could not appear in the figure at
     * all. Both problems have the same cause and the same fix: ask the one
     * expression that already answers "what does a card quote".
     *
     * AN OPTION POINTS AT A SET, NOT AN ITEM, and "as low as" is still the
     * honest reading of that: the cheapest way into any product, or into any
     * package, or into any package of one tier. A range across a set would need
     * both ends in the same unit to mean anything, and they are not.
     *
     * `MIN()` over the shared card-price expression, so the quiz cannot drift
     * from the listings — the parity contract in `CatalogPriceParityTest`
     * covers this by covering the expression.
     *
     * Returns null rather than a zero figure when nothing is priced: a card
     * reading "$0" is worse than a card with no price on it, and the frontend
     * can only tell the two apart if one of them is absent.
     *
     * @return array{amount: float, currency: string}|null
     */
    private function priceFrom(?string $source): ?array
    {
        if ($source === null || $source === '') {
            return null;
        }

        if ($source === 'products') {
            $query = Product::query()->selectRaw('MIN('.Product::priceFromAmountSql().') as figure');
        } elseif (str_starts_with($source, 'packages')) {
            $tier = str_contains($source, ':') ? explode(':', $source, 2)[1] : null;

            $query = Package::query()
                ->selectRaw('MIN('.Package::priceFromAmountSql().') as figure')
                // A tier that matches nothing must yield NO figure rather than
                // every package's — an operator who mistypes a tier should see
                // a missing price, not a wrong one.
                ->when($tier !== null && $tier !== '', fn ($q) => $q->where('tier', $tier));
        } else {
            return null;
        }

        $figure = $query->where('status', CatalogStatus::Published->value)->first()?->figure;

        return $figure === null ? null : [
            'amount' => round((float) $figure, 2),
            'currency' => 'USD',
        ];
    }
}
