<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\ReadsEmailSuppression;
use App\Integrations\Contracts\SyncsContacts;
use App\Integrations\Contracts\TracksEvents;
use App\Integrations\Messages\ContactPayload;
use App\Integrations\Messages\EmailSuppressionRead;
use App\Integrations\Support\TalksToVendorApi;
use App\Models\Integrations\IntegrationInstance;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klaviyo — profiles, lists and events.
 *
 * ─── What it does and pointedly does not ───────────────────────────────
 *
 * `SyncsContacts` + `TracksEvents`, and **not** `EnrollsInAutomations`. That is
 * not a gap to fill later: Klaviyo has no endpoint that drops a profile into a
 * flow, and its terms are explicit that a profile enters a flow by meeting the
 * flow's trigger. So the way to "start the follow-up" here is to record an
 * event and let a flow trigger on it — which is why `TracksEvents` exists as its
 * own interface rather than as a method on one big CRM contract.
 *
 * ─── Groups are LIST IDS, and the mapping is the operator's ────────────
 *
 * `addToGroup()` receives a semantic name like "quiz-completers". Klaviyo needs
 * an opaque list id (`Xy3Abc`), so the instance's settings carry a name → id
 * map. The alternative — making workflow authors paste list ids into every step
 * — spreads an opaque identifier across the funnel and breaks every step the day
 * a list is rebuilt.
 *
 * A NAME PASSES THROUGH UNMAPPED if it already looks like an id, so an operator
 * who prefers ids is not forced to invent aliases.
 *
 * ─── Things that will bite whoever extends this ────────────────────────
 *
 *  * `external_id` does NOT participate in Klaviyo's profile merging. Keying on
 *    our lead id CREATES duplicates rather than preventing them, so it is sent
 *    as a back-reference only and identity is email/phone.
 *  * Consent and profile properties can never be one request. Subscribing is a
 *    different endpoint with different rules, so `upsertContact()` sets no
 *    consent — `addToGroup()` is where the two verbs part company.
 *  * A private key's scopes are fixed when it is created, and `test()` CANNOT
 *    SEE THEM. It reads `/accounts/`, which needs only `accounts:read`, so a
 *    read-only key reports a healthy connection and then 403s on every write —
 *    which is what this install's first key did, meaning no lead ever reached
 *    Klaviyo at all. The driver needs `accounts:read`, `profiles:write`,
 *    `lists:write`, `subscriptions:write` and `events:write`; the credential
 *    field names them, because the connection test cannot check them and there
 *    is no endpoint that reports a key's scopes.
 *  * Klaviyo's acceptable-use policy bars health data and they do not sign BAAs.
 *    That is a fact about their terms, NOT a rule this class enforces — the
 *    operator's own attestation decides, and `FieldMap` enforces it upstream.
 *    Do not add a hardcoded refusal here; it would be wrong for an install whose
 *    contract differs and would go stale when their terms change.
 *
 * PARTLY VERIFIED against a live account: the `revision` header, the credential
 * check in `test()` and the shape of a list id are confirmed. Everything else —
 * the profile-import, list-membership and event endpoints, and their JSON:API
 * envelopes — is built to Klaviyo's documented shapes and has not been exercised
 * against the real service.
 */
class KlaviyoDriver implements ReadsEmailSuppression, SyncsContacts, TracksEvents
{
    use TalksToVendorApi;

    private const BASE = 'https://a.klaviyo.com/api';

    /**
     * Klaviyo pins behaviour to a dated revision rather than a path version, so
     * this is effectively the API version. Changing it can change response
     * shapes; do it deliberately, not as housekeeping.
     */
    private const REVISION = '2026-07-15';

