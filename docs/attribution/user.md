# Attribution history and delivery preview status

The new internal records preserve original Lead capture source evidence and record destination policy checks. They do not change existing referral credit, send marketing, subscribe contacts, or report purchases/revenue.

There is no new operator screen or automatic history import in this increment. A developer can invoke the internal actions with explicit persisted event and integration IDs. Existing source summaries remain unchanged. Multiple visits across channels are not collected yet.

Every destination preview is blocked while verified suppression information is unavailable. Other reasons identify missing email consent, missing identity, unavailable destination or missing destination policy. Earlier checks remain immutable; a later check reads the latest audited consent and configuration. A previous successful consent check never authorizes a future send.

Klaviyo delivery activation, suppression synchronization and verified outcomes remain separate work. No served database migration or marketing activation was performed for this increment.

## Email suppression readiness

The new internal read-only check can verify the configured Klaviyo account and an already linked profile's current email-marketing state. It requires the existing private key to have `accounts:read` and `profiles:read`, an explicit account/environment mapping, a matching profile/email, and current local email consent. Missing or ambiguous information blocks the check. Any global or list suppression and any absent opt-in block marketing eligibility.

There is no new operator button or automatic background check yet. Configuration and connection testing do not activate it. A developer can run the explicit action only against an authorized destination; this increment was tested with synthetic responses, without querying live accounts.

A successful check lasts no more than five minutes for an internal preview. Changes to credentials, account mapping, identity or local consent invalidate eligibility; a newer unfinished or failed check blocks an older successful one. Previews still show delivery disabled and never send messages, subscribe profiles or activate flows. Existing workflow behavior has not been changed by this increment.
