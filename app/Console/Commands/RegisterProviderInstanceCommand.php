<?php

namespace App\Console\Commands;

use App\Models\ProviderInstance;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

class RegisterProviderInstanceCommand extends Command
{
    protected $signature = 'customers:provider-instance {key} {provider} {environment} {external-account-id} {--account-type=tenant : Stable provider account namespace type}';

    protected $description = 'Register an immutable provider namespace without credentials or remote calls';

    public function handle(): int
    {
        $data = ['key' => $this->argument('key'), 'provider' => $this->argument('provider'),
            'environment' => $this->argument('environment'), 'account_type' => $this->option('account-type'), 'external_account_id' => $this->argument('external-account-id')];
        $validator = Validator::make($data, [
            'key' => ['required', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'provider' => ['required', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'environment' => ['required', 'in:sandbox,production'],
            'account_type' => ['required', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'external_account_id' => ['required', 'string', 'max:128', 'regex:/^\S+$/u'],
        ]);
        if ($validator->fails()) {
            $this->error('Invalid provider namespace. Use a stable key, provider, account type, sandbox/production and external account ID.');

            return self::FAILURE;
        }
        try {
            $instance = ProviderInstance::firstOrCreate(['key' => $data['key']], $data);
            foreach ($data as $field => $value) {
                if ($instance->{$field} !== $value) {
                    $this->error('The key already identifies a different provider namespace.');

                    return self::FAILURE;
                }
            }
        } catch (UniqueConstraintViolationException) {
            $this->error('This provider namespace is already registered under another key.');

            return self::FAILURE;
        }
        $this->info('Provider instance registered.');

        return self::SUCCESS;
    }
}