    /** Authenticated GETs only; never uses the vendor-error-body reporting path. */
    public function readEmailSuppression(IntegrationInstance $instance, string $accountId, string $profileId, string $email): EmailSuppressionRead
    {
        $result = fn (string $status, string $reason) => new EmailSuppressionRead($status, $reason, self::REVISION);
        try {
            $accounts = $this->suppressionJson($instance, '/accounts/', ['fields[account]' => 'id']);
            if ($accounts === null) {
                return $result('unknown', 'account_read_failed');
            }
            $data = $accounts['data'] ?? null;
            if (! is_array($data) || ! array_is_list($data) || count($data) !== 1
                || ($data[0]['type'] ?? null) !== 'account' || ($data[0]['id'] ?? null) !== $accountId
                || data_get($accounts, 'links.next') !== null) {
                return $result('unknown', 'account_scope_mismatch');
            }
            $profile = $this->suppressionJson($instance, '/profiles/'.rawurlencode($profileId).'/', [
                'additional-fields[profile]' => 'subscriptions', 'fields[profile]' => 'email,subscriptions',
            ]);
            if ($profile === null) {
                return $result('unknown', 'profile_read_failed');
            }
            if (data_get($profile, 'data.type') !== 'profile' || data_get($profile, 'data.id') !== $profileId
                || data_get($profile, 'data.attributes.email') !== $email) {
                return $result('unknown', 'profile_identity_mismatch');
            }
            $marketing = data_get($profile, 'data.attributes.subscriptions.email.marketing');
            if (! is_array($marketing) || ! is_bool($marketing['can_receive_email_marketing'] ?? null)
                || ! in_array($marketing['consent'] ?? null, ['SUBSCRIBED', 'UNSUBSCRIBED', 'NEVER_SUBSCRIBED'], true)
                || ! is_array($marketing['suppression'] ?? null) || ! array_is_list($marketing['suppression'])
                || ! is_array($marketing['list_suppressions'] ?? null) || ! array_is_list($marketing['list_suppressions'])) {
                return $result('unknown', 'subscription_evidence_missing');
            }
            // Any list suppression blocks: canonical events have no verified list/flow scope yet.
            if ($marketing['can_receive_email_marketing'] !== true || $marketing['consent'] !== 'SUBSCRIBED'
                || $marketing['suppression'] !== [] || $marketing['list_suppressions'] !== []) {
                return $result('suppressed', 'remote_marketing_blocked');
            }

            return $result('clear', 'verified_read');
        } catch (\Throwable) {
            return $result('unknown', 'read_failed');
        }
    }

    /** Bounded in-memory decode; no redirects, content decompression, body logging or persistence. */
    private function suppressionJson(IntegrationInstance $instance, string $path, array $query): ?array
    {
        $key = $this->credential($instance->credentials ?? [], 'private_key', 'Klaviyo private API key');
        $deadline = hrtime(true) + 15_000_000_000;
        $response = Http::timeout(15)->connectTimeout(5)->withoutRedirecting()
            ->withOptions(['stream' => true, 'decode_content' => false, 'read_timeout' => 5])
            ->withHeaders(['Accept-Encoding' => 'identity', 'Authorization' => 'Klaviyo-API-Key '.$key,
                'revision' => self::REVISION, 'accept' => 'application/vnd.api+json'])->get(self::BASE.$path, $query);
        $stream = $response->toPsrResponse()->getBody();
        try {
            if ($response->status() !== 200 || ! in_array(strtolower($response->header('Content-Encoding')), ['', 'identity'], true)) {
                return null;
            }
            $body = '';
            while (! $stream->eof() && strlen($body) <= 65536) {
                if (hrtime(true) >= $deadline) {
                    return null;
                }
                $chunk = $stream->read(min(8192, 65537 - strlen($body)));
                if ($chunk === '' && ! $stream->eof()) {
                    return null;
                }
                $body .= $chunk;
            }
            if (strlen($body) > 65536 || hrtime(true) >= $deadline) {
                return null;
            }
            $object = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
            if (str_starts_with($path, '/profiles/')) {
                $marketing = data_get($object, 'data.attributes.subscriptions.email.marketing');
                if (! is_object($marketing) || ! is_array($marketing->suppression ?? null)
                    || ! is_array($marketing->list_suppressions ?? null)) {
                    return null;
                }
            }
            $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

            return is_array($json) ? $json : null;
        } finally {
            $stream->close();
        }
    }

    public function test(IntegrationInstance $instance): void
    {
        // The cheapest authenticated read there is. It answers "is this key
        // valid and does it have any scope at all", which is the question.
        $this->ok(
            $this->request($instance)->get(self::BASE.'/accounts/'),
            'Connecting to Klaviyo',
        );
    }

