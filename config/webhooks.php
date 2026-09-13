<?php

use App\Services\PrescribeRx\Webhooks\PrescribeRxWebhookHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound webhook handlers
    |--------------------------------------------------------------------------
    |
    | One handler per source slug — the same slug the receiving route records
    | on `inbound_webhook_events.source`. A recorded event whose source has no
    | handler here is marked `failed` rather than silently dropped.
    |
    */

    'handlers' => [
        'prescribe-rx' => PrescribeRxWebhookHandler::class,
    ],

];
