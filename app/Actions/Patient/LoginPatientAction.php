<?php

namespace App\Actions\Patient;

use App\Models\Patient;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

class LoginPatientAction
{
    /**
     * @return array{patient: Patient, token: string}
     *
     * @throws AuthenticationException
     */
    public function execute(string $email, string $password, string $deviceName = 'api'): array
    {
        $patient = Patient::where('email', $email)->first();

        if (! $patient || ! Hash::check($password, $patient->password)) {
            throw new AuthenticationException('The provided credentials are incorrect.');
        }

        // `patient:*`, the same as every other patient session. It was `['*']`,
        // which nothing checks today (EnsurePatientToken tests the model type),
        // but a token that claims every ability is one refactor from meaning it.
        $token = $patient->createToken($deviceName, ['patient:*'])->plainTextToken;

        return compact('patient', 'token');
    }
}
