# ADR 0008: Phase 6 policy-scoped Dashboard

Status: Implemented for review

The Dashboard is an operational view of active authorized client folders. Deleted folders remain available through the Recycle Bin and are not included in Dashboard totals, status counts, recent folder cards, completed generated-report counts or recent activities.

`ClientFolder::accessibleTo(User)` is the shared query scope. It leaves the active-folder query unrestricted for an Administrator and adds the indexed `assigned_ci_id` condition for a Credit Investigator. Generated-report and activity queries apply the same scope through their client-folder relationship, so counts and rendered details use one ownership rule.

Folder totals are calculated with one aggregate query. Completed generated reports use one scoped count. Recent folders and recent activities are limited and eager-load only the assigned investigator, folder and updater fields required for presentation. The number of Dashboard queries is constant as accessible records grow, avoiding N+1 behavior.

The Dashboard shows only the approved Total Client Folders, On Progress, Completed and Reports Generated summaries, recent yellow folder cards, recent CI activity and a Create Client Folder shortcut. The shortcut leads to a policy-protected Phase 8 placeholder; it does not create or persist a folder.

Approval queues, approval states, field-work calendars, tasks, loan approval statistics, CRM, sales and inventory widgets are intentionally absent.
