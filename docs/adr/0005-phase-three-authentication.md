# ADR 0005: Phase 3 authentication

Status: Implemented for review

BRBI CIMS uses Laravel's session guard with a private username login and no registration or public password-recovery routes. Login attempts are limited to five per normalized username and IP address. Successful login regenerates the session and records login metadata; logout invalidates the session and regenerates the CSRF token.

Passwords use Laravel's one-way hashed cast and a minimum length of 12 characters when created or changed. The first Administrator is provisioned only through the interactive `php artisan cims:create-admin` command. The command refuses non-interactive execution, duplicate usernames and a second initial Administrator, and its audit record contains account metadata but no password.

Administrator password resets are represented by a Phase 3 application action. The action accepts or generates a temporary password, sets `must_change_password`, clears `password_changed_at`, rotates the remember token, increments `auth_session_version`, removes database-backed sessions and writes a password-free audit event. Existing sessions on any supported session driver are rejected when their stored version no longer matches. Phase 4 now supplies the policy-protected Administrator screen and route that invoke this action.

Users with `must_change_password` may access only logout and the required password-change flow. A successful change verifies the current temporary password, requires confirmation and at least 12 characters, clears the flag, rotates all session credentials and keeps only the newly regenerated current session.
