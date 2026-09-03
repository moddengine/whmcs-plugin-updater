<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (($argv[1] ?? '') !== '--latest') {
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " --latest\n");
    exit(2);
}

$storage = __DIR__;
$journals = glob($storage . '/transactions/*.json') ?: [];
rsort($journals, SORT_STRING);
$selected = null;
foreach ($journals as $journalPath) {
    try {
        $candidate = json_decode((string) file_get_contents($journalPath), true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        continue;
    }
    if (is_array($candidate) && !in_array($candidate['status'] ?? '', ['committed', 'recovered'], true)) {
        $selected = [$journalPath, $candidate];
        break;
    }
}
if ($selected === null) {
    fwrite(STDOUT, "No interrupted transaction requires recovery.\n");
    exit(0);
}

[$journalPath, $journal] = $selected;
$lock = fopen($storage . '/updater.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another updater process holds the recovery lock.\n");
    exit(1);
}

try {
    if (($journal['schema'] ?? null) !== 1 || !is_array($journal['swaps'] ?? null)) {
        throw new RuntimeException('Invalid transaction journal');
    }
    $whmcsRoot = (string) ($journal['whmcs_root'] ?? '');
    $allowedParents = [
        $whmcsRoot . '/modules/addons',
        $whmcsRoot . '/modules/servers',
        $whmcsRoot . '/modules/registrars',
    ];
    foreach (array_reverse($journal['swaps']) as $swap) {
        if (!is_array($swap) || !isset($swap['destination'], $swap['old'], $swap['deployment'], $swap['had_original'])) {
            throw new RuntimeException('Invalid transaction swap record');
        }
        $destination = (string) $swap['destination'];
        $old = (string) $swap['old'];
        $deployment = (string) $swap['deployment'];
        $removeOnly = (bool) ($swap['remove_only'] ?? false);
        if ($destination === '' || $whmcsRoot === '' || !in_array(dirname($destination), $allowedParents, true)
            || !preg_match('/^[a-z][a-z0-9_-]*$/D', basename($destination))
            || dirname($destination) !== dirname($old) || (!$removeOnly && dirname($destination) !== dirname($deployment))
            || !str_starts_with(basename($old), '.pluginupdater-old-')
            || (!$removeOnly && !str_starts_with(basename($deployment), '.pluginupdater-new-'))) {
            throw new RuntimeException('Unsafe recovery path in journal');
        }
        if (is_dir($old)) {
            if (is_dir($destination)) {
                pluginupdater_recovery_remove($destination);
            }
            if (!rename($old, $destination)) {
                throw new RuntimeException("Unable to restore {$destination}");
            }
        } elseif (!$swap['had_original'] && ($swap['swapped'] ?? false) && is_dir($destination)) {
            pluginupdater_recovery_remove($destination);
        }
        if (is_dir($deployment)) {
            pluginupdater_recovery_remove($deployment);
        }
    }
    $journal['status'] = 'recovered';
    $journal['updated_at'] = gmdate(DATE_ATOM);
    pluginupdater_recovery_json($journalPath, $journal);
    fwrite(STDOUT, "Recovered transaction {$journal['id']}.\n");
    if ($journal['maintenance_changed'] ?? false) {
        fwrite(STDOUT, "WHMCS Maintenance Mode may still be enabled; restore it in General Settings after confirming WHMCS loads.\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Recovery failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

function pluginupdater_recovery_remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException("Unable to remove {$path}");
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
            pluginupdater_recovery_remove($entry->getPathname());
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException("Unable to remove {$path}");
    }
}

function pluginupdater_recovery_json(string $path, array $data): void
{
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $handle = fopen($temporary, 'xb');
    if ($handle === false || fwrite($handle, $json) !== strlen($json) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
        throw new RuntimeException('Unable to persist recovered journal');
    }
    fclose($handle);
    chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        throw new RuntimeException('Unable to commit recovered journal');
    }
}
