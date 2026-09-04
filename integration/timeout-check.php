<?php

declare(strict_types=1);

require '/source/modules/addons/pluginupdater/lib/Core.php';

$warnings = PluginUpdater\Transaction::executionTimeWarnings();
if (count($warnings) !== 1 || !str_contains($warnings[0], '60s')) {
    fwrite(STDERR, 'Expected a 60-second preflight warning when set_time_limit is disabled.' . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "PASS disabled set_time_limit produces a preflight warning\n");
