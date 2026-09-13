<?php

namespace App\Console\Commands\PrescribeRx;

use App\Services\PrescribeRx\Client;
use App\Settings\IntegrationSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('prescribe-rx:register-webhook
    {--url= : Webhook URL (defaults to the webhooks.prescribe-rx route on this install)}
    {--events=* : Events to subscribe to (defaults to encounter.* order.* fulfillment.*)}
    {--list : List existing webhook subscriptions instead of creating}
    {--delete= : Delete a webhook subscription by ID}
    {--save-secret : Persist the returned signing secret to IntegrationSettings}
')]
#[Description('Register, list, or delete webhook subscriptions with prescribe-rx.')]
class RegisterWebhookCommand extends Command
{
    public function handle(IntegrationSettings $settings, Client $client): int
    {
        if ($this->option('list')) {
            return $this->listWebhooks($client);
        }

        if ($id = $this->option('delete')) {
            return $this->deleteWebhook($client, $id);
        }

        return $this->registerWebhook($settings, $client);
    }

    private function registerWebhook(IntegrationSettings $settings, Client $client): int
    {
        $url = $this->option('url') ?: route('webhooks.prescribe-rx');

        // The families the receiver acts on today; everything else it records
        // and ignores, so subscribing wider only costs deliveries.
        $events = $this->option('events') ?: ['encounter.*', 'order.*', 'fulfillment.*'];

        $this->line('');
        $this->info('Registering PRX webhook subscription');
        $this->line(str_repeat('─', 60));
        $this->line(sprintf('  URL:    %s', $url));
        $this->line(sprintf('  Events: %s', implode(', ', $events)));
        $this->line('');

        if (! $this->confirm('Proceed?', true)) {
            return self::FAILURE;
        }

        try {
            $subscription = $client->registerWebhook($url, $events);
        } catch (Throwable $e) {
            $this->error('✗ Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('✓ Subscription created');
        $this->line(sprintf('  ID:             %s', $subscription['id'] ?? '—'));
        $this->line(sprintf('  URL:            %s', $subscription['url'] ?? '—'));
        $this->line(sprintf('  Events:         %s', implode(', ', (array) ($subscription['events'] ?? []))));

        $secret = $subscription['secret'] ?? null;

        if ($secret) {
            $this->line('');
            $this->line('  Signing secret: '.str_repeat('•', 8).' (shown once)');
            $this->line('');
            $this->warn('  Copy this secret — it will not be shown again:');
            $this->line('  '.$secret);

            if ($this->option('save-secret') || $this->confirm('Save signing secret to IntegrationSettings now?', true)) {
                $settings->prescribe_rx_webhook_secret = $secret;
                $settings->save();
                $this->info('✓ Secret saved to IntegrationSettings (encrypted at rest).');
            } else {
                $this->warn('  Not saved. Store it manually in IntegrationSettings → prescribe_rx_webhook_secret.');
            }
        }

        return self::SUCCESS;
    }

    private function listWebhooks(Client $client): int
    {
        try {
            $subscriptions = $client->listWebhooks();
        } catch (Throwable $e) {
            $this->error('✗ Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($subscriptions)) {
            $this->line('No webhook subscriptions registered.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->info(sprintf('%d webhook subscription(s)', count($subscriptions)));
        $this->line(str_repeat('─', 60));

        foreach ($subscriptions as $sub) {
            $this->line(sprintf('  [%s]  %s', $sub['id'] ?? '?', $sub['url'] ?? '?'));
            $this->line(sprintf('         events: %s', implode(', ', (array) ($sub['events'] ?? []))));
        }

        return self::SUCCESS;
    }

    private function deleteWebhook(Client $client, string $id): int
    {
        if (! $this->confirm(sprintf('Delete webhook subscription %s?', $id), false)) {
            return self::FAILURE;
        }

        try {
            $ok = $client->deleteWebhook($id);
        } catch (Throwable $e) {
            $this->error('✗ Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $ok ? $this->info('✓ Deleted.') : $this->error('✗ Delete returned false.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