    public function upsertContact(IntegrationInstance $instance, ContactPayload $contact): string
    {
        if ($contact->email === null && $contact->phone === null) {
            // Klaviyo identifies by email or phone. Without one it would create
            // an unreachable profile on every run.
            throw new RuntimeException('Klaviyo needs an email address or a phone number to identify someone.');
        }

        $attributes = array_filter([
            'email' => $contact->email,
            'phone_number' => $contact->phone,
            'first_name' => $contact->firstName,
            'last_name' => $contact->lastName,
            // A back-reference for our own reconciliation. NOT a merge key —
            // see the class doc.
            'external_id' => $contact->externalId,
            // The identity fields are already first-class attributes above, and
            // `withIdentity()` always puts them in the mapped set — so without
            // this every profile would also grow four duplicate custom
            // properties that can drift from the real ones. GoHighLevel's driver
            // excludes the same four for the same reason.
            'properties' => $this->customProperties($contact) ?: null,
        ], fn ($value): bool => $value !== null);

        $response = $this->ok(
            $this->request($instance)->post(self::BASE.'/profile-import/', [
                'data' => ['type' => 'profile', 'attributes' => $attributes],
            ]),
            'Sending a profile to Klaviyo',
        );

        $id = $response->json('data.id');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Klaviyo accepted the profile but returned no id.');
        }

