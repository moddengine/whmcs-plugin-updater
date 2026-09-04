# Integration Test Results

## 2026-09-04 — WHMCS 8.13.7 and 9.0.8

Environment:

- WHMCS 8.13.7 release 1 and WHMCS 9.0.8
- PHP 8.3.33 with ionCube Loader, ZIP, POSIX, and OPcache
- MariaDB 10.6
- Apache in a loopback-only Docker Compose environment
- PHP/web worker user `www-data`; WHMCS target and external storage paths owned by the same user
- External update storage at `/var/lib/whmcs-plugin-updater`, outside `/var/www/html`

Passed automated checks:

- Licensed WHMCS CLI and web bootstrap.
- Addon activation and updater cache-table creation.
- Rejection of storage under the web/WHMCS root.
- Rejection of storage permissions other than `0700` and mismatched ownership.
- Rejection of a target owned by a different OS user.
- Increase of a mutable 60-second PHP execution limit to 120 seconds.
- Warning, rather than failure, when `set_time_limit` is disabled.
- Persisted one-hour, two-hour exponential GitHub retry delays and early-retry rejection.
- Seven-day successful-check caching, cached release selection, dashboard update count, escaped release notes, and database-backup confirmation rendering.
- Normal addon update with complete-tree replacement and stale-file removal.
- Ignoring files above the release manifest root.
- WHMCS Maintenance Mode enable/restore through the real local API.
- Retention of one verified rollback copy and exact rollback to the old tree.
- Atomic grouped addon/server update with independent manifest roots and rollback.
- Forced PHP process death during a 20-component rename sequence.
- Standalone external recovery of every interrupted component without a mixed tree.
- Recovery of a newly added component when termination occurs after its live rename but before the journal update.
- Rejection of an occupied unmanaged destination for a newly declared component.
- Atomic replacement and verification of the standalone recovery utility before operations.
- Self-update from 0.0.1 to 0.0.2 with OPcache enabled.
- Loading self-updated code in a fresh PHP/WHMCS process and rolling back to 0.0.1.
- Playwright-driven WHMCS admin login, addon activation, release check, expanded release notes, pre-flight confirmations, complete-tree update from 1.0.0 to 1.1.0, and rollback to 1.0.0 on both WHMCS versions.
- Native WebM recording of the browser flow against a deterministic HTTPS GitHub API/asset mock, including certificate validation by the production cURL client.
- Existing unit checks for manifest validation, unsafe ZIP paths, symlinks, transactions, and recovery.

Defect found and fixed:

- `Transaction::executionTimeWarnings()` called `set_time_limit()` unconditionally. PHP removes disabled functions from the function table, so a hardened host could receive a fatal undefined-function error instead of a preflight warning. The call is now guarded with `function_exists()` and covered by a separate disabled-function process check.
- Recovery previously relied on the journal's `swapped` flag for a new component. Process death between the live rename and journal write could leave that component installed; recovery now infers the completed rename from the destination and missing deployment path.
- Storage mode `0770` was accepted despite the documented `0700` requirement, and the external recovery utility was installed only once. Storage now requires owner-only mode and ownership, while the recovery utility is atomically refreshed and hash-verified before operations.
- Restarting the web container immediately after startup could interrupt extraction of the larger WHMCS 9 distribution. The harness now waits for an entrypoint completion marker.

Not yet covered:

- A real GitHub Releases repository, asset redirect, API token, rate-limit headers, and weekly cron timing. The deterministic browser harness covers release and asset requests through the production `GitHubClient`, including an ETag response.
- Concurrent client-area requests while Maintenance Mode is active.
- Filesystem exhaustion and an actual kernel-level permission change between preflight and rename.

These remaining cases require either another proprietary WHMCS distribution, a dedicated GitHub fixture repository, or fault-environment setup. They are not prerequisites for rerunning the completed filesystem transaction, browser, and self-update coverage.
