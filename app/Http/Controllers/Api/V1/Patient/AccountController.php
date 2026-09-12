<?php

namespace App\Http\Controllers\Api\V1\Patient;

use App\Actions\Exceptions\ActionException;
use App\Actions\Patient\ClaimPatientRecordAction;
use App\Actions\Patient\RequestClaimLinkAction;
use App\Data\Patient\PatientResource;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A patient's own account, as distinct from the clinical data PortalController
 * proxies. Nothing here talks to the provider.
 *
 * Connecting a record is two requests with an email between them. There used to
 * be one — `POST /patient/link-chart` with an order uuid — and it was removed
 * rather than kept alongside: its checks were a strict subset of these, so
 * leaving it reachable would have left the unverified path open.
 */
class AccountController extends ApiController
{
    /** What the patient is told whether or not an order matched. */
    public const LINK_SENT = 'If there is an order under your email address, we have sent a link to connect your record to it.';

    /**
     * Email a link to connect the record an order created.
     *
     * Takes no body. The order is resolved from the session's own account and
     * the link goes to the address that order was placed under. The answer is
     * the same 202 whether or not anything matched — see RequestClaimLinkAction.
     *
     * @tags PatientAuth
     */
    public function requestClaimLink(Request $request, RequestClaimLinkAction $action): JsonResponse
    {
        try {
            $action->execute($request->user(), $request->ip());
        } catch (ActionException $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }

        return response()->json(['data' => ['sent' => true], 'message' => self::LINK_SENT], 202);
    }

    /**
     * Use a claim link. The token arrives in the BODY of a POST, never a URL:
     * mail scanners follow links, and a single-use token spent by a scanner is
     * a link the patient can no longer use.
     *
     * @tags PatientAuth
     */
    public function claim(Request $request, ClaimPatientRecordAction $action): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        $patient = $action->execute(
            $request->user(),
            $request->input('token'),
            $current instanceof PersonalAccessToken ? $current->getKey() : null,
            $request->ip(),
        );

        return $this->success(['patient' => PatientResource::fromModel($patient)->toArray()]);
    }
}
