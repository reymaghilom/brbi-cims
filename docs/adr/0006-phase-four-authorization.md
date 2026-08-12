# ADR 0006: Phase 4 roles and authorization

Status: Implemented for review

BRBI CIMS recognizes only `administrator` and `credit_investigator`. It retains one `assigned_ci_id` on each client folder; no collaboration/access pivot table is introduced.

Authorization is implemented with explicitly registered Laravel policies. Folder-backed resources share the same invariant: an Administrator can access the resource, while a Credit Investigator can access it only when the root folder is assigned to that user. Client-folder permanent deletion, user management, system settings and Audit Trail remain Administrator-only. Route middleware enforces authentication, active/current session state, required password change and Administrator role where applicable. Controllers and Form Requests independently invoke policies for protected operations.

Administrator user management does not expose a delete operation or existing passwords. Created users are Active and must change the Administrator-selected temporary password. Disabling an account preserves foreign-key history while rotating remember credentials, incrementing its authentication-session version and deleting database sessions. Role changes also invalidate sessions. Administrator password reset reuses the Phase 3 audited action and displays only the newly generated temporary password once.

Nested folder/income-source routes use scoped binding before policy authorization. This prevents a user from combining an accessible parent folder with a child ID owned by another folder. Folder list queries are also scoped so inaccessible names are not rendered.

Phase 4 requires no database migration; the Phase 2 ownership fields and Phase 3 authentication-session fields already support the complete permission model.
