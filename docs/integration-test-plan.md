# WHMCS Integration Test Plan

This plan covers end-to-end testing of normal plugin updates, grouped multi-component updates, rollback, crash recovery, and self-update. Run it only in a disposable, private WHMCS installation. It is not a production test procedure.

## Status and prerequisites

The integration test is intentionally deferred until the required licensed test environment is available. Unit tests remain available with:

```shell
php -d zend.assertions=1 -d assert.exception=1 tests/run.php
```

Before starting, provide:

- A WHMCS development/test licence valid for the test hostname, public egress IP, and installation path.
- Local WHMCS release archives for each supported version under test (initially WHMCS 8.13 and 9.0).
- A dedicated GitHub fixture repository whose Releases may be created and deleted during testing.
- If the fixture repository is private, a fine-grained GitHub token with the minimum permissions required to read releases and assets. Release publishing credentials belong in the test runner, not WHMCS.

Do not paste a licence or token into chat, commit it, bake it into a container image, or place it under the web root. Supply secrets at runtime using mode-`0600` files or container secrets. Keep WHMCS archives and secrets in ignored local storage outside the repository, and destroy the environment and its secrets when testing is complete.

## Test environment

Use a reproducible disposable container or virtual-machine environment containing:

- Apache or nginx with PHP 8.3, ionCube Loader, cURL, JSON, POSIX, and ZIP.
- MariaDB or MySQL with a clean snapshot that can be restored between scenarios.
- The normal WHMCS web and cron entry points.
- HTTPS and outbound access to the WHMCS licensing service and GitHub.
- HTTP authentication or equivalent network access controls so the development installation is not publicly accessible.
- PHP OPcache enabled for self-update testing.

Use separate paths and filesystem volumes:

```text
/var/www/whmcs                  # WHMCS installation and web root
/var/lib/whmcs-plugin-updater   # staging, backups, journals, recovery tool
```

The update storage path must resolve outside both the document root and WHMCS installation. It should have mode `0700`. The PHP web-server OS user must own the managed module directories, their parent directories, and the update storage path. Install fixtures into container-managed volumes rather than host bind mounts whose numeric ownership may differ.

Record the following before every run:

- WHMCS and PHP versions.
- Container image or VM revision.
- Web-server UID and GID, target-directory owners, and storage-directory owner.
- Licensed hostname, installation path, and observed outbound IP (never the licence value).
- Initial database snapshot identifier.
- Fixture release tags and asset SHA-256 hashes.

Exercise the admin web request and cron separately because their execution environment and observed licensing network path may differ.

## GitHub release fixtures

Build minimal modules with visible version markers and deterministic file contents. Avoid real customer or production plugin code.

Prepare these releases in the dedicated repository:

1. `v1.0.0`: baseline addon installed manually.
2. `v1.1.0`: valid addon update, including a changed file, a new file, and omission of one old file.
3. `v1.2.0`: valid grouped package containing independent addon and server manifest roots.
4. A valid release for the updater itself, with an obvious version/UI marker.
5. Negative fixtures as required: malformed ZIP, path traversal entry, symlink entry, unexpected manifest, mismatched component versions, missing asset, and incorrect digest.

Release archives may contain a wrapper directory. Each component's `whmcs.update-manifest.json` defines its installation root. Add sentinel files above every manifest root; they must never appear in WHMCS after installation.

Capture a sorted SHA-256 inventory of each component tree at every version. These inventories are the source of truth for update and rollback assertions.

## Baseline setup

For each supported WHMCS version:

1. Restore a clean database and filesystem snapshot.
2. Install WHMCS using the runtime-supplied development licence.
3. Set the correct System URL and configure cron.
4. Install and activate Plugin Updater.
5. Configure the external update storage path and optional read-only GitHub token.
6. Install fixture version `v1.0.0` manually.
7. Confirm WHMCS admin, client area, cron, and the updater page load successfully.
8. Record database and component filesystem baselines.

## Test cases

### 1. Discovery and presentation

- Run **Check Now** and verify the available version, release date, and GitHub release notes.
- Verify the dashboard tile reports cached update availability without making a GitHub request.
- Run the daily cron repeatedly and verify a successful repository check is not repeated before the weekly interval.
- Force retryable GitHub failures and verify persisted strict exponential backoff prevents requests before `next_attempt_at` and caps at the configured maximum.
- Verify an invalid or insufficient GitHub token produces a safe diagnostic and is never rendered or logged.

### 2. Preflight and normal update

