<?php

namespace App\Services\Payments;

use Illuminate\Validation\ValidationException;
use stdClass;

/** Bounded syntax walk detects duplicate decoded property names before native decoding. */
class StrictNotificationJson
{
    private string $json;

    private int $offset;

    private int $nodes;

    public function decode(string $json): stdClass
    {
        $this->json = $json;
        $this->offset = 0;
        $this->nodes = 0;
        try {
            $this->value(0);
            $this->space();
            if ($this->offset !== strlen($json)) {
                $this->reject();
            }
            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof stdClass) {
                $this->reject();
            }

            return $decoded;
        } catch (\Throwable) {
            $this->reject();
        } finally {
            $this->json = '';
        }
    }

    private function value(int $depth): void
    {
        if ($depth > 30 || ++$this->nodes > 4096) {
            $this->reject();
        }
        $this->space();
        $c = $this->json[$this->offset] ?? '';
        if ($c === '{' || $c === '[') {
            $object = $c === '{';
            $end = $object ? '}' : ']';
            $this->offset++;
            $this->space();
            $seen = [];
            if (($this->json[$this->offset] ?? '') === $end) {
                $this->offset++;

                return;
            }
            while (true) {
                if ($object) {
                    $this->space();
                    $key = $this->string();
                    if (array_key_exists($key, $seen)) {
                        $this->reject();
                    }
                    $seen[$key] = true;
                    $this->space();
                    if (($this->json[$this->offset++] ?? '') !== ':') {
                        $this->reject();
                    }
                }
                $this->value($depth + 1);
                $this->space();
                $next = $this->json[$this->offset++] ?? '';
                if ($next === $end) {
                    return;
                }
                if ($next !== ',') {
                    $this->reject();
                }
            }
        }
        if ($c === '"') {
            $this->string();

            return;
        }
        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($this->json, $this->offset, strlen($literal)) === $literal) {
                $this->offset += strlen($literal);

                return;
            }
        }
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $this->json, $match, 0, $this->offset) === 1) {
            $this->offset += strlen($match[0]);

            return;
        }
        $this->reject();
    }

    private function string(): string
    {
        $start = $this->offset;
        if (($this->json[$this->offset++] ?? '') !== '"') {
            $this->reject();
        }
        while ($this->offset < strlen($this->json)) {
            $c = $this->json[$this->offset++];
            if ($c === '\\') {
                $this->offset++;

                continue;
            }
            if ($c === '"') {
                $value = json_decode(substr($this->json, $start, $this->offset - $start), true, 2, JSON_THROW_ON_ERROR);
                if (! is_string($value)) {
                    $this->reject();
                }

                return $value;
            }
        }
        $this->reject();
    }

    private function space(): void
    {
        while (isset($this->json[$this->offset]) && str_contains(" \t\r\n", $this->json[$this->offset])) {
            $this->offset++;
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['notification' => 'The notification envelope is invalid.']);
    }
}
