<?php

namespace App\Enums\Patient;

/**
 * Who caused a security event.
 *
 * `Anonymous` is a request nobody has proven anything about — a failed sign-in,
 * or an anonymous "email me a link". It is not the same as `Patient`, which
 * means the account holder acted through a session or a link that proves them.
 */
enum SecurityEventActor: string
{
    case Patient = 'patient';
    case Anonymous = 'anonymous';
    case Operator = 'operator';
    case System = 'system';
}
