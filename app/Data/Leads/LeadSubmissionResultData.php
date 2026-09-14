<?php

namespace App\Data\Leads;

use Spatie\LaravelData\Data;

class LeadSubmissionResultData extends Data
{
    public function __construct(public array $response) {}
}
