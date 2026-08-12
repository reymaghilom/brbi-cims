# BRBI CIMS authorization matrix

Status: Implemented through Phase 4

All application routes require authentication, a current active session and completion of any required password change. Administrator routes additionally require the `administrator` role and their controller/action policy. Hiding navigation is supplementary and is never the authorization control.

| Resource / ability | Administrator | Assigned Credit Investigator | Other Credit Investigator |
|---|---:|---:|---:|
| Active client folder: view/update/soft delete | Allow | Allow | Deny |
| Deleted client folder: view | Allow | Allow when assigned | Deny |
| Client folder: create | Allow | Allow | Allow |
| Client folder: restore/permanent delete | Allow | Deny | Deny |
| Client information and CI/BI report | Allow | View/update | Deny |
| Business / income source | Allow | View/update/delete/generate/export | Deny |
| Residence/business report | Allow | View/update/generate/export | Deny |
| CI activity | Allow | View/update | Deny |
| Media reference | Allow | View/update/delete | Deny |
| Generated report | Allow | View/generate/export | Deny |
| Telegram message | Allow | View/update for assigned folder | Deny |
| User management | Allow | Deny | Deny |
| Settings | Allow | Deny | Deny |
| Audit Trail | Allow | Deny | Deny |

User accounts are never deleted by Phase 4. An Administrator may create and update accounts, assign either approved role, activate/disable another account and reset another user's password. Administrators cannot disable themselves, demote themselves or reset their own password through user management. Password reset and account disable invalidate sessions and remember credentials. Role changes also invalidate sessions so old authorization does not persist.

Folder-backed policies authorize against the stable `client_folder_id`. Nested folder/income-source routes use scoped route-model binding in addition to policies, so an otherwise accessible child ID cannot be forged beneath a different parent folder.
