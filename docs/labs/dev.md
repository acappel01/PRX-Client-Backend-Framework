# Patient lab-order status

Implemented in the isolated Customer commerce branch; deployment and provider runtime qualification remain separate. This is a read-only status list, not a lab-results viewer or ordering/payment flow.

## Contract and provenance

`GET /api/v1/patient/labs?page=1&per_page=20` uses the existing Patient session, session expiry, required 2FA and no-store/noindex middleware, plus explicit `patient:*` token ability (legacy `*` retains Sanctum wildcard semantics). Non-Patient identities cannot enter. An unlinked account receives the existing `409 code=no_linked_chart`; it makes no provider request. Page is 1–10,000; page size is 1–100, default 20. Invalid pagination is 422.

The admin derives the chart solely from the signed-in `Patient.prx_patient_chart_id`. It forwards only bounded numeric pagination to PRX `GET /api/v1/patients/{chart}/labs` using the existing patient-scoped token. Request chart/filter/include values cannot change this. Existing automatic patient-token renewal retries one read after upstream 401; there is no organization-token fallback for reading labs.

Source contract inspected at PRX `39ed32965bfa2e821403e747fc2c630015ffa476`: `routes/api.php:62,97` requires `patient:read`; `LabOrderController::indexForChart` injects exact chart filtering, then `index` applies tenant/patient scope. `TokenAbility::forUserType(PATIENT)` and the admin's existing `IssuePortalTokenAction` permit that ability. The list maps status/date fields explicitly and does not serialize results or lab names, even with allowed includes. `ApiResponseTrait` places pagination under `meta.pagination`. This is inspected source plus synthetic contract testing, not evidence of deployed PRX capability. No PRX source, database or remote API was changed or queried.

`Client::getPatientLabOrders` preserves pagination, verifies every returned row's chart equals the requested chart, rejects malformed/non-list/oversized results, and requires coherent numeric metadata matching the requested page and size. Unknown status strings remain available; no success/payment interpretation is assigned. Reads refuse redirects, use an in-memory stream (no `php://temp` disk spill), and cap accepted download/body size at 512 KiB (the progress callback may observe one transfer chunk beyond the threshold before aborting). They request identity encoding and disable transparent decompression, so compressed content cannot expand past the transfer guard. No response cache, database record or clinical raw-body logging is added. Provider non-2xx errors use a generic exception without copying/logging their body. Existing central upstream-error behavior remains in effect.

`PortalController::labs` passes the result through `PortalResponseFilter`'s explicit `labs` spec. The response is:

```json
{"data":{"items":[{"id":"opaque-lab-id","lab_order_number":"LAB-100","status":"results_received","collection_method":"walk_in_draw","ordered_at":"2026-09-14T12:00:00Z","results_received_at":null,"completed_at":null,"created_at":"2026-09-14T12:00:00Z"}],"pagination":{"current_page":1,"last_page":1,"per_page":20,"total":1}}}
```

Absent optional provider fields remain absent; null dates stay null. The projection drops chart/encounter/provider identity, billing/payment/cost fields, result values, checkout/requisition URLs and all arbitrary metadata. Only the list's lab ID is exposed as an opaque display identity; no detail endpoint or result link is introduced. The portal constructs its own pagination URLs rather than following upstream links.

## Qualification and remaining scope

`LabHistoryTest` uses isolated synthetic records and faked HTTP with stray requests forbidden. It checks exact session chart/token/query binding, rejected foreign-chart rows, nested malformed values, pagination validation, empty-vs-failure, missing linked chart, wrong principal/ability, required 2FA, one renewal after 401, response minimization and failure-log hygiene. Related ownership/error/vitals suites are run with it. Runtime PRX tenant configuration and real-patient screen-reader qualification remain release checks, not development claims.

Lab-result detail requires its own verified patient visibility/result-release contract and reviewed projection. The source's separate detail API exposes biomarker results, but this status list cannot establish that those results are released for patient display. Do not turn `results_received_at` into an actionable result link, infer reviewed/normal findings, forward a checkout URL, or add uploads/order/payment controls without that work.

Final isolated qualification: seven focused labs tests / 48 assertions and 31 labs/ownership/upstream-error/vitals tests / 167 assertions passed. Independent Astra review approved after transport-failure sanitization and compressed-response limit fixes. Pint and diff checks passed. These results use synthetic data and do not certify a deployed provider build.
