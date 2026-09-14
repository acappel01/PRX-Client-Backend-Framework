<?php

namespace App\Actions\Leads;

use App\Data\Leads\LeadData;
use App\Data\Leads\LeadSubmissionData;
use App\Data\Leads\LeadSubmissionResultData;
use App\Events\Quiz\QuizCompleted;
use App\Http\Resources\Api\V1\Leads\LeadResource;
use App\Models\Lead;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Optional public capture idempotency; no provider or destination delivery. */
class SubmitLeadAction
{
    public function __construct(private readonly CreateLeadAction $createLead) {}

    /** Replay before consulting mutable quiz definitions. No write or workflow replay. */
    public function replay(LeadSubmissionData $submission): ?LeadSubmissionResultData
    {
        $row = DB::table('lead_submissions')->where('submission_key', $submission->key)->first();

        return $row === null ? null : $this->result($row, $submission);
    }

    public function execute(LeadData $data, LeadSubmissionData $submission): LeadSubmissionResultData
    {
        return DB::transaction(function () use ($data, $submission): LeadSubmissionResultData {
            try {
                // Contain a duplicate insert within a savepoint on PostgreSQL.
                DB::transaction(fn () => DB::table('lead_submissions')->insert([
                    'submission_key' => $submission->key,
                    'owner_hash' => $submission->ownerHash,
                    'request_fingerprint' => $submission->fingerprint,
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                // Current read sees the winner even with an older MySQL snapshot.
                $row = DB::table('lead_submissions')->where('submission_key', $submission->key)->lockForUpdate()->first();
                if ($row === null) {
                    throw $exception;
                }

                return $this->result($row, $submission);
            }

            $lead = $this->createLead->execute($data);
            // Freeze the public result, including timestamps, before any workflow
            // can change the Lead. No internal fields or owner secret are stored.
            $response = (new LeadResource($lead))->resolve();
            DB::table('lead_submissions')->where('submission_key', $submission->key)->update([
                'lead_id' => $lead->id,
                'response' => Crypt::encryptString(json_encode($response, JSON_THROW_ON_ERROR)),
                'updated_at' => now(),
            ]);
            if ($data->quiz_id !== null) {
                DB::afterCommit(fn () => QuizCompleted::dispatch($lead));
            }

            return new LeadSubmissionResultData($response);
        }, 3);
    }

    private function result(stdClass $row, LeadSubmissionData $submission): LeadSubmissionResultData
    {
        // Same generic conflict for wrong owner/content/unavailable result. A
        // visitor ID, public key, email or cart identifier cannot authorize this.
        if (! hash_equals($row->owner_hash, $submission->ownerHash)
            || ! hash_equals($row->request_fingerprint, $submission->fingerprint)
            || $row->response === null || $row->lead_id === null
            || Lead::query()->whereKey($row->lead_id)->lockForUpdate()->first(['id']) === null) {
            throw new ConflictHttpException('This submission cannot be replayed.');
        }

        return new LeadSubmissionResultData(json_decode(Crypt::decryptString($row->response), true, flags: JSON_THROW_ON_ERROR));
    }
}
