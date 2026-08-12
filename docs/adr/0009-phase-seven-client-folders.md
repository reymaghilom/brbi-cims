# ADR 0009: Phase 7 Client Folders browsing

## Decision

The normal Client Folders page is an active-record filing-cabinet view built from reusable yellow folder cards. `ClientFolder::accessibleTo(User)` is applied before all search and filtering: an Administrator sees every active folder, while a Credit Investigator sees only folders whose indexed `assigned_ci_id` matches their account. Laravel's default soft-delete scope excludes Recycle Bin records.

Client names and folder numbers are searched in the database. Folder status accepts only `on_progress` or `completed`. Stable server-side sorting supports recently updated (default), recently created and client name. Results use a 12-record length-aware paginator and retain the validated search, status and sort query string across pages.

Assigned investigators are eager-loaded with only the fields needed by the card. The browse service selects only card fields, performs count/page queries in the database and does not load the full folder collection into memory.

## Phase boundary

The existing policy-protected folder detail remains a placeholder for later modules. `+ Create Client Folder` continues to open the Phase 8 placeholder. Phase 7 introduces no persistence, rename, soft-delete, restore, report, activity, media or integration workflows.
