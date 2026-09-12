<?php

namespace App\Integrations\Messages;

/**
 * One email, in the only terms every provider agrees on.
 *
 * A value object rather than an array so a driver cannot quietly depend on a key
 * that another caller does not set. Deliberately minimal: anything a specific
 * vendor needs beyond this belongs in that instance's `settings`, not in a
 * shared shape every other driver has to ignore.
 */
readonly class EmailMessage
{
    /**
     * @param  array<string, mixed>  $context  Merge data for a templated send.
     * @param  bool  $trackLinks  False for a message whose links carry a secret.
     *                            A provider's click tracking rewrites every link
     *                            through its own redirector — which, for a
     *                            single-use token, hands the token to a third
     *                            host (on Mailgun, over plain HTTP by default).
     *                            Each driver maps this to its vendor's opt-out,
     *                            so an operator switching tracking on in a
     *                            vendor dashboard cannot leak one.
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public ?string $toName = null,
        public ?string $template = null,
        public array $context = [],
        public bool $trackLinks = true,
    ) {}
}
