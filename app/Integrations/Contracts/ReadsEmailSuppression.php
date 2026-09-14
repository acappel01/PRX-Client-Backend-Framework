<?php

namespace App\Integrations\Contracts;

use App\Integrations\Messages\EmailSuppressionRead;
use App\Models\Integrations\IntegrationInstance;

interface ReadsEmailSuppression
{
    public function readEmailSuppression(IntegrationInstance $instance, string $accountId, string $profileId, string $email): EmailSuppressionRead;
}
