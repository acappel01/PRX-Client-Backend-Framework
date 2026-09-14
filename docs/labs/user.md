# Lab-order history

The patient portal's read-only lab-order history shows the lab order reference, recorded status, collection method and available dates from the linked clinical record. It requires the normal portal sign-in and any required two-step verification.

An account without a linked clinical record is directed to connect it. An empty list means no orders were returned for that account; an unavailable provider is shown as a failure rather than an empty history. Older orders are available through pagination.

This view does not show test values, interpret results, indicate that a bill was paid, or permit ordering or payment. A results-received date means the provider recorded receipt; it is not a claim that the results have been reviewed or released. No provider payment or document links are included.

The matching admin endpoint and provider capability must be qualified before deploying the portal page. No new staff permission or database migration is required for the lab status endpoint. Authentication, clinical ownership and provider configuration remain unchanged.
