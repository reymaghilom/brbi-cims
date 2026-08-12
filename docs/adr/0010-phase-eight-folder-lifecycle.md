# ADR 0010: Phase 8 Client Folder lifecycle

## Creation and numbering

Folder creation is a transaction containing assignment, yearly number allocation, the folder record, active CI Activity initialization and its audit event. Credit Investigators cannot submit an assignment and are assigned automatically. Administrators must choose an active `credit_investigator` account.

`FolderNumberGenerator` inserts the year row if absent, locks it for update, increments monotonically and checks active plus recycled folder numbers before returning `BRBI-CI-{year}-{sequence}`. The year follows the configured Asia/Manila business timezone; timestamps remain UTC.

## Rename and Recycle Bin

Rename changes only `display_name` and normal timestamps. Stable identity, folder number, ownership, client name identity columns and children remain intact. Recycle records `deleted_by` and uses Laravel soft deletion, so normal Dashboard and Client Folder queries exclude it without deleting children.

The Recycle Bin uses `onlyTrashed()->accessibleTo(User)`. Administrators see all recycled folders. Credit Investigators see only assigned recycled folders and cannot restore or purge them. Administrator restore reuses the same database ID and folder number and clears `deleted_by`.

## Permanent deletion boundary

Administrator purge accepts only an already recycled folder and requires its exact folder number as typed confirmation. Database-dependent records use the approved cascade rules. A pre-purge audit record survives with a nullable folder reference and minimal identity metadata.

Folders with media, attachments, generated reports, Google Drive references or Telegram messages are not purged in Phase 8. Physical and remote deletion requires the later storage/integration cleanup policies; retaining the recycled database record is safer than deleting it while leaving uncontrolled external artifacts.
