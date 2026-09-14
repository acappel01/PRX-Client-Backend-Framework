<?php

namespace App\Services\Attribution;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Schema allowlist for trusted producers; never pass a model or request body as payload. */
class CanonicalEventRegistry
{
    public const LEAD_CAPTURED = 'lead.captured';

    public const QUIZ_COMPLETED = 'quiz.completed';

    public const SOURCE_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public function validate(string $name, int $schemaVersion, array $payload): array
    {
        $rules = [
            'name' => [Rule::in([self::LEAD_CAPTURED, self::QUIZ_COMPLETED])],
            'schema_version' => [Rule::in([1])],
            'payload' => ['required', 'array:source,goal_keys,quiz'],
            'payload.source' => ['required', 'array:'.implode(',', self::SOURCE_FIELDS)],
            'payload.goal_keys' => ['present', 'array', 'list'],
            'payload.goal_keys.*' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'distinct:strict'],
            'payload.quiz' => [$name === self::QUIZ_COMPLETED ? 'required' : 'nullable', 'array:id,version'],
            'payload.quiz.id' => ['required_with:payload.quiz', 'integer', 'min:1'],
            'payload.quiz.version' => ['required_with:payload.quiz', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
        foreach (self::SOURCE_FIELDS as $field) {
            $rules['payload.source.'.$field] = ['present', 'nullable', 'string', 'max:255'];
        }
        Validator::make(['name' => $name, 'schema_version' => $schemaVersion, 'payload' => $payload], $rules)->validate();

        return $this->canonicalize($payload);
    }

    /** Stable associative-key ordering; list order stays part of the business event. */
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
