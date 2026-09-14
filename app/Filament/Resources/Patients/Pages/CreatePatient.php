<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Actions\Customers\EnsureCustomerForPortalAccountAction;
use App\Filament\Resources\Patients\PatientResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePatient extends CreateRecord
{
    protected static string $resource = PatientResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            // This form has no password field. A random, undisclosed password
            // keeps staff-created accounts on the existing mailbox reset flow.
            $data['password'] = Str::random(64);
            $account = parent::handleRecordCreation($data);
            app(EnsureCustomerForPortalAccountAction::class)->execute($account);

            return $account;
        });
    }
}
