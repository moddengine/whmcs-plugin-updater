<?php

declare(strict_types=1);

namespace PluginUpdater;

use RuntimeException;
use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/Core.php';

final class Cache
{
    private const TABLE = 'mod_pluginupdater_repositories';

    public static function row(string $repository): ?object
    {
        $row = Capsule::table(self::TABLE)->where('repository', $repository)->first();
        return $row ?: null;
    }

    /** @param list<array<string,mixed>> $releases */
    public static function success(string $repository, ?string $etag, array $releases, string $fingerprint, bool $notModified): void
    {
        $existing = self::row($repository);
        $payload = [
            'etag' => $etag,
            'releases_json' => $notModified && $existing ? $existing->releases_json : json_encode($releases, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'configuration_fingerprint' => $fingerprint,
            'consecutive_failures' => 0,
            'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
            'status' => 'ok',
            'error' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        Capsule::table(self::TABLE)->updateOrInsert(['repository' => $repository], $payload);
    }

    /** @param array<string,string> $headers */
    public static function failure(string $repository, string $message, int $status, array $headers, string $fingerprint): void
    {
        $existing = self::row($repository);
        $failures = (int) ($existing->consecutive_failures ?? 0) + 1;
        $configurationError = in_array($status, [401, 404], true);
        $delay = $configurationError ? 7 * 86400 : min(7 * 86400, 3600 * (2 ** min(6, $failures - 1)));
        if (isset($headers['retry-after'])) {
            $retry = ctype_digit($headers['retry-after']) ? (int) $headers['retry-after'] : max(0, strtotime($headers['retry-after']) - time());
            $delay = max($delay, $retry);
        }
        if (isset($headers['x-ratelimit-reset']) && ctype_digit($headers['x-ratelimit-reset'])) {
            $delay = max($delay, (int) $headers['x-ratelimit-reset'] - time());
        }
        Capsule::table(self::TABLE)->updateOrInsert(['repository' => $repository], [
            'configuration_fingerprint' => $fingerprint,
            'consecutive_failures' => $failures,
            'last_attempt_at' => gmdate('Y-m-d H:i:s'),
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + max(60, $delay)),
            'status' => $configurationError ? 'configuration-error' : 'retrying',
            'error' => substr($message, 0, 2000),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function mayAttempt(?object $row, string $fingerprint, bool $force = false): bool
    {
        if ($force || $row === null || !hash_equals((string) ($row->configuration_fingerprint ?? ''), $fingerprint)) {
            return true;
        }
        return strtotime((string) $row->next_attempt_at . ' UTC') <= time();
    }

    /** @return list<array<string,mixed>> */
    public static function releases(?object $row): array
    {
        if ($row === null || !$row->releases_json) {
            return [];
        }
        $decoded = json_decode((string) $row->releases_json, true);
        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
}

final class Service
{
    private readonly string $root;
    private readonly string $webRoot;
    private readonly string $storage;
    private readonly ?string $token;
    private readonly bool $maintenance;

    /** @param array<string,mixed> $settings */
    public function __construct(array $settings)
    {
        if (!defined('ROOTDIR')) {
            throw new RuntimeException('WHMCS ROOTDIR is unavailable');
        }
        $this->root = rtrim((string) ROOTDIR, '/');
        $this->webRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ROOTDIR), '/');
        $this->storage = rtrim((string) ($settings['storagePath'] ?? ''), '/');
        $this->token = ($settings['githubToken'] ?? '') !== '' ? (string) $settings['githubToken'] : null;
        $this->maintenance = in_array(strtolower((string) ($settings['maintenanceMode'] ?? 'on')), ['on', 'yes', '1', 'true'], true);
    }

    /** @return list<Manifest> */
    public function manifests(): array
    {
        return Manifest::discover($this->root);
    }

    /** @return array<string,list<Manifest>> */
    public function packages(): array
    {
        $packages = [];
        foreach ($this->manifests() as $manifest) {
            $packages[$manifest->package][] = $manifest;
        }
        ksort($packages);
        return $packages;
    }

    /** @return list<string> */
    public function check(bool $force = false): array
    {
        $lockName = 'pluginupdater-' . substr(hash('sha256', $this->root), 0, 32);
        $lock = Capsule::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ((int) ($lock->acquired ?? 0) !== 1) {
            return ['A release check is already running'];
        }
        $messages = [];
        try {
            $repositories = [];
            foreach ($this->manifests() as $manifest) {
                $repositories[$manifest->repository][] = $manifest->package . '|' . $manifest->asset;
            }
            $client = new GitHubClient($this->token);
            foreach ($repositories as $repository => $configuration) {
                sort($configuration);
                $fingerprint = hash('sha256', ($this->token ?? 'anonymous') . "\0" . implode("\0", array_unique($configuration)));
                $row = Cache::row($repository);
                if (!Cache::mayAttempt($row, $fingerprint, $force)) {
                    $messages[] = "{$repository}: backoff active until {$row->next_attempt_at} UTC";
                    continue;
                }
                try {
                    $response = $client->releases($repository, $row?->etag);
                    Cache::success($repository, $response['etag'], $response['releases'], $fingerprint, $response['status'] === 304);
                    $messages[] = "{$repository}: checked";
                } catch (HttpException $e) {
                    Cache::failure($repository, $e->getMessage(), $e->status, $e->headers, $fingerprint);
                    $messages[] = "{$repository}: {$e->getMessage()}";
                } catch (\Throwable $e) {
                    Cache::failure($repository, $e->getMessage(), 0, [], $fingerprint);
                    $messages[] = "{$repository}: {$e->getMessage()}";
                }
            }
        } finally {
            Capsule::selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
        return $messages;
    }

    /** @return array<string,array{manifests:list<Manifest>,release:?Release,cache:?object,selection_error:?string}> */
    public function status(): array
    {
        $status = [];
        foreach ($this->packages() as $package => $manifests) {
            $row = Cache::row($manifests[0]->repository);
            try {
                $release = Release::select(Cache::releases($row), $manifests);
                $selectionError = null;
            } catch (\Throwable $e) {
                $release = null;
                $selectionError = $e->getMessage();
            }
            $status[$package] = ['manifests' => $manifests, 'release' => $release, 'cache' => $row, 'selection_error' => $selectionError];
        }
        return $status;
    }

    public function update(string $package, bool $databaseBackupConfirmed, bool $timeoutWarningConfirmed, bool $componentsConfirmed): void
    {
        $packages = $this->packages();
        if (!isset($packages[$package])) {
            throw new RuntimeException('Unknown package');
        }
        $row = Cache::row($packages[$package][0]->repository);
        $release = Release::select(Cache::releases($row), $packages[$package]);
        if ($release === null) {
            throw new RuntimeException('No cached compatible update is available');
        }
        $this->ensureStorage();
        if (!$databaseBackupConfirmed) {
            throw new RuntimeException('A verified database backup must be confirmed before updating');
        }
        logActivity('[Plugin Updater] Administrator confirmed a current database backup for update of ' . $package);
        $this->transaction()->apply($packages[$package], $release, new GitHubClient($this->token), $this->maintenance, $databaseBackupConfirmed, $timeoutWarningConfirmed, $componentsConfirmed);
    }

    public function rollback(string $package, bool $databaseBackupConfirmed, bool $timeoutWarningConfirmed): void
    {
        $packages = $this->packages();
        if (!isset($packages[$package])) {
            throw new RuntimeException('Unknown package');
        }
        if (!$databaseBackupConfirmed) {
            throw new RuntimeException('A verified database backup must be confirmed before rollback');
        }
        logActivity('[Plugin Updater] Administrator confirmed a current database backup for rollback of ' . $package);
        $this->ensureStorage();
        $this->transaction()->rollback($packages[$package], $this->maintenance, $databaseBackupConfirmed, $timeoutWarningConfirmed);
    }

    /** @return list<string> */
    public function preflight(string $package): array
    {
        $packages = $this->packages();
        if (!isset($packages[$package])) {
            throw new RuntimeException('Unknown package');
        }
        $this->ensureStorage();
        return $this->transaction()->preflight($packages[$package]);
    }

    public function rollbackVersion(string $package): ?string
    {
        if ($this->storage === '') {
            return null;
        }
        $root = $this->storage . '/backups/' . hash('sha256', $package);
        $backups = is_dir($root) ? array_values(array_filter(scandir($root) ?: [], static fn (string $name): bool => $name !== '.' && $name !== '..' && is_dir($root . '/' . $name))) : [];
        rsort($backups, SORT_STRING);
        $metadata = $backups === [] ? null : json_decode((string) @file_get_contents($root . '/' . $backups[0] . '/metadata.json'), true);
        if (!is_array($metadata) || ($metadata['package'] ?? null) !== $package || !is_array($metadata['components'] ?? null)) {
            return null;
        }
        $versions = [];
        foreach ($metadata['components'] as $component) {
            if (is_array($component) && !($component['absent'] ?? false) && is_string($component['version'] ?? null)) {
                $versions[] = $component['version'];
            }
        }
        return $versions === [] ? null : implode(', ', array_unique($versions));
    }

    public function recoverPending(): int
    {
        if ($this->storage === '' || !is_dir($this->storage)) {
            return 0;
        }
        Fs::validateStorage($this->storage, $this->webRoot, $this->root);
        return Recovery::pending(
            $this->storage,
            static function (string $value): void {
                $result = localAPI('SetConfigurationValue', ['setting' => 'MaintenanceMode', 'value' => $value]);
                if (($result['result'] ?? null) !== 'success') {
                    throw new RuntimeException('Unable to restore WHMCS maintenance mode');
                }
            },
            static function (string $message): void {
                logActivity('[Plugin Updater] ' . $message);
            },
        );
    }

    private function transaction(): Transaction
    {
        return new Transaction(
            $this->root,
            $this->webRoot,
            $this->storage,
            (string) (Setting::getValue('Version') ?? '0.0.0'),
            static function (): string {
                $result = localAPI('GetConfigurationValue', ['setting' => 'MaintenanceMode']);
                if (($result['result'] ?? null) !== 'success') {
                    throw new RuntimeException('Unable to read WHMCS maintenance mode');
                }
                return (string) ($result['value'] ?? '');
            },
            static function (string $value): void {
                $result = localAPI('SetConfigurationValue', ['setting' => 'MaintenanceMode', 'value' => $value]);
                if (($result['result'] ?? null) !== 'success') {
                    throw new RuntimeException('Unable to change WHMCS maintenance mode');
                }
            },
            static function (string $message): void {
                logActivity('[Plugin Updater] ' . $message);
            },
        );
    }

    private function ensureStorage(): void
    {
        [$storage] = Fs::validateStorage($this->storage, $this->webRoot, $this->root);
        foreach (['staging', 'backups', 'transactions'] as $directory) {
            Fs::mkdir($storage . '/' . $directory, 0700);
        }
        $recovery = $storage . '/recover.php';
        $source = dirname(__DIR__) . '/bin/recover.php';
        $temporary = $recovery . '.tmp-' . bin2hex(random_bytes(6));
        $input = fopen($source, 'rb');
        $output = fopen($temporary, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
            throw new RuntimeException('Unable to copy the external recovery utility');
        }
        $copied = stream_copy_to_stream($input, $output);
        $persisted = $copied !== false && fflush($output) && (!function_exists('fsync') || fsync($output));
        fclose($input);
        fclose($output);
        if (!$persisted || !chmod($temporary, 0700)
            || hash_file('sha256', $source) !== hash_file('sha256', $temporary)
            || !rename($temporary, $recovery)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to install the external recovery utility');
        }
    }
}
