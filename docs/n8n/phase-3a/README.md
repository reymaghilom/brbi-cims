# BRBI CIMS — Phase 3A: isolated HTTPS ingress (preflight only)

Status: **read-only preflight COMPLETE. Nothing on the VM, Caddy, n8n, PisoWiFi, Google Cloud or
Laravel was changed.** Date: 2026-10-26. Any live Caddy change needs separate explicit approval.

Files: `Caddyfile.brbi-cims.block` (the new site block), `Caddyfile.PROPOSED.diff` (append-only diff
against the live file), `laravel-outbox-feed-pass.PROPOSED.diff` (proposed local Laravel change,
not applied).

## Preflight results

| # | Item | Result | Evidence |
|---|---|---|---|
| 1 | Identity | PASS | Account `crisbasco04@gmail.com`; project `project-d54d0392-1941-4432-bd6` (#447687567900, ACTIVE); VM `n8n-zerotier-pisowifi-monitoring`, instance ID `2687189898573408744`, `asia-southeast1-c`, RUNNING, e2-medium, `34.158.53.160` |
| 2 | Effective Caddy config | PASS | Caddy 2.6.2, `caddy run --config /etc/caddy/Caddyfile`, admin `localhost:2019`, h1/h2 only, **no imports**. Live file sha256 `c90b30e5…e444c` (2026-10-18). Admin-API config = Caddyfile: `pesowifi.duckdns.org` (`/api/v1*` → `127.0.0.1:4000`, else → `127.0.0.1:3000`, 1 MB), `gw.pesowifi.duckdns.org` (→ gateway socket, 1 MiB). **No n8n endpoint exposed** (`5678` not referenced) |
| 3 | Health | PASS | caddy, pisowifi-api/-gateway/-web-staging/-worker, zerotier-one, n8n (+ socket, egress proxy, netguard) active, 0 restarts, 0 error journal lines in 6 h; n8n `/healthz` ok. Public: `pesowifi.duckdns.org/` 200, `gw.pesowifi.duckdns.org/` 401 (auth required, expected), valid TLS |
| 4 | DNS options | PASS | DuckDNS resolves any sub-name of `pesowifi.duckdns.org` to the VM (`brbi-cims.pesowifi.duckdns.org` → `34.158.53.160`; HTTPS fails today as expected, because no site is configured). Alternative: a separate DuckDNS name (operator account action) or a BRBI-owned domain (A record). TLS: Caddy auto-issues on first request (HTTP-01/TLS-ALPN-01 over the already-open 80/443) |
| 5 | Minimal config | PASS (prepared) | `brbi-cims.pesowifi.duckdns.org`: `request_body max_size 16KiB` (16384 B, the Workflow A limit), `@reminder_events = method POST + path /webhook/brbi-cims/reminder-events` → `127.0.0.1:5678`, same forwarding-header stripping and timeouts style as the PisoWiFi blocks, own access log with `X-Cims-Signature`/`Idempotency-Key`/auth headers deleted |
| 6 | Everything else closed | PASS (by construction) | `handle { respond 404 }` for every other method/path on the new host: editor `/`, `/rest/*`, `/webhook-test/*`, other `/webhook/*`, `/healthz`, any GET. Existing hosts unchanged |
| 7 | Backup/validate/reload/rollback | PASS (prepared) | See procedure below. `caddy adapt` of the proposed file: exit 0. The `gw` route is identical. The `pesowifi` route differs **only** in Caddy's auto-generated handle-group label (`group3` → `group7`, mutual-exclusion label, no behaviour change). Existing loggers `log0`/`log1` unchanged, new `log2` |
| 8 | InfinityFree outbound check | Method prepared | See below. Requires a file on the live host (approval) |
| 9 | Laravel retry | Assessed + patch proposed | See below |
| 10 | Diff / impact / plan | PASS | Below |

Socket reachability: `n8n-ingress.socket` `IPAddressAllow=localhost` (any local process, including
the `caddy` user, may connect). nftables `n8n_guard` restricts only **outbound** traffic of uid 988
(n8n). **No netguard change is needed.**

## Service impact assessment

- **Change:** one appended site block (54 lines). 0 existing lines modified.
- **Reload:** `systemctl reload caddy` is graceful (config swap through the admin API). Existing
  connections continue and the PisoWiFi sites keep identical routes, limits, headers and logs.
- **New certificate:** one ACME order for the new host on first request. Failure affects only the new host.
- **n8n:** receives requests only on that one path. While Workflow A is **inactive**, n8n answers
  404 "webhook not registered". Nothing executes until Workflow A is activated (separate approval).
- **Risk:** a Caddyfile error would block the reload. Caddy keeps running the old config and
  `systemctl reload` fails, so validate first. Rollback = restore the backup and reload.

## Deployment procedure (NOT executed; each run needs approval)

```bash
# 0. Precheck: live file is still the reviewed one
sudo sha256sum /etc/caddy/Caddyfile        # must be c90b30e5d741a3c6a6f37d209161f310cf63dfe97410ce698a1f8856ee3e444c
# 1. Backup
sudo cp -a /etc/caddy/Caddyfile /etc/caddy/Caddyfile.pre-brbi-cims.bak
# 2. Stage the reviewed file (sha256 a3957fc9611420022d9112d6edc07eea7754440b495c3a7c2f40d0c7c0ebf88f)
sudo install -m 0644 -o root -g root /tmp/Caddyfile.brbi /etc/caddy/Caddyfile.new
sudo caddy validate --config /etc/caddy/Caddyfile.new --adapter caddyfile   # must succeed
# 3. Swap + graceful reload
sudo mv /etc/caddy/Caddyfile.new /etc/caddy/Caddyfile && sudo systemctl reload caddy
systemctl is-active caddy; journalctl -u caddy --since -2min -p warning --no-pager
# 4. Verify (from any PC)
curl -s -o /dev/null -w '%{http_code}\n' https://pesowifi.duckdns.org/                 # 200 (unchanged)
curl -s -o /dev/null -w '%{http_code}\n' https://gw.pesowifi.duckdns.org/              # 401 (unchanged)
curl -s -o /dev/null -w '%{http_code}\n' https://brbi-cims.pesowifi.duckdns.org/       # 404
curl -s -o /dev/null -w '%{http_code}\n' https://brbi-cims.pesowifi.duckdns.org/rest/settings            # 404
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://brbi-cims.pesowifi.duckdns.org/webhook-test/brbi-cims/reminder-events  # 404
curl -s -o /dev/null -w '%{http_code}\n' https://brbi-cims.pesowifi.duckdns.org/webhook/brbi-cims/reminder-events               # 404 (GET)
curl -s -w '\n%{http_code}\n' -X POST -H 'Content-Type: application/json' -d '{}' https://brbi-cims.pesowifi.duckdns.org/webhook/brbi-cims/reminder-events  # 404 "not registered" (A inactive)
head -c 17000 /dev/zero | curl -s -o /dev/null -w '%{http_code}\n' -X POST --data-binary @- https://brbi-cims.pesowifi.duckdns.org/webhook/brbi-cims/reminder-events  # 413 (may show 404 while A is inactive, because n8n can answer before reading the body; re-check after activation)
# Rollback (if anything is off)
sudo cp -a /etc/caddy/Caddyfile.pre-brbi-cims.bak /etc/caddy/Caddyfile && sudo caddy validate --config /etc/caddy/Caddyfile && sudo systemctl reload caddy
```

## InfinityFree outbound HTTPS verification (after the route exists; approval required)

Upload a one-off `n8n-reach-check.php` (random file name) to the live site. It POSTs `{}` to
`https://brbi-cims.pesowifi.duckdns.org/webhook/brbi-cims/reminder-events` with cURL (5 s timeout,
TLS verification on) and prints only the HTTP status and cURL error code: no secret, no data, no
Laravel bootstrap. **Expected: 404** (route reachable, Workflow A inactive). `0` + cURL error means
blocked or TLS failure. Delete the file right after. Workflow A stays inactive, so nothing is
ingested.

## Laravel retry assessment (item 9)

Existing: after-commit delivery of the saving request's own event (opt-in). The dispatcher has
row-locked claims, ID order, superseded-version skipping (a cancellation supersedes a pending
reschedule of the same subject), any non-2xx retried with backoff `2^(n-1)` min capped at 360, and
`failed` after `max_attempts` (default 10, about **8.5 h** of retries). `failed` can be re-queued
only by the CLI (`--retry-failed`), which InfinityFree lacks.

Minimum safe local changes (proposed, `laravel-outbox-feed-pass.PROPOSED.diff`, not applied):
1. A bounded outbox pass in `CiActivityNotificationFeedController` (the existing no-cron fallback
   place): global `Cache::add` 1-minute guard, `deliverPending(2)`, try/catch + log. It runs for any
   signed-in bell poll. With 6 feature tests.
2. Production `N8N_REMINDERS_MAX_ATTEMPTS=30` (about 5.3 days of retries before `failed`).
Residual: if nobody is signed in, pending events wait for the next session.

## Step-by-step plan

1. Approve the hostname (default `brbi-cims.pesowifi.duckdns.org`) and **the Caddy change** → apply the procedure above.
2. InfinityFree outbound probe (approval) → expect 404.
3. Approve the Laravel patch → implement locally + tests (no deploy).
4. Workflow A: `ALLOWED_ACTIVITIES_ORIGIN` = production `APP_URL` origin; new "CIMS Reminder HMAC (production)" credential; cancel synthetic `920001` (signed v4) first.
5. Activate Workflow A → unsigned POST must return 401, other paths 404.
6. Production Laravel deploy: code, 2 migrations, `.env` (`N8N_REMINDERS_ENABLED=true`, URL, new secret, `DISPATCH_AFTER_COMMIT=true`, `MAX_ATTEMPTS=30`).
7. Controlled end-to-end test (create, then cancel), then activate Workflow B with the gate approved.

## Phase 3C — deployment result (2026-10-26) — PASS, no rollback

Executed under explicit operator approval as one gated session on the VM. Each gate would have
stopped without changes, with automatic restore + graceful reload on a critical post-check failure.

| Step | Result |
|---|---|
| Identity (GCE metadata) | id `2687189898573408744`, `n8n-zerotier-pisowifi-monitoring`, `project-d54d0392-1941-4432-bd6` |
| Hashes | live before `c90b30e5…e444c` (= reviewed), candidate `a3957fc9…bf88f` (= reviewed) |
| Backup | `/etc/caddy/Caddyfile.pre-brbi-cims.bak`, sha `c90b30e5…e444c` |
| `caddy validate` | Valid configuration |
| Install | `/etc/caddy/Caddyfile` → **sha `a3957fc9611420022d9112d6edc07eea7754440b495c3a7c2f40d0c7c0ebf88f`**, `root:root 644` |
| Reload | `systemctl reload caddy` at 07:07:13Z, **graceful**: Caddy MainPID 155996 and start time (2026-10-18 10:42:50Z) unchanged; 0 error lines |
| Health before = after | caddy, pisowifi-api/-gateway/-web-staging/-worker, zerotier-one, n8n-egress-proxy, n8n-netguard, n8n-ingress.socket, n8n all active, 0 restarts; n8n `/healthz` ok; `pesowifi.duckdns.org/` **200**, `gw.pesowifi.duckdns.org/` **401** (loopback and public) |
| TLS | Let's Encrypt `YE2`, `CN=brbi-cims.pesowifi.duckdns.org` (SAN matches), valid to 2027-01-24, trusted (verify 0). HTTP → HTTPS 308 |
| Restriction | **404** for `/`, `/signin`, `/rest/settings`, `POST /rest/login`, `/healthz`, `POST /webhook-test/brbi-cims/reminder-events`, `POST /webhook/other-hook`, trailing slash, `GET`/`PUT` on the path, `/api/v1/`. **Only `POST /webhook/brbi-cims/reminder-events` reaches n8n**: 404 "webhook POST brbi-cims/reminder-events is not registered" (Workflow A inactive, as intended) |
| 16 KiB | 16,000-byte POST forwarded (n8n 404); **17,000-byte POST rejected by Caddy (HTTP 502, empty body, never answered by n8n)**. Caddy 2.6.2 reports a request-body-limit hit inside `reverse_proxy` as 502, not 413. The limit is enforced; only the status code differs from the plan |
| Headers | `Strict-Transport-Security: max-age=31536000`, `X-Content-Type-Options: nosniff`; no `Server` / `X-Powered-By` |
| Observed | Internet scanners probed the new host within seconds (`/wp-json/`, `/xmlrpc.php`): all 404 |

Rollback: **not required**. The backup is kept at `/etc/caddy/Caddyfile.pre-brbi-cims.bak` (rollback
command in the procedure above).