- Verify the UI requires explicit confirmation that a current database backup exists.
- With a non-zero `max_execution_time`, verify the updater attempts `set_time_limit(120)`. If it cannot change the limit, verify preflight warns before any transaction begins.
- Verify preflight rejects storage inside the web root or WHMCS installation.
- Verify preflight rejects mismatched target/parent ownership, unwritable targets, and insufficient free space before any component changes.
- Update `v1.0.0` to `v1.1.0` with Maintenance Mode enabled.
- While the swap is active, make concurrent client-area requests and verify WHMCS presents maintenance behavior. Confirm the admin path remains usable as WHMCS permits.
- Verify the updated component exactly matches the `v1.1.0` hash inventory: changed and new files are present, the omitted stale file is gone, and archive sentinels above the manifest root were not installed.
- Verify the original Maintenance Mode state is restored, the transaction is logged without secrets, and exactly one verified pre-upgrade copy is retained externally.

Repeat once with Maintenance Mode disabled, and once with WHMCS already in Maintenance Mode to prove the prior state is preserved.

### 3. Grouped multi-component update

- Install the addon and server fixtures with independent manifests sharing one package identity.
- Update to `v1.2.0` and verify both preflights complete before either live component is changed.
- Verify both component destinations exactly match their expected inventories.
- Make the second component fail its preflight and confirm neither component changes.
- Verify files from one manifest root cannot escape into or overwrite the other component.

### 4. Rollback

- Roll back a successful normal update from the admin interface.
- Verify the old component inventory is restored exactly and the newer tree is no longer active.
- Verify WHMCS admin, client area, hooks, and cron load after rollback.
- Apply two sequential updates and verify retention keeps only the single configured previous version.
- If a fixture performs a database migration, verify the UI and log clearly state that file rollback does not reverse database changes.

### 5. Interrupted transaction and external recovery

Do not add a production fault-injection setting. Instead, observe the transaction journal/filesystem and terminate the PHP worker immediately after the first live-directory rename.

- Confirm the request terminates without completing its cleanup path.
- Run the external recovery command from the configured storage path:

  ```shell
  php /var/lib/whmcs-plugin-updater/recover.php --latest
  ```

- Verify the original component inventory is restored and the journal records recovery.
- Verify WHMCS loads afterward and follow any reported instruction to restore Maintenance Mode manually.
- Repeat during a grouped update after the first component swaps but before the second completes; recovery must restore a consistent pre-update state for all affected components.

### 6. Self-update

Publish the updater fixture as a newer release in the sandbox repository and configure the updater's own installed manifest to use it.

- Start with the older updater version and confirm its version marker.
- Update it through its own admin interface with OPcache enabled.
- Allow the initiating request to finish, then use a fresh PHP/web request to verify the new version is loaded.
- Verify the updater page, hooks, dashboard widget, cron check, and external recovery command still work.
- Verify its pre-upgrade copy exists outside the web root.
- Roll back through the new UI and verify the exact old updater inventory and version marker are restored.
- Repeat the interrupted-transaction recovery case while the updater is updating itself.

### 7. Archive and trust-boundary failures

For every negative release fixture, confirm validation fails before a live rename and leaves the current component, Maintenance Mode, database, and retained rollback copy unchanged.

At minimum cover:

- Missing or ambiguous release asset.
- Missing, malformed, duplicate, or inconsistent manifests.
- GitHub digest mismatch.
- Absolute paths, `..` traversal, symlinks, and unsupported archive entries.
- A destination outside the allowed module types and names.
- A downgrade or same-version release where an upgrade is expected.
- Network timeout, rate limit, partial download, and truncated archive.

## Completion criteria

The integration suite passes only when:

- Every successful install matches its expected SHA-256 inventory.
- Every rejected or interrupted update leaves or restores a complete known-good version; no mixed component tree remains.
- Update staging, archives, journals, and rollback copies never enter the web root.
- Secrets do not appear in logs, HTML, command output, database diagnostics, or committed files.
- Maintenance Mode always returns to its original state after handled success or failure, with clear manual recovery instructions after an unhandled process death.
- Normal update, grouped update, rollback, crash recovery, and self-update pass on every supported WHMCS version in the matrix.
- The existing unit test suite still passes.

Retain a redacted test report containing the environment record, scenario results, hashes, relevant transaction IDs, and unresolved defects. Do not retain the WHMCS licence, GitHub token, WHMCS distribution, extracted installation, or database snapshot in the repository.
