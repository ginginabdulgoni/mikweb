# MikWeb — Security Review (Phase 6)

MikWeb controls network infrastructure, so security is treated as a primary
feature. This document records the controls in place and the known gaps.

## Controls implemented

| Area | Control |
|------|---------|
| Credential storage | API/SSH/SNMP secrets use Laravel `encrypted` casts (AES-256-GCM via `APP_KEY`). Never stored in plaintext. |
| Credential exposure | Secret columns are `$hidden`; `RouterPresenter` is the only serialization path to UI/API and emits credential *metadata* only (username/configured flag). |
| Log redaction | `SecretRedactor` scrubs `config('mikweb.redacted_keys')` from every connection log, command log, and audit payload (recursively, case/hyphen-insensitive). |
| AuthN | Session auth + Sanctum API tokens. Login is rate-limited (5 failures / email+IP, plus a route throttle). |
| 2FA | TOTP (pragmarx/google2fa) with encrypted secret + single-use recovery codes. Enforced as a second login step. |
| AuthZ | `RouterAccess` centralises every check: super-admin → org scope → role permission → per-router grant. Policies + gates enforce it server-side on every request; UI gating is advisory only. |
| Multi-tenancy | Every router query is scoped by `organization_id`; cross-org access returns 403. |
| Audit | All config-changing actions + tests write immutable `audit_logs` (actor, IP, UA, redacted old/new values). |
| CSRF | Laravel web middleware; Inertia sends the XSRF token. |
| Input validation | FormRequests / `$request->validate()` on every mutation; RouterOS item ids validated to the `*ID` shape. |
| Command injection | No shell execution for device I/O — all commands go through the typed RouterOS API client (`Query` builder), never string interpolation into a shell. |
| Transport security | API-SSL supported with configurable peer verification; host/cipher options in `ConnectionConfig`. |
| Resilience | Per-router circuit breaker + retry + write lock prevent a compromised/flapping device from being hammered. |

## Risk notes & recommended hardening before production

1. **TLS verification defaults.** `verify_tls` defaults on, but RouterOS ships
   anonymous-DH certs unless a cert is installed; when `verify_tls=false` MikWeb
   permits ADH ciphers. Install real certs on devices and keep verification on.
2. **SSH host-key verification.** The schema stores `ssh_host_fingerprint`; the
   SSH gateway/terminal (Phase 3 remainder) must enforce it (TOFU → pinned).
3. **Risky device operations** (reboot/reset/upgrade, management-access changes)
   must require re-confirmation + a pre-change snapshot. The backup/export
   capability exists; wire a mandatory snapshot into those flows when built.
4. **Automation secrets** (webhook URLs, Telegram tokens) are stored in
   `action_params` JSON — move these to encrypted columns before storing
   production tokens.
5. **SSRF.** Automation webhooks and (future) REST transport POST to
   operator-supplied URLs. Add an allowlist / block RFC-1918 + metadata IPs for
   webhook targets in a hardening pass.
6. **2FA enforcement policy.** 2FA is available per-user; add an org policy to
   *require* it for admin roles.
7. **Rotate `APP_KEY` procedure.** Document re-encryption of stored credentials
   if the app key is ever rotated.

## Reporting
Run `php artisan test` (security/authorization suite) and the project's
`/security-review` before each release.
