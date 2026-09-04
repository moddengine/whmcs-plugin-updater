# WHMCS Plugin Updater

A dependency-free WHMCS addon for checking, staging, updating, and rolling back manifest-enabled addon, server, and registrar modules from GitHub Releases.

## Requirements

- WHMCS 8.13 or 9.0
- PHP 8.3 or newer with cURL, JSON, POSIX, and ZIP
- A normal WHMCS cron configuration
- The PHP web worker must own the managed module directories and their parent directories

## Installation

1. Copy `modules/addons/pluginupdater` into the same path in the WHMCS installation.
2. Create a private update directory outside both the web document root and the WHMCS installation:

   ```shell
   install -d -m 0700 -o WEB_USER -g WEB_GROUP /var/lib/whmcs-plugin-updater
   ```

3. Activate **Plugin Updater** under **System Settings → Addon Modules**.
4. Configure the absolute update storage path and, optionally, a fine-grained GitHub token with read-only Contents access.
5. Grant addon access only to administrator roles allowed to install executable PHP code.

Tagged releases build an installable `whmcs-plugin-updater-<version>.zip` containing the `modules/addons/pluginupdater` tree and a stamped self-update manifest. Push a stable tag such as `v1.2.3`; the GitHub Actions workflow runs QA before publishing the ZIP to a GitHub release.

The storage directory contains downloads, validated extraction trees, the one retained pre-upgrade copy, transaction journals, and a standalone recovery command. It must never be web-accessible.

## Plugin manifest

Every independently installed component has a `whmcs.update-manifest.json` directly in its root:

```json
{
  "schema": 1,
  "package": "acme/example",
  "component": {
    "type": "addon",
    "name": "example"
  },
  "version": "1.4.0",
  "github": {
    "repository": "acme/whmcs-example",
    "asset": "whmcs-example-{version}.zip"
  },
  "requires": {
    "php_min": "8.3.0",
    "whmcs_min": "8.13.0",
    "whmcs_max_exclusive": "10.0.0"
  }
}
```

Release pipelines can generate this file with the Composer development package. The component directory is the only required argument; the command loads `<name>.php`, calls the native WHMCS metadata function, and detects Composer, `whmcs.json`, and GitHub Actions metadata:

```shell
composer require --dev moddengine/whmcs-plugin-updater
vendor/bin/whmcs-plugin-manifest modules/addons/example \
  --whmcs-min 8.13.0 --whmcs-max-exclusive 10.0.0
```

Use `--package`, `--type`, `--version`, `--repository`, `--asset`, `--php-min`, `--whmcs-min`, or `--whmcs-max-exclusive` only when a value cannot be detected. Conflicting stable versions stop the build.

PHP build scripts can call the same API directly:

```php
PluginUpdater\Manifest::generate(__DIR__ . '/modules/addons/example');
```

Supported destinations are calculated from `component.type` and `component.name`:

| Type | Destination |
| --- | --- |
| `addon` | `modules/addons/<name>` |
| `server` | `modules/servers/<name>` |
| `registrar` | `modules/registrars/<name>` |

`package` groups components that must update together. All manifests in a release asset must have the same package, version, repository, and asset template. Tags must be stable `1.2.3` or `v1.2.3` versions, and `{version}` expands without the optional `v` prefix.

The asset can contain an arbitrary wrapper directory. The directory containing each manifest is the source installed at its calculated destination; files above that directory are ignored. Existing component directories are replaced completely, so plugins must keep mutable data outside their module directory.

Example release layout:

```text
example-1.4.0.zip
└── package/
    ├── addon/
    │   ├── whmcs.update-manifest.json
    │   └── ...addon files
    └── server/
        ├── whmcs.update-manifest.json
        └── ...server files
```

## Operation and recovery

The daily WHMCS cron checks each repository no more than weekly. Failed GitHub requests use persisted exponential backoff. The dashboard widget only reads cached results.

Before changing files, an update validates ownership and permissions, downloads and verifies GitHub's SHA-256 digest, safely extracts the ZIP, builds target-adjacent deployment copies, and saves verified pre-upgrade copies under the external storage path. The administrator must confirm a current database backup. Maintenance Mode is enabled only for the final swaps when configured and its original state is restored afterward.

If a fatal plugin update prevents WHMCS from loading, run:

```shell
php /var/lib/whmcs-plugin-updater/recover.php --latest
```

Then verify WHMCS and restore its Maintenance Mode setting in General Settings if the recovery command reports that it may still be enabled.

Rollback restores files only. It cannot undo database migrations run by the updated plugin.

## Security boundary

The installed manifest pins the GitHub repository and asset name. HTTPS, strict archive validation, and GitHub's asset SHA-256 protect transport and file integrity. Version 1 does not independently authenticate the publisher: compromise of the GitHub repository or maintainer account can still publish malicious PHP. Detached publisher signatures are intentionally deferred to a future manifest schema.

## Checks

```shell
nix-shell --run 'composer install && composer qa'
```

This runs the stubbed unit checks and PHPStan with PHP 8.3. Composer dependencies are development-only; the shipped addon remains dependency-free.

Licensed end-to-end coverage, including normal update, rollback, crash recovery, and self-update, is specified in [the integration test plan](docs/integration-test-plan.md).

With local WHMCS 8.13.7 and 9.0.8 distributions and a development licence, run the reusable two-version Docker harness with `integration/run.sh`. See the [harness instructions](integration/README.md) and [latest integration results](docs/integration-test-results.md).

Run `integration/record-update.sh` to generate Playwright videos of the complete WHMCS admin update and rollback flow on both versions against the deterministic HTTPS GitHub mock.
