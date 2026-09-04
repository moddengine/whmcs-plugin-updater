# Integration harness

This directory contains the disposable WHMCS test environment described in [`docs/integration-test-plan.md`](../docs/integration-test-plan.md). It binds HTTP to loopback only and keeps the proprietary WHMCS distribution, licence, database, installed files, staging files, and rollback copies outside Git.

The expected local inputs are:

```text
whmcs-dist/whmcs_v8137_full.zip
whmcs-dist/whmcs_v908_full.zip
whmcs-dist/licence.key
```

Do not commit either file. The top-level `.gitignore` excludes the entire directory.

WHMCS is installed non-interactively with JSON written directly to the official installer's standard input. The mounted licence is never passed in a process argument or printed by the harness.

Run the complete suite against WHMCS 8.13.7 and 9.0.8 with:

```shell
integration/run.sh
```

Each version starts with fresh database, WHMCS, staging, and rollback volumes. The final version's stack is retained for diagnosis. To run only one version:

```shell
WHMCS_VERSIONS=9.0.8 integration/run.sh
```

To stop the retained containers without deleting their named volumes:

```shell
docker compose -f integration/compose.yaml down
```

Record the complete admin-browser update and rollback flow with:

```shell
integration/record-update.sh
```

The videos are written to `integration/artifacts/whmcs-plugin-update-demo-8137.webm` and `integration/artifacts/whmcs-plugin-update-demo-908.webm`. Set `WHMCS_VERSIONS` to record only one version. The test uses a local HTTPS server under the `api.github.com` Docker alias, installs its disposable CA only in the test web container, and exercises the production GitHub client without using the public network.

The runner refuses a licence file that is not mode `0400` or `0600`, verifies that `whmcs-dist` is ignored by Git, binds WHMCS to `127.0.0.1`, and runs update processes as the Apache `www-data` user. Results and remaining coverage are recorded in [`docs/integration-test-results.md`](../docs/integration-test-results.md).
