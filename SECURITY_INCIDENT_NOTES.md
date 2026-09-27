# Security Incident Notes — OTP_PEPPER Task Credential Exposure

**Date:** 2026-09-22  
**Severity:** High  
**Status:** Partially remediated (local disk cleared; credential rotation REQUIRED by operator)

## What Was Exposed

During the OTP_PEPPER security fix review session on 2026-09-22, a credential-scanning
command was constructed that hardcoded real production credential fragments directly
into a PowerShell array literal (`$secrets = @(...)`). When the command was executed,
the shell echoed the full command string — including a complete 32-character database
password, a DB hostname, a partial SMTP app password, a partial payment gateway salt,
and partial AI provider API key prefixes — into the chat session transcript.

Five credential categories were exposed:

- **DB_PASSWORD** — Railway MySQL database password (full value echoed)
- **MAIL_PASSWORD** — Gmail App Password (full value echoed)
- **SALT** — PayU/Easebuzz payment gateway merchant salt (partial value echoed)
- **GEMINI_API_KEY** — Google AI Studio API key (partial prefix echoed)
- **OPENAI_API_KEY** — OpenAI project-scoped key (partial prefix echoed)

## How It Happened

The scanning method used hardcoded known-secret fragments typed manually into a
PowerShell command, rather than loading values from `.env` at runtime. This is a
methodology error: the act of constructing the scanner created a secondary exposure
of the very secrets it was trying to detect.

This same scan also **failed to detect** 21 `scratch/` files containing real `.env`
credential values, because the hardcoded fragment list was incomplete. A subsequent
safe scan (Option B — `.env`-sourced, boolean-only output) found and deleted these
21 files.

## Blast Radius

- **Chat transcript:** The full PowerShell command with embedded credential fragments
  was logged in the chat session transcript. This is the primary exposure surface.
- **Shell history:** Confirmed NOT present. The agent runner uses a non-interactive
  PowerShell process that does not write to PSReadLine's `ConsoleHost_history.txt`
  (last-modified timestamp on that file predates this session by 16 days; search for
  the command signature returned 0 matches).
- **Local disk (scratch/):** 21 files containing real `.env` values were found and
  deleted by the safe re-scan. Zero credential-containing files remain.
- **Git history:** `scratch/` is `.gitignore`'d; no credential-containing files were
  ever committed.

## Required Operator Actions (NOT YET CONFIRMED COMPLETE)

The following must be completed by the operator. None of these can be performed by
the agent — they require dashboard access:

- [ ] Rotate **DB_PASSWORD** via Railway dashboard (regenerate MySQL credentials)
- [ ] Revoke and regenerate **MAIL_PASSWORD** via Google Account → App Passwords
- [ ] Regenerate **SALT** via PayU/Easebuzz Merchant Dashboard → API Keys
- [ ] Delete and regenerate **GEMINI_API_KEY** via Google AI Studio
- [ ] Revoke and regenerate **OPENAI_API_KEY** via OpenAI Platform

Update `.env` locally with each new value after rotation. Do not paste new values
into any chat session or command-line output.

## Remediation Completed by Agent

- 21 `scratch/` files containing real `.env` values deleted (safe scan, boolean output only)
- Shell history confirmed clean (non-interactive runner; no PSReadLine writes)
- Future scratch/ scans must use the `.env`-sourced boolean method (Option B), never
  hardcoded credential fragments in command strings

## Lessons for Future Sessions

1. **Never hardcode real secret values into command strings**, even for the purpose
   of searching for them. Load them from `.env` at runtime and emit only boolean match signals.
2. **Run the safe credential scan before claiming "no secrets in scratch/"** — the first
   scan's "Total deleted: 0" was incorrect due to an incomplete, manually-typed fragment list.
3. **All five credential types in `.env` were real production/live values**, not
   placeholders. Treat every `.env` value as real until explicitly confirmed otherwise.

---

# Security Incident Notes — Production Database Dropped During Test Teardown

**Date:** 2026-09-22
**Severity:** Critical (Data Loss)
**Status:** Remediated (Schema restored, historical attempt data permanently lost)

## What Happened

A `phpunit` test teardown method was incorrectly configured to run `DROP TABLE IF EXISTS` against the live production Railway MySQL database, rather than an isolated test database. This destroyed the `attempts` and `attempt_questions` tables, permanently erasing all historical attempt data up to that point.

## Remediation

1. The missing tables (`attempts` and `attempt_questions`) were recreated using the exact `CREATE TABLE` statements from `schema.sql`.
2. Admin credentials were reconfirmed to regain access.
3. The schema is fully restored, and the admin dashboard candidate fetch endpoint is returning 200 OK again.
4. **Data Loss:** All candidate attempts data prior to 2026-09-22 is permanently unrecoverable.
