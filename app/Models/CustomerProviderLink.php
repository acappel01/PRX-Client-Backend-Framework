<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Commerce reference only: never evidence for portal chart entitlement. */
class CustomerProviderLink extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['chart_id', 'patient_id', 'patient_number'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function providerInstance(): BelongsTo
    {
        return $this->belongsTo(ProviderInstance::class);
    }
}
