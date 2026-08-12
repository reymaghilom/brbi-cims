# ADR 0011: Phase 9 Client Folder Contents

## Active folder boundary

The normal Client Folder contents route and all module destinations use Laravel's default active-only model binding. The prior `withTrashed` behavior is removed from this route. Recycled folders are visible only in the policy-scoped Recycle Bin and its Administrator lifecycle endpoints.

Every module destination is nested beneath the active folder and independently authorizes `view`. Administrators may open any active folder; a Credit Investigator may open only their assigned folder. Phase 9 destinations are explicit placeholders and contain no later-phase encoding behavior.

## Summary data and progress

`ClientFolderOverview` obtains card information through aggregate relationship counts, conditional counts and maximum update timestamps, plus small one-to-one state records. It does not load report rows, source snapshots, captions, media payloads, attachment contents or integration metadata. Query count is fixed as record volume grows.

`client_folders.progress_percent` remains the canonical cached percentage displayed by the Dashboard, folder grid and folder header. Evaluated active-required `client_completion_results` provide the applicable completed/total explanation and missing-item labels. No results means the folder has not yet been evaluated; the UI does not manufacture missing requirements or completion data. No weighting is introduced.

## Recent history

The folder page shows only the eight most recent audit descriptions, actors and timestamps. Metadata is deliberately excluded from the select and from presentation, preventing sensitive audit payloads from leaking into the folder overview.