        return $id;
    }

    /**
     * Put somebody on a list — subscribing them where they consented to it.
     *
     * ─── WHY THIS BRANCHES, AND WHAT WENT WRONG WHEN IT DID NOT ────────
     *
     * `POST /lists/{id}/relationships/profiles/` adds a profile to a list and
     * sets NO consent. Klaviyo will not send marketing to a profile without
     * consent, but an "Added to List" flow still fires — so the funnel looks
     * like it is working, the run log says success, and the email is suppressed
     * on their side. Nothing anywhere reports it. That was the shipped
     * behaviour, and it is the exact failure mode this project keeps meeting:
     * a success that is not one.
     *
     * The fix is not "always subscribe". Subscribing somebody who did not agree
     * is the worse error of the two, and it is the one that reaches a regulator
     * rather than a support inbox. So the verb comes from OUR consent audit,
     * which makes it structurally impossible to opt somebody in that our own
     * records say did not opt in.
     *
     * ─── Per channel, and only with the identifier that channel needs ──
     *
     * `subscriptions` is keyed by channel, and a channel omitted is left
     * untouched rather than cleared. An email grant with no email address on the
     * payload subscribes nothing, so it is dropped here rather than sent for
     * Klaviyo to reject.
     *
     * `consented_at` is deliberately NOT sent: Klaviyo only accepts it under
     * `historical_import: true`, which also bypasses double opt-in and the
     * "Added to List" flows — wrong for a live capture, and our timestamp
     * evidence lives in `lead_consents` regardless.
     *
     * NEEDS `subscriptions:write` ON THE KEY. `test()` proves only that the key
     * reads `/accounts/`, and a key's scopes are fixed when it is minted, so an
     * install can pass the connection test and fail here.
     */
    public function addToGroup(
        IntegrationInstance $instance,
        string $remoteId,
        string $group,
        ContactPayload $contact,
    ): void {
        $listId = $this->listId($instance, $group);

        $subscriptions = $this->subscriptions($contact);

        if ($subscriptions === []) {
            $this->ok(
                $this->request($instance)->post(self::BASE."/lists/{$listId}/relationships/profiles/", [
                    'data' => [['type' => 'profile', 'id' => $remoteId]],
                ]),
                "Adding someone to the Klaviyo list [{$group}]",
            );

            return;
        }

        $profile = array_filter([
            'email' => $contact->email,
            'phone_number' => $contact->phone,
        ], fn ($value): bool => $value !== null);

        $this->ok(
            $this->request($instance)->post(self::BASE.'/profile-subscription-bulk-create-jobs/', [
                'data' => [
                    'type' => 'profile-subscription-bulk-create-job',
                    'attributes' => [
                        'profiles' => [
                            'data' => [[
                                'type' => 'profile',
                                'id' => $remoteId,
                                'attributes' => $profile + ['subscriptions' => $subscriptions],
                            ]],
                        ],
                    ],
                    'relationships' => [
                        'list' => ['data' => ['type' => 'list', 'id' => $listId]],
                    ],
                ],
            ]),
            "Subscribing someone to the Klaviyo list [{$group}]",
        );
    }

    public function trackEvent(IntegrationInstance $instance, ContactPayload $contact, string $event, array $properties = []): array
    {
        $identifier = array_filter([
            'email' => $contact->email,
            'phone_number' => $contact->phone,
        ], fn ($value): bool => $value !== null);

        if ($identifier === []) {
            throw new RuntimeException('Klaviyo needs an email address or a phone number to attach an event to.');
        }

        $this->ok(
            $this->request($instance)->post(self::BASE.'/events/', [
                'data' => [
                    'type' => 'event',
                    'attributes' => [
                        'properties' => (object) $properties,
                        'metric' => ['data' => ['type' => 'metric', 'attributes' => ['name' => $event]]],
                        'profile' => ['data' => ['type' => 'profile', 'attributes' => $identifier]],
                    ],
                ],
            ]),
            "Recording the Klaviyo event [{$event}]",
        );

        // Klaviyo answers 202 with no body. Nothing to return but the fact it
        // was accepted — and the run log must not carry the properties, which
        // are the person's own data.
        return ['accepted' => true, 'metric' => $event];
    }

    /**
     * The `subscriptions` block, built from consent we actually hold.
     *
     * Empty means "no channel can be subscribed" — either nothing was granted,
     * or what was granted has no identifier to subscribe. The caller reads that
     * as "fall back to a plain list add", never as "subscribe everything".
     *
     * Note what the second case means: a consented person carrying neither an
     * email address nor a phone number takes the unsubscribed branch. That is
     * the original defect for that one person, and it is left alone because
     * there is nothing to suppress a send to — `upsertContact()` refuses such a
     * payload before this is ever reached in a `sync_contact` run.
     *
     * Klaviyo's channel names happen to match `lead_consents.channel` for email
     * and sms; the mapping is written out rather than assumed so a channel this
     * install adds later (postal, push) does not silently become a Klaviyo key.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private function subscriptions(ContactPayload $contact): array
    {
        $subscriptions = [];

        if ($contact->consents('email') && $contact->email !== null) {
            $subscriptions['email'] = ['marketing' => ['consent' => 'SUBSCRIBED']];
        }

        if ($contact->consents('sms') && $contact->phone !== null) {
            $subscriptions['sms'] = ['marketing' => ['consent' => 'SUBSCRIBED']];
        }

        return $subscriptions;
    }

    /**
     * Everything the operator mapped that is not already a first-class field.
     *
     * @return array<string, mixed>
     */
    private function customProperties(ContactPayload $contact): array
    {
        return array_diff_key(
            $contact->attributes,
            array_flip(['email', 'phone', 'first_name', 'last_name']),
        );
    }

    /**
     * Resolve a semantic group name to a Klaviyo list id.
     *
     * The map lives in the instance's settings so one workflow step reads the
     * same in every install. A value that already looks like a list id is passed
     * through, so an operator who prefers ids is not made to invent aliases.
     */
    private function listId(IntegrationInstance $instance, string $group): string
    {
        foreach (($instance->settings['lists'] ?? []) as $row) {
            if (($row['name'] ?? null) === $group && filled($row['list_id'] ?? null)) {
                return (string) $row['list_id'];
            }
        }

        // A Klaviyo list id is six alphanumeric characters and effectively always
        // carries a digit or a capital (`XyZ123`, `Ru4Ff9`). Requiring that is
        // not pedantry: a looser rule matches ordinary six-letter list NAMES —
        // `buyers`, `vipsss` — and sends them off as bogus ids, skipping the
        // "you forgot to map this" error written for exactly that mistake. The
        // request still fails, but wearing Klaviyo's 404 instead of the sentence
        // that says what to do.
        if (preg_match('/^(?=.*[0-9A-Z])[A-Za-z0-9]{6}$/', $group) === 1) {
            return $group;
        }

        throw new RuntimeException(
            "No Klaviyo list is mapped to [{$group}] on this integration. Add it under the "
            .'integration\'s list mapping, or use the Klaviyo list ID itself (six characters, '
            .'as shown in Klaviyo\'s list URL).'
        );
    }

    private function request(IntegrationInstance $instance)
    {
        $key = $this->credential($instance->credentials ?? [], 'private_key', 'Klaviyo private API key');

        return $this->http()->withHeaders([
            'Authorization' => 'Klaviyo-API-Key '.$key,
            'revision' => self::REVISION,
            'accept' => 'application/vnd.api+json',
            'content-type' => 'application/vnd.api+json',
        ]);
    }
}
