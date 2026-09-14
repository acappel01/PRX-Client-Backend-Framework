<?php

namespace App\Services\Attribution;

use App\Enums\Privacy\DataClassification;
use App\Enums\Quiz\QuizQuestionKind;
use App\Models\Kb\HealthGoal;
use App\Models\Lead;
use App\Models\Quiz\QuizQuestion;

/** Original capture projection, not final referral credit or permission to deliver. */
class LeadEventPayload
{
    public function forLead(Lead $lead): array
    {
        $source = [];
        foreach (CanonicalEventRegistry::SOURCE_FIELDS as $field) {
            $source[$field] = $this->sourceValue($lead->{$field}, $lead->uuid);
        }
        $payload = ['source' => $source, 'goal_keys' => [], 'quiz' => null];
        if ($lead->quiz_id === null) {
            return $payload;
        }

        $questions = QuizQuestion::query()->where('quiz_id', $lead->quiz_id)
            ->with(['step', 'options'])->orderBy('id')->get();
        $goals = HealthGoal::query()->forQuiz()->orderBy('id')->get();
        $allowedGoals = $goals->pluck('slug')->filter(fn ($slug) => is_string($slug)
            && strlen($slug) <= 255 && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug))->all();
        $answers = $lead->quiz_answers ?? [];

        foreach ($questions as $question) {
            if (! $question->is_active || ! $question->step?->is_active
                || $question->kind !== QuizQuestionKind::HealthGoals
                || ! in_array($question->effectiveDataClass(), [DataClassification::General, DataClassification::Sensitive], true)) {
                continue;
            }
            $values = $answers[$question->slug] ?? [];
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (is_string($value) && in_array($value, $allowedGoals, true)) {
                    $payload['goal_keys'][] = $value;
                }
            }
        }
        $payload['goal_keys'] = array_values(array_unique($payload['goal_keys']));
        sort($payload['goal_keys'], SORT_STRING);

        // A snapshot fingerprint identifies the authored policy/catalog read at
        // capture. It is not a recoverable archive of historical definitions.
        // Only this digest is persisted; prompts, config and other answers are
        // never part of the event payload. Replays use the stored projection.
        $definition = [
            'projection_version' => 1,
            'quiz_id' => $lead->quiz_id,
            'questions' => $questions->map(fn ($question) => [
                'question' => $question->getRawOriginal(),
                'effective_class' => $question->effectiveDataClass()->value,
                'step' => $question->step?->getRawOriginal(),
                'options' => $question->options->sortBy('id')->values()->map(fn ($option) => $option->getRawOriginal())->all(),
            ])->all(),
            'goals' => $goals->map(fn ($goal) => $goal->getRawOriginal())->all(),
        ];
        $payload['quiz'] = ['id' => (int) $lead->quiz_id,
            'version' => hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR))];

        return $payload;
    }

    private function sourceValue(mixed $value, ?string $leadUuid): ?string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > 255
            || preg_match('/[\x00-\x1f\x7f?&#=]|:\/\//u', $value)
            || str_starts_with($value, '/')
            || ($leadUuid !== null && stripos($value, $leadUuid) !== false)) {
            return null;
        }

        return $value;
    }
}
