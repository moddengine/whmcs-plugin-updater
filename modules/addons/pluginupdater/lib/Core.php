<?php

declare(strict_types=1);

namespace PluginUpdater;

use DirectoryIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class Manifest
{
    public const FILENAME = 'whmcs.update-manifest.json';
    public const ROOTS = [
        'addon' => 'modules/addons',
        'server' => 'modules/servers',
        'registrar' => 'modules/registrars',
    ];

    public function __construct(
        public readonly int $schema,
        public readonly string $package,
        public readonly string $type,
        public readonly string $name,
        public readonly string $version,
        public readonly string $repository,
        public readonly string $asset,
        public readonly ?string $phpMin,
        public readonly ?string $whmcsMin,
        public readonly ?string $whmcsMaxExclusive,
        public readonly string $manifestPath,
    ) {
    }

    public static function fromFile(string $path): self
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in {$path}: {$e->getMessage()}");
        }

        if (!is_array($data)) {
            throw new RuntimeException("Manifest {$path} must contain a JSON object");
        }

        return self::fromData($data, $path);
    }

    public static function generate(
        string $outputDirectory,
        ?string $package = null,
        ?string $type = null,
        ?string $version = null,
        ?string $repository = null,
        ?string $asset = null,
        ?string $phpMin = null,
        ?string $whmcsMin = null,
        ?string $whmcsMaxExclusive = null,
    ): self {
        $directory = realpath($outputDirectory);
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException("Component directory {$outputDirectory} does not exist");
        }
        $name = basename($directory);
        $modulePath = $directory . '/' . $name . '.php';
        if (!is_file($modulePath)) {
            throw new RuntimeException("Component entrypoint {$modulePath} does not exist");
        }

        if (!defined('WHMCS')) {
            define('WHMCS', true);
        }
        require_once $modulePath;

        $configFunction = $name . '_config';
        $metadataFunction = $name . '_MetaData';
        $optionsFunction = $name . '_ConfigOptions';
        $registrarFunction = $name . '_getConfigArray';
        $metadata = [];
        $detectedType = null;
        if (function_exists($configFunction)) {
            $metadata = self::moduleMetadata($configFunction);
            $detectedType = 'addon';
        } elseif (function_exists($metadataFunction) || function_exists($optionsFunction)) {
            $metadata = function_exists($metadataFunction) ? self::moduleMetadata($metadataFunction) : [];
            if (function_exists($optionsFunction)) {
                $metadata += self::moduleMetadata($optionsFunction);
            }
            $detectedType = 'server';
        } elseif (function_exists($registrarFunction)) {
            $metadata = self::moduleMetadata($registrarFunction);
            $detectedType = 'registrar';
        }

        $normalized = str_replace('\\', '/', $directory);
        if (preg_match('~/modules/(addons|servers|registrars)/[^/]+$~', $normalized, $match)) {
            $pathType = ['addons' => 'addon', 'servers' => 'server', 'registrars' => 'registrar'][$match[1]];
            if ($detectedType !== null && $detectedType !== $pathType) {
                throw new RuntimeException("Component API does not match directory type {$pathType}");
            }
            $detectedType = $pathType;
        }
        $type ??= $detectedType;

        $whmcs = self::nearbyJson($directory, 'whmcs.json');
        $composer = self::nearbyJson($directory, 'composer.json', true);
        $githubRepository = getenv('GITHUB_REPOSITORY') ?: null;
        $repository ??= $githubRepository;
        $package ??= $githubRepository ?? self::nestedString($composer, ['name']);

        $versions = [];
        foreach ([$version, self::nestedString($metadata, ['version']), self::nestedString($whmcs, ['version']), self::githubVersion()] as $candidate) {
            if ($candidate !== null && ($stable = self::stableVersion($candidate)) !== null) {
                $versions[$stable] = true;
            }
        }
        if (count($versions) > 1) {
            throw new RuntimeException('Discovered component versions disagree: ' . implode(', ', array_keys($versions)));
        }
        $version = array_key_first($versions);
        if ($version === null) {
            throw new RuntimeException('Unable to determine a stable component version');
        }
        if ($repository !== null) {
            $asset ??= basename($repository) . '-{version}.zip';
        }

        $phpMin ??= self::versionMinimum(self::nestedString($whmcs, ['requirements', 'php', 'min']))
            ?? self::versionMinimum(self::nestedString($composer, ['require', 'php']))
            ?? self::versionMinimum(self::nestedString($composer, ['config', 'platform', 'php']));
        $whmcsMin ??= self::versionMinimum(self::nestedString($whmcs, ['requirements', 'whmcs', 'min']));
        $whmcsMaxExclusive ??= self::versionMinimum(self::nestedString($whmcs, ['requirements', 'whmcs', 'max_exclusive']));

        $requires = array_filter([
            'php_min' => $phpMin,
            'whmcs_min' => $whmcsMin,
            'whmcs_max_exclusive' => $whmcsMaxExclusive,
        ], static fn (?string $value): bool => $value !== null);
        $data = [
            'schema' => 1,
            'package' => $package,
            'component' => ['type' => $type, 'name' => $name],
            'version' => $version,
            'github' => ['repository' => $repository, 'asset' => $asset],
        ];
        if ($requires !== []) {
            $data['requires'] = $requires;
        }

        $path = $directory . '/' . self::FILENAME;
        $manifest = self::fromData($data, $path);
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $e) {
            throw new RuntimeException("Unable to encode manifest: {$e->getMessage()}");
        }
        if (file_put_contents($path, $json) !== strlen($json)) {
            throw new RuntimeException("Unable to write manifest {$path}");
        }
        return $manifest;
    }

    /** @param array<string,mixed> $data */
    private static function fromData(array $data, string $path): self
    {

        self::exactKeys($data, ['schema', 'package', 'component', 'version', 'github'], ['requires'], 'manifest');
        self::exactKeys(self::object($data, 'component'), ['type', 'name'], [], 'component');
        self::exactKeys(self::object($data, 'github'), ['repository', 'asset'], [], 'github');
        $requires = isset($data['requires']) ? self::object($data, 'requires') : [];
        self::exactKeys($requires, [], ['php_min', 'whmcs_min', 'whmcs_max_exclusive'], 'requires');

        $schema = $data['schema'] ?? null;
        if ($schema !== 1) {
            throw new RuntimeException("Manifest {$path} has unsupported schema");
        }

        $component = $data['component'];
        $github = $data['github'];
        $package = self::string($data, 'package');
        $type = self::string($component, 'type');
        $name = self::string($component, 'name');
        $version = self::string($data, 'version');
        $repository = self::string($github, 'repository');
        $asset = self::string($github, 'asset');

        if (!isset(self::ROOTS[$type])) {
            throw new RuntimeException("Manifest {$path} uses unsupported component type {$type}");
        }
        if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $name)) {
            throw new RuntimeException("Manifest {$path} has an invalid component name");
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $package)) {
            throw new RuntimeException("Manifest {$path} has an invalid package identifier");
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository)) {
            throw new RuntimeException("Manifest {$path} has an invalid GitHub repository");
        }
        self::assertVersion($version, "manifest {$path}");
        if (strlen($asset) > 255 || !preg_match('/^[A-Za-z0-9._-]*\{version\}[A-Za-z0-9._-]*\.zip$/Di', $asset)) {
            throw new RuntimeException("Manifest {$path} has an invalid asset template");
        }
        foreach ($requires as $key => $constraint) {
            if (!is_string($constraint)) {
                throw new RuntimeException("Manifest {$path} requirement {$key} must be a version string");
            }
            self::assertVersion($constraint, "requirement {$key}");
        }

        return new self(
            1,
            $package,
            $type,
            $name,
            $version,
            $repository,
            $asset,
            $requires['php_min'] ?? null,
            $requires['whmcs_min'] ?? null,
            $requires['whmcs_max_exclusive'] ?? null,
            $path,
        );
    }

    /** @return array<string,mixed> */
    private static function moduleMetadata(string $function): array
    {
        $metadata = $function();
        if (!is_array($metadata)) {
            throw new RuntimeException("Module metadata function {$function} must return an array");
        }
        return $metadata;
    }

    /** @return array<string,mixed> */
    private static function nearbyJson(string $directory, string $filename, bool $ancestors = false): array
    {
        do {
            $path = $directory . '/' . $filename;
            if (is_file($path)) {
                try {
                    $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new RuntimeException("Invalid JSON in {$path}: {$e->getMessage()}");
                }
                return is_array($data) ? $data : [];
            }
            $parent = dirname($directory);
            if (!$ancestors || $parent === $directory) {
                break;
            }
            $directory = $parent;
        } while (true);
        return [];
    }

    /** @param array<string,mixed> $data @param list<string> $keys */
    private static function nestedString(array $data, array $keys): ?string
    {
        $value = $data;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function githubVersion(): ?string
    {
        $ref = getenv('GITHUB_REF') ?: '';
        if ((getenv('GITHUB_REF_TYPE') ?: '') !== 'tag' && !str_starts_with($ref, 'refs/tags/')) {
            return null;
        }
        return getenv('GITHUB_REF_NAME') ?: basename($ref);
    }

    private static function stableVersion(string $version): ?string
    {
        return preg_match('/^v?(\d+\.\d+\.\d+)$/D', trim($version), $match) ? $match[1] : null;
    }

    private static function versionMinimum(?string $constraint): ?string
    {
        if ($constraint === null || !preg_match('/(?:^|\s)(?:>=|\^|~)?\s*(\d+\.\d+(?:\.\d+)?)/', $constraint, $match)) {
            return null;
        }
        return substr_count($match[1], '.') === 1 ? $match[1] . '.0' : $match[1];
    }

    /** @return list<self> */
    public static function discover(string $whmcsRoot): array
    {
        $found = [];
        foreach (self::ROOTS as $type => $relativeRoot) {
            $root = $whmcsRoot . '/' . $relativeRoot;
            if (!is_dir($root)) {
                continue;
            }
            foreach (new DirectoryIterator($root) as $entry) {
                if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) {
                    continue;
                }
                $path = $entry->getPathname() . '/' . self::FILENAME;
                if (!is_file($path) || is_link($path)) {
                    continue;
                }
                $manifest = self::fromFile($path);
                if ($manifest->type !== $type || $manifest->name !== $entry->getFilename()) {
                    throw new RuntimeException("Manifest {$path} does not match its installed location");
                }
                $found[] = $manifest;
            }
        }
        return $found;
    }

    public function destination(string $whmcsRoot): string
    {
        return $whmcsRoot . '/' . self::ROOTS[$this->type] . '/' . $this->name;
    }

    public function componentKey(): string
    {
        return $this->type . ':' . $this->name;
    }

    public function assertCompatible(string $phpVersion, string $whmcsVersion): void
    {
        if ($this->phpMin !== null && version_compare($phpVersion, $this->phpMin, '<')) {
            throw new RuntimeException("{$this->componentKey()} requires PHP {$this->phpMin} or newer");
        }
        if ($this->whmcsMin !== null && version_compare($whmcsVersion, $this->whmcsMin, '<')) {
            throw new RuntimeException("{$this->componentKey()} requires WHMCS {$this->whmcsMin} or newer");
        }
        if ($this->whmcsMaxExclusive !== null && version_compare($whmcsVersion, $this->whmcsMaxExclusive, '>=')) {
            throw new RuntimeException("{$this->componentKey()} requires WHMCS below {$this->whmcsMaxExclusive}");
        }
    }

    public static function tagVersion(string $tag): ?string
    {
        return preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $match) ? $match[1] : null;
    }

    private static function assertVersion(string $version, string $where): void
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            throw new RuntimeException("Invalid stable semantic version {$version} in {$where}");
        }
    }

    /** @param array<string,mixed> $value */
    private static function exactKeys(array $value, array $required, array $optional, string $where): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw new RuntimeException("Missing {$where}.{$key}");
            }
        }
        $unknown = array_diff(array_keys($value), [...$required, ...$optional]);
        if ($unknown !== []) {
            throw new RuntimeException("Unknown {$where} field " . reset($unknown));
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function object(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || array_is_list($data[$key])) {
            throw new RuntimeException("Manifest field {$key} must be an object");
        }
        return $data[$key];
    }

    /** @param array<string,mixed> $data */
    private static function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
            throw new RuntimeException("Manifest field {$key} must be a non-empty string");
        }
        return $data[$key];
    }
}

final class Release
{
    public function __construct(
        public readonly string $version,
        public readonly string $tag,
        public readonly string $url,
        public readonly string $notes,
        public readonly int $assetId,
        public readonly string $assetName,
        public readonly int $assetSize,
        public readonly string $digest,
    ) {
    }

    /** @param list<array<string,mixed>> $releases @param list<Manifest> $manifests */
    public static function select(array $releases, array $manifests): ?self
    {
        if ($manifests === []) {
            return null;
        }
        $template = $manifests[0]->asset;
        $installed = $manifests[0]->version;
        foreach ($manifests as $manifest) {
            if ($manifest->repository !== $manifests[0]->repository || $manifest->asset !== $template) {
                throw new RuntimeException("Package {$manifest->package} has inconsistent GitHub settings");
            }
            if (version_compare($manifest->version, $installed, '>')) {
                $installed = $manifest->version;
            }
        }

        $candidates = [];
        foreach ($releases as $candidate) {
            if (($candidate['draft'] ?? true) || ($candidate['prerelease'] ?? true)) {
                continue;
            }
            $tag = is_string($candidate['tag_name'] ?? null) ? $candidate['tag_name'] : '';
            $version = Manifest::tagVersion($tag);
            if ($version === null || version_compare($version, $installed, '<=')) {
                continue;
            }
            $candidates[$version] = [$tag, $candidate];
        }
        if ($candidates === []) {
            return null;
        }
        uksort($candidates, static fn (string $left, string $right): int => version_compare($right, $left));
        $version = array_key_first($candidates);
        [$tag, $candidate] = $candidates[$version];
        $expectedName = str_replace('{version}', $version, $template);
        $assets = array_values(array_filter(
            is_array($candidate['assets'] ?? null) ? $candidate['assets'] : [],
            static fn ($asset): bool => is_array($asset) && ($asset['name'] ?? null) === $expectedName,
        ));
        if (count($assets) !== 1) {
            throw new RuntimeException("Latest release {$tag} must contain exactly one {$expectedName} asset");
        }
        $asset = $assets[0];
        $digest = is_string($asset['digest'] ?? null) ? $asset['digest'] : '';
        if (!preg_match('/^sha256:([a-f0-9]{64})$/D', $digest, $digestMatch)) {
            throw new RuntimeException("Latest release {$tag} has no valid GitHub SHA-256 digest");
        }
        $assetId = filter_var($asset['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $assetSize = filter_var($asset['size'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        if ($assetId < 1 || $assetSize < 1 || $assetSize > Archive::MAX_COMPRESSED) {
            throw new RuntimeException("Latest release {$tag} has invalid asset metadata");
        }
        return new self(
            $version,
            $tag,
            'https://github.com/' . $manifests[0]->repository . '/releases/tag/' . rawurlencode($tag),
            substr(is_string($candidate['body'] ?? null) ? $candidate['body'] : '', 0, 262144),
            $assetId,
            $expectedName,
            $assetSize,
            $digestMatch[1],
        );
    }
}

class GitHubClient
{
    private const API = 'https://api.github.com';

    public function __construct(private readonly ?string $token = null)
    {
    }

    /** @return array{status:int,etag:?string,releases:list<array<string,mixed>>,headers:array<string,string>} */
    public function releases(string $repository, ?string $etag = null): array
    {
        $headers = ['Accept: application/vnd.github+json'];
        if ($etag !== null && $etag !== '') {
            $headers[] = 'If-None-Match: ' . $etag;
        }
        $response = $this->request(self::API . '/repos/' . $repository . '/releases?per_page=100', $headers, null, 30);
        if ($response['status'] === 304) {
            return ['status' => 304, 'etag' => $response['headers']['etag'] ?? $etag, 'releases' => [], 'headers' => $response['headers']];
        }
        if ($response['status'] !== 200) {
            throw new HttpException($response['status'], $this->failureMessage($response), $response['headers']);
        }
        try {
            $decoded = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('GitHub returned invalid JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('GitHub release response was not a list');
        }
        return ['status' => 200, 'etag' => $response['headers']['etag'] ?? null, 'releases' => $decoded, 'headers' => $response['headers']];
    }

    /** @param array{status:int,body:string,headers:array<string,string>} $response */
    private function failureMessage(array $response): string
    {
        if ($response['status'] === 403 && ($response['headers']['x-ratelimit-remaining'] ?? null) === '0') {
            return $this->token === null
                ? 'GitHub API rate limit exceeded; configure a GitHub token in the addon settings'
                : 'GitHub API rate limit exceeded; the configured token was not accepted or its quota is exhausted';
        }
        $decoded = json_decode($response['body'], true);
        $detail = is_array($decoded) && is_string($decoded['message'] ?? null) ? trim($decoded['message']) : '';
        return 'GitHub release request failed' . ($detail !== '' ? ': ' . $detail : '');
    }

    public function download(string $repository, Release $release, string $destination): void
    {
        $endpoint = self::API . '/repos/' . $repository . '/releases/assets/' . $release->assetId;
        $first = $this->downloadResponse($endpoint, $destination, ['Accept: application/octet-stream'], 30);
        if ($first['status'] === 200) {
            // The API may return the asset directly instead of redirecting.
        } elseif (in_array($first['status'], [301, 302, 303, 307, 308], true)) {
            @unlink($destination);
            $location = $first['headers']['location'] ?? '';
            self::assertDownloadUrl($location);
            $this->downloadUrl($location, $destination);
        } else {
            @unlink($destination);
            throw new HttpException($first['status'], 'GitHub asset request failed', $first['headers']);
        }
        $actualDigest = hash_file('sha256', $destination);
        if ($actualDigest === false || !hash_equals($release->digest, $actualDigest)) {
            @unlink($destination);
            throw new RuntimeException('Downloaded release SHA-256 does not match GitHub metadata');
        }
        if (filesize($destination) !== $release->assetSize) {
            @unlink($destination);
            throw new RuntimeException('Downloaded release size does not match GitHub metadata');
        }
    }

    private function downloadUrl(string $url, string $destination): void
    {
        $response = $this->downloadResponse($url, $destination, [], 300);
        if ($response['status'] !== 200) {
            @unlink($destination);
            throw new RuntimeException("GitHub asset download failed (HTTP {$response['status']})");
        }
    }

    /** @return array{status:int,headers:array<string,string>} */
    private function downloadResponse(string $url, string $destination, array $headers, int $timeout): array
    {
        $handle = fopen($destination, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create release download file');
        }
        $downloaded = 0;
        $responseHeaders = [];
        $curl = $this->curl($url, $headers, $timeout);
        curl_setopt($curl, CURLOPT_FILE, $handle);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return $length;
        });
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($curl, string $chunk) use ($handle, &$downloaded): int {
            $downloaded += strlen($chunk);
            if ($downloaded > Archive::MAX_COMPRESSED) {
                return 0;
            }
            return fwrite($handle, $chunk) ?: 0;
        });
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);
        if ($ok === false || $downloaded > Archive::MAX_COMPRESSED) {
            @unlink($destination);
            throw new RuntimeException("GitHub asset download failed ({$status}): {$error}");
        }
        return ['status' => $status, 'headers' => $responseHeaders];
    }

    /** @return array{status:int,body:string,headers:array<string,string>} */
    private function request(string $url, array $headers, ?string $body, int $timeout): array
    {
        $responseHeaders = [];
        $curl = $this->curl($url, $headers, $timeout);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return $length;
        });
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = '';
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($curl, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 8_388_608) {
                return 0;
            }
            $response .= $chunk;
            return strlen($chunk);
        });
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($ok === false) {
            throw new HttpException(0, 'GitHub request failed: ' . $error, $responseHeaders);
        }
        return ['status' => $status, 'body' => $response, 'headers' => $responseHeaders];
    }

    private function curl(string $url, array $headers, int $timeout): \CurlHandle
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }
        $headers[] = 'User-Agent: WHMCS-Plugin-Updater/1.0';
        if ($this->token !== null && $this->token !== '' && str_starts_with($url, self::API . '/')) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        return $curl;
    }

    private static function assertDownloadUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ($host !== 'github.com' && !str_ends_with($host, '.githubusercontent.com'))) {
            throw new RuntimeException('GitHub returned an untrusted asset redirect');
        }
    }
}

final class HttpException extends RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(public readonly int $status, string $message, public readonly array $headers = [])
    {
        parent::__construct($message . ($status ? " (HTTP {$status})" : ''));
    }
}

final class Archive
{
    public const MAX_COMPRESSED = 262_144_000;
    public const MAX_EXTRACTED = 524_288_000;
    public const MAX_ENTRIES = 10_000;
    private const MAX_RATIO = 100;

    public static function extract(string $archive, string $destination): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP ZIP extension is required');
        }
        if (!is_file($archive) || filesize($archive) > self::MAX_COMPRESSED) {
            throw new RuntimeException('Release archive exceeds the compressed size limit');
        }
        if (file_exists($destination)) {
            throw new RuntimeException("Extraction destination already exists: {$destination}");
        }
        Fs::mkdir($destination, 0700);

        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            Fs::remove($destination);
            throw new RuntimeException('Unable to open release ZIP');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('Release ZIP has an invalid number of entries');
            }
            $seen = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) {
                    throw new RuntimeException('Unable to inspect a ZIP entry');
                }
                $name = self::safeName((string) $stat['name']);
                $key = strtolower(rtrim($name, '/'));
                if (isset($seen[$key])) {
                    throw new RuntimeException("Duplicate or case-colliding ZIP entry: {$name}");
                }
                $seen[$key] = true;
                $size = (int) $stat['size'];
                $compressed = (int) $stat['comp_size'];
                $total += $size;
                if ($total > self::MAX_EXTRACTED || ($size > 1_048_576 && $size > max(1, $compressed) * self::MAX_RATIO)) {
                    throw new RuntimeException('Release ZIP exceeds extraction safety limits');
                }
                $directory = str_ends_with($name, '/');
                self::assertRegularEntry($zip, $index, $directory, $name);
                $target = $destination . '/' . rtrim($name, '/');
                if ($directory) {
                    Fs::mkdir($target, 0755);
                    continue;
                }
                Fs::mkdir(dirname($target), 0755);
                $input = $zip->getStream((string) $stat['name']);
                $output = fopen($target, 'xb');
                if ($input === false || $output === false) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    throw new RuntimeException("Unable to extract ZIP entry {$name}");
                }
                $written = stream_copy_to_stream($input, $output, $size + 1);
                fclose($input);
                fclose($output);
                if ($written !== $size) {
                    throw new RuntimeException("ZIP entry size changed while extracting {$name}");
                }
                if (!chmod($target, 0644)) {
                    throw new RuntimeException("Unable to set safe permissions on {$name}");
                }
            }
        } catch (\Throwable $e) {
            $zip->close();
            Fs::remove($destination);
            throw $e;
        }
        $zip->close();
    }

    /** @return list<Manifest> */
    public static function manifests(string $extractedRoot): array
    {
        $manifests = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractedRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Extracted release contains a symbolic link');
            }
            if ($entry->isFile() && $entry->getFilename() === Manifest::FILENAME) {
                $manifests[] = Manifest::fromFile($entry->getPathname());
            }
        }
        if ($manifests === []) {
            throw new RuntimeException('Release ZIP contains no update manifests');
        }

        $roots = array_map(static fn (Manifest $manifest): string => dirname($manifest->manifestPath), $manifests);
        foreach ($roots as $leftIndex => $left) {
            foreach ($roots as $rightIndex => $right) {
                if ($leftIndex !== $rightIndex && Fs::contains($left, $right)) {
                    throw new RuntimeException('Release ZIP contains overlapping manifest roots');
                }
            }
        }
        return $manifests;
    }

    private static function safeName(string $name): string
    {
        if ($name === '' || strlen($name) > 4096 || str_contains($name, "\0") || str_contains($name, '\\')) {
            throw new RuntimeException('ZIP contains an unsafe path');
        }
        if ($name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)) {
            throw new RuntimeException("ZIP contains an absolute path: {$name}");
        }
        foreach (explode('/', rtrim($name, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255) {
                throw new RuntimeException("ZIP contains an unsafe path: {$name}");
            }
        }
        return $name;
    }

    private static function assertRegularEntry(ZipArchive $zip, int $index, bool $directory, string $name): void
    {
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes, ZipArchive::FL_UNCHANGED)) {
            return;
        }
        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return;
        }
        $type = ($attributes >> 16) & 0170000;
        $expected = $directory ? 0040000 : 0100000;
        if ($type !== 0 && $type !== $expected) {
            throw new RuntimeException("ZIP contains a link or special file: {$name}");
        }
    }
}

final class Fs
{
    public static function mkdir(string $path, int $mode): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException("Unable to create directory {$path}");
        }
        if (!chmod($path, $mode)) {
            throw new RuntimeException("Unable to set permissions on {$path}");
        }
    }

    public static function remove(string $path): void
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
                self::remove($entry->getPathname());
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException("Unable to remove directory {$path}");
        }
    }

    public static function copyTree(string $source, string $destination): void
    {
        if (is_link($source) || !is_dir($source)) {
            throw new RuntimeException("Component source is not a regular directory: {$source}");
        }
        self::mkdir($destination, 0755);
        foreach (new DirectoryIterator($source) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $from = $entry->getPathname();
            $to = $destination . '/' . $entry->getFilename();
            if ($entry->isLink()) {
                throw new RuntimeException("Symbolic links are not supported: {$from}");
            }
            if ($entry->isDir()) {
                self::copyTree($from, $to);
            } elseif ($entry->isFile()) {
                if (!copy($from, $to)) {
                    throw new RuntimeException("Unable to copy {$from}");
                }
                if (!chmod($to, 0644)) {
                    throw new RuntimeException("Unable to set permissions on {$to}");
                }
            } else {
                throw new RuntimeException("Special files are not supported: {$from}");
            }
        }
    }

    /** @return array<string,array{size:int,sha256:string}> */
    public static function inventory(string $root): array
    {
        $result = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException("Symbolic links are not supported: {$entry->getPathname()}");
            }
            if ($entry->isFile()) {
                $relative = substr($entry->getPathname(), strlen(rtrim($root, '/')) + 1);
                $digest = hash_file('sha256', $entry->getPathname());
                if ($digest === false) {
                    throw new RuntimeException("Unable to hash {$entry->getPathname()}");
                }
                $result[$relative] = ['size' => $entry->getSize(), 'sha256' => $digest];
            }
        }
        ksort($result);
        return $result;
    }

    public static function copyVerified(string $source, string $destination): array
    {
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException("Copy destination already exists: {$destination}");
        }
        self::copyTree($source, $destination);
        $sourceInventory = self::inventory($source);
        if ($sourceInventory !== self::inventory($destination)) {
            self::remove($destination);
            throw new RuntimeException("Copy verification failed for {$source}");
        }
        return $sourceInventory;
    }

    public static function contains(string $parent, string $candidate): bool
    {
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        return $candidate === $parent || str_starts_with($candidate . '/', $parent . '/');
    }

    public static function atomicJson(string $path, array $data): void
    {
        self::mkdir(dirname($path), 0700);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Unable to create journal {$temporary}");
        }
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Unable to persist transaction journal');
            }
        } finally {
            fclose($handle);
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Unable to commit journal {$path}");
        }
    }

    /** @return list<string> */
    public static function validateStorage(string $storage, string $webRoot, string $whmcsRoot): array
    {
        if ($storage === '' || $storage[0] !== '/') {
            throw new RuntimeException('Update storage must be an absolute path');
        }
        $storageReal = realpath($storage);
        $webReal = realpath($webRoot);
        $whmcsReal = realpath($whmcsRoot);
        if ($storageReal === false || $webReal === false || $whmcsReal === false || $storageReal !== rtrim($storage, '/') || is_link($storage)) {
            throw new RuntimeException('Storage, web root, and WHMCS root must be existing canonical directories');
        }
        if (self::contains($webReal, $storageReal) || self::contains($whmcsReal, $storageReal)) {
            throw new RuntimeException('Update storage must be outside the web and WHMCS roots');
        }
        if (!is_writable($storageReal)) {
            throw new RuntimeException('Update storage is not writable');
        }
        if ((fileperms($storageReal) & 0777) !== 0700) {
            throw new RuntimeException('Update storage must have mode 0700');
        }
        self::validateOwners([$storageReal]);
        return [$storageReal, $webReal, $whmcsReal];
    }

    /** @param list<string> $paths */
    public static function validateOwners(array $paths): void
    {
        if (!function_exists('posix_geteuid')) {
            throw new RuntimeException('The POSIX extension is required to verify update ownership');
        }
        $uid = posix_geteuid();
        if ($uid === 0) {
            throw new RuntimeException('Refusing to update from a web process running as root');
        }
        foreach (array_unique($paths) as $path) {
            clearstatcache(true, $path);
            $owner = fileowner($path);
            if ($owner === false || $owner !== $uid) {
                $expectedUser = function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? 'unknown') : 'unknown';
                $actualUser = $owner !== false && function_exists('posix_getpwuid') ? (posix_getpwuid($owner)['name'] ?? 'unknown') : 'unknown';
                throw new RuntimeException("Owner mismatch for {$path}: expected {$expectedUser} ({$uid}), found {$actualUser} (" . ($owner === false ? 'unknown' : $owner) . ')');
            }
        }
    }

    /** @param list<string> $parents */
    public static function probeParents(array $parents): void
    {
        foreach (array_unique($parents) as $parent) {
            $probe = $parent . '/.pluginupdater-probe-' . bin2hex(random_bytes(6));
            $renamed = $probe . '-renamed';
            if (!mkdir($probe, 0700) || file_put_contents($probe . '/probe', 'ok', LOCK_EX) !== 2 || !rename($probe, $renamed)) {
                if (file_exists($probe)) {
                    self::remove($probe);
                }
                throw new RuntimeException("Create/write/rename probe failed in {$parent}");
            }
            self::remove($renamed);
        }
    }
}

final class Transaction
{
    /** @var callable():string */
    private $getMaintenance;
    /** @var callable(string):void */
    private $setMaintenance;
    /** @var callable(string):void */
    private $log;

    public function __construct(
        private readonly string $whmcsRoot,
        private readonly string $webRoot,
        private readonly string $storage,
        private readonly string $whmcsVersion,
        callable $getMaintenance,
        callable $setMaintenance,
        callable $log,
    ) {
        $this->getMaintenance = $getMaintenance;
        $this->setMaintenance = $setMaintenance;
        $this->log = $log;
    }

    /** @param list<Manifest> $installed @return list<string> warnings */
    public function preflight(array $installed): array
    {
        $warnings = self::executionTimeWarnings();
        [$storage] = Fs::validateStorage($this->storage, $this->webRoot, $this->whmcsRoot);
        Fs::mkdir($storage . '/staging', 0700);
        Fs::mkdir($storage . '/backups', 0700);
        Fs::mkdir($storage . '/transactions', 0700);
        $owners = [$storage, $storage . '/staging', $storage . '/backups', $storage . '/transactions'];
        $parents = [];
        foreach ($installed as $manifest) {
            $destination = $manifest->destination($this->whmcsRoot);
            if (!is_dir($destination) || is_link($destination)) {
                throw new RuntimeException("Installed component directory is unsafe or missing: {$destination}");
            }
            $owners[] = $destination;
            $owners[] = dirname($destination);
            $parents[] = dirname($destination);
            Fs::inventory($destination);
        }
        Fs::validateOwners($owners);
        Fs::probeParents([...$parents, $storage . '/staging', $storage . '/backups', $storage . '/transactions']);
        return $warnings;
    }

    /** @param list<Manifest> $installed */
    public function apply(array $installed, Release $release, GitHubClient $github, bool $maintenance, bool $databaseBackupConfirmed, bool $timeoutWarningConfirmed, bool $componentsConfirmed): void
    {
        if ($installed === []) {
            throw new RuntimeException('No installed package components were found');
        }
        if (!$databaseBackupConfirmed) {
            throw new RuntimeException('A verified database backup must be confirmed before updating');
        }
        $warnings = $this->preflight($installed);
        if ($warnings !== [] && !$timeoutWarningConfirmed) {
            throw new RuntimeException('The execution-time warning must be acknowledged before updating');
        }

        $lock = fopen($this->storage . '/updater.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Another update or rollback is already running');
        }

        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        $work = $this->storage . '/staging/' . $id;
        $archive = $work . '/release.zip';
        $extracted = $work . '/extracted';
        $journalPath = $this->storage . '/transactions/' . $id . '.json';
        $maintenanceOriginal = null;
        $journal = [
            'schema' => 1,
            'id' => $id,
            'package' => $installed[0]->package,
            'whmcs_root' => $this->whmcsRoot,
            'action' => 'update',
            'status' => 'preparing',
            'maintenance_original' => null,
            'maintenance_changed' => false,
            'swaps' => [],
            'backup' => null,
            'updated_at' => gmdate(DATE_ATOM),
        ];

        try {
            Fs::mkdir($work, 0700);
            $github->download($installed[0]->repository, $release, $archive);
            Archive::extract($archive, $extracted);
            $incoming = $this->validateIncoming(Archive::manifests($extracted), $installed, $release);
            $installedKeys = array_fill_keys(array_map(static fn (Manifest $manifest): string => $manifest->componentKey(), $installed), true);
            $newComponents = array_values(array_filter($incoming, static fn (Manifest $manifest): bool => !isset($installedKeys[$manifest->componentKey()])));
            $this->assertNewDestinationsAvailable($newComponents);
            if ($newComponents !== [] && !$componentsConfirmed) {
                throw new RuntimeException('Release adds components that require explicit confirmation: ' . implode(', ', array_map(static fn (Manifest $manifest): string => $manifest->componentKey(), $newComponents)));
            }
            $incomingParents = array_map(fn (Manifest $manifest): string => dirname($manifest->destination($this->whmcsRoot)), $incoming);
            Fs::validateOwners($incomingParents);
            Fs::probeParents($incomingParents);
            $this->assertFreeSpace($incoming, $installed, $release->assetSize);

            $packageHash = hash('sha256', $installed[0]->package);
            $backupCandidate = $this->storage . '/backups/.candidate-' . $id;
            Fs::mkdir($backupCandidate . '/components', 0700);
            $backupMetadata = ['schema' => 1, 'package' => $installed[0]->package, 'created_at' => gmdate(DATE_ATOM), 'components' => []];
            foreach ($installed as $manifest) {
                $key = $manifest->componentKey();
                $backupPath = $backupCandidate . '/components/' . rawurlencode($key);
                $inventory = Fs::copyVerified($manifest->destination($this->whmcsRoot), $backupPath);
                $backupMetadata['components'][$key] = [
                    'type' => $manifest->type,
                    'name' => $manifest->name,
                    'version' => $manifest->version,
                    'absent' => false,
                    'path' => 'components/' . rawurlencode($key),
                    'inventory' => $inventory,
                ];
            }
            foreach ($newComponents as $manifest) {
                $backupMetadata['components'][$manifest->componentKey()] = [
                    'type' => $manifest->type,
                    'name' => $manifest->name,
                    'version' => null,
                    'absent' => true,
                ];
            }
            Fs::atomicJson($backupCandidate . '/metadata.json', $backupMetadata);

            $swaps = [];
            foreach ($incoming as $manifest) {
                $destination = $manifest->destination($this->whmcsRoot);
                $deployment = dirname($destination) . '/.pluginupdater-new-' . $id . '-' . $manifest->name;
                $old = dirname($destination) . '/.pluginupdater-old-' . $id . '-' . $manifest->name;
                Fs::copyVerified(dirname($manifest->manifestPath), $deployment);
                $swaps[] = [
                    'component' => $manifest->componentKey(),
                    'destination' => $destination,
                    'deployment' => $deployment,
                    'old' => $old,
                    'had_original' => is_dir($destination),
                    'swapped' => false,
                    'remove_only' => false,
                ];
            }

            // Recheck after all slower network and hashing work.
            $this->preflight($installed);
            $this->assertNewDestinationsAvailable($newComponents);
            $maintenanceOriginal = (string) ($this->getMaintenance)();
            $journal['status'] = 'ready';
            $journal['maintenance_original'] = $maintenanceOriginal;
            $journal['swaps'] = $swaps;
            $journal['backup'] = $backupCandidate;
            $this->saveJournal($journalPath, $journal);

            if ($maintenance && !self::maintenanceEnabled($maintenanceOriginal)) {
                $journal['maintenance_changed'] = true;
                $this->saveJournal($journalPath, $journal);
                ($this->setMaintenance)('on');
            }

            $journal['status'] = 'swapping';
            $this->saveJournal($journalPath, $journal);
            foreach ($journal['swaps'] as $index => $swap) {
                if ($swap['had_original'] && !rename($swap['destination'], $swap['old'])) {
                    throw new RuntimeException("Unable to move existing component {$swap['component']}");
                }
                if (!rename($swap['deployment'], $swap['destination'])) {
                    if ($swap['had_original']) {
                        @rename($swap['old'], $swap['destination']);
                    }
                    throw new RuntimeException("Unable to install component {$swap['component']}");
                }
                $journal['swaps'][$index]['swapped'] = true;
                $this->saveJournal($journalPath, $journal);
            }

            $finalBackupRoot = $this->storage . '/backups/' . $packageHash;
            Fs::mkdir($finalBackupRoot, 0700);
            $finalBackup = $finalBackupRoot . '/' . $id;
            if (!rename($backupCandidate, $finalBackup)) {
                throw new RuntimeException('Unable to commit the verified backup');
            }
            $journal['backup'] = $finalBackup;
            $journal['status'] = 'committed';
            $this->saveJournal($journalPath, $journal);

            foreach ($journal['swaps'] as $swap) {
                try {
                    if (is_dir($swap['old'])) {
                        Fs::remove($swap['old']);
                    }
                    self::invalidateOpcache($swap['destination']);
                } catch (\Throwable $cleanupError) {
                    ($this->log)('Post-update cleanup warning: ' . $cleanupError->getMessage());
                }
            }
            try {
                $this->retainOnly($finalBackupRoot, $id);
            } catch (\Throwable $cleanupError) {
                ($this->log)('Backup retention warning: ' . $cleanupError->getMessage());
            }
            try {
                ($this->log)("Updated {$installed[0]->package} to {$release->version}");
            } catch (\Throwable) {
            }
        } catch (\Throwable $e) {
            if (($journal['status'] ?? null) === 'committed') {
                throw $e;
            }
            try {
                $this->reverse($journal);
                $journal['status'] = 'recovered';
                $journal['error'] = $e->getMessage();
                $this->saveJournal($journalPath, $journal);
            } catch (\Throwable $recoveryError) {
                $journal['status'] = 'recovery-required';
                $journal['error'] = $e->getMessage();
                $journal['recovery_error'] = $recoveryError->getMessage();
                $this->saveJournal($journalPath, $journal);
            }
            ($this->log)("Update failed for {$installed[0]->package}: {$e->getMessage()}");
            throw $e;
        } finally {
            if ($maintenanceOriginal !== null && isset($journal['maintenance_changed']) && $journal['maintenance_changed']) {
                try {
                    ($this->setMaintenance)($maintenanceOriginal);
                    $journal['maintenance_changed'] = false;
                    $this->saveJournal($journalPath, $journal);
                } catch (\Throwable $e) {
                    ($this->log)('Unable to restore WHMCS maintenance mode: ' . $e->getMessage());
                }
            }
            if (is_dir($work)) {
                try {
                    Fs::remove($work);
                } catch (\Throwable $cleanupError) {
                    try {
                        ($this->log)('Staging cleanup warning: ' . $cleanupError->getMessage());
                    } catch (\Throwable) {
                    }
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param list<Manifest> $installed */
    public function rollback(array $installed, bool $maintenance, bool $databaseBackupConfirmed, bool $timeoutWarningConfirmed): void
    {
        if ($installed === [] || !$databaseBackupConfirmed) {
            throw new RuntimeException('A package and verified database backup confirmation are required');
        }
        $warnings = $this->preflight($installed);
        if ($warnings !== [] && !$timeoutWarningConfirmed) {
            throw new RuntimeException('The execution-time warning must be acknowledged before rollback');
        }
        $root = $this->storage . '/backups/' . hash('sha256', $installed[0]->package);
        $backups = is_dir($root) ? array_values(array_filter(scandir($root) ?: [], static fn (string $name): bool => $name !== '.' && $name !== '..' && is_dir($root . '/' . $name))) : [];
        rsort($backups, SORT_STRING);
        if ($backups === []) {
            throw new RuntimeException('No rollback copy is available for this package');
        }
        $metadataPath = $root . '/' . $backups[0] . '/metadata.json';
        try {
            $metadata = json_decode((string) file_get_contents($metadataPath), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('Rollback metadata is invalid: ' . $e->getMessage());
        }
        if (($metadata['package'] ?? null) !== $installed[0]->package || !is_array($metadata['components'] ?? null)) {
            throw new RuntimeException('Rollback metadata does not match the selected package');
        }

        $lock = fopen($this->storage . '/updater.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another update or rollback is already running');
        }
        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        $journalPath = $this->storage . '/transactions/' . $id . '.json';
        $maintenanceOriginal = (string) ($this->getMaintenance)();
        $nextBackup = $this->storage . '/backups/.candidate-' . $id;
        $journal = ['schema' => 1, 'id' => $id, 'package' => $installed[0]->package, 'whmcs_root' => $this->whmcsRoot, 'action' => 'rollback', 'status' => 'preparing', 'maintenance_original' => $maintenanceOriginal, 'maintenance_changed' => false, 'swaps' => [], 'backup' => $nextBackup, 'updated_at' => gmdate(DATE_ATOM)];
        try {
            Fs::mkdir($nextBackup . '/components', 0700);
            $nextMetadata = ['schema' => 1, 'package' => $installed[0]->package, 'created_at' => gmdate(DATE_ATOM), 'components' => []];
            foreach ($installed as $manifest) {
                $key = $manifest->componentKey();
                $path = $nextBackup . '/components/' . rawurlencode($key);
                $inventory = Fs::copyVerified($manifest->destination($this->whmcsRoot), $path);
                $nextMetadata['components'][$key] = ['type' => $manifest->type, 'name' => $manifest->name, 'version' => $manifest->version, 'absent' => false, 'path' => 'components/' . rawurlencode($key), 'inventory' => $inventory];
            }
            Fs::atomicJson($nextBackup . '/metadata.json', $nextMetadata);
            foreach ($metadata['components'] as $key => $component) {
                if (!is_array($component) || !isset($component['type'], $component['name'])) {
                    throw new RuntimeException('Rollback component metadata is invalid');
                }
                $type = (string) $component['type'];
                $name = (string) $component['name'];
                if (!isset(Manifest::ROOTS[$type]) || !preg_match('/^[a-z][a-z0-9_-]*$/D', $name)) {
                    throw new RuntimeException('Rollback destination is invalid');
                }
                if ((string) $key !== $type . ':' . $name) {
                    throw new RuntimeException('Rollback component key is invalid');
                }
                $destination = $this->whmcsRoot . '/' . Manifest::ROOTS[$type] . '/' . $name;
                $absent = (bool) ($component['absent'] ?? false);
                $deployment = '';
                if (!$absent) {
                    if (!isset($component['path'], $component['inventory'])) {
                        throw new RuntimeException('Rollback component copy metadata is invalid');
                    }
                    if ($component['path'] !== 'components/' . rawurlencode((string) $key)) {
                        throw new RuntimeException('Rollback component copy path is invalid');
                    }
                    $source = dirname($metadataPath) . '/' . $component['path'];
                    if (Fs::inventory($source) !== $component['inventory']) {
                        throw new RuntimeException("Rollback copy verification failed for {$key}");
                    }
                    $deployment = dirname($destination) . '/.pluginupdater-new-' . $id . '-' . $name;
                    Fs::copyVerified($source, $deployment);
                }
                $journal['swaps'][] = ['component' => $key, 'destination' => $destination, 'deployment' => $deployment, 'old' => dirname($destination) . '/.pluginupdater-old-' . $id . '-' . $name, 'had_original' => is_dir($destination), 'swapped' => false, 'remove_only' => $absent];
            }
            $this->saveJournal($journalPath, $journal);
            if ($maintenance && !self::maintenanceEnabled($maintenanceOriginal)) {
                $journal['maintenance_changed'] = true;
                $this->saveJournal($journalPath, $journal);
                ($this->setMaintenance)('on');
            }
            $journal['status'] = 'swapping';
            foreach ($journal['swaps'] as $index => $swap) {
                if ($swap['had_original'] && !rename($swap['destination'], $swap['old'])) {
                    throw new RuntimeException("Unable to move {$swap['component']} for rollback");
                }
                if (!$swap['remove_only'] && !rename($swap['deployment'], $swap['destination'])) {
                    throw new RuntimeException("Unable to restore {$swap['component']}");
                }
                $journal['swaps'][$index]['swapped'] = true;
                $this->saveJournal($journalPath, $journal);
            }
            if (!rename($nextBackup, $root . '/' . $id)) {
                throw new RuntimeException('Unable to retain the pre-rollback version');
            }
            $journal['status'] = 'committed';
            $journal['backup'] = $root . '/' . $id;
            $this->saveJournal($journalPath, $journal);
            foreach ($journal['swaps'] as $swap) {
                try {
                    if (is_dir($swap['old'])) {
                        Fs::remove($swap['old']);
                    }
                    self::invalidateOpcache($swap['destination']);
                } catch (\Throwable $cleanupError) {
                    ($this->log)('Post-rollback cleanup warning: ' . $cleanupError->getMessage());
                }
            }
            try {
                Fs::remove(dirname($metadataPath));
            } catch (\Throwable $cleanupError) {
                ($this->log)('Rollback retention warning: ' . $cleanupError->getMessage());
            }
            try {
                ($this->log)("Rolled back {$installed[0]->package}");
            } catch (\Throwable) {
            }
        } catch (\Throwable $e) {
            if (($journal['status'] ?? null) === 'committed') {
                throw $e;
            }
            $this->reverse($journal);
            if (is_dir($nextBackup)) {
                Fs::remove($nextBackup);
            }
            $journal['status'] = 'recovered';
            $journal['error'] = $e->getMessage();
            $this->saveJournal($journalPath, $journal);
            throw $e;
        } finally {
            if ($journal['maintenance_changed']) {
                try {
                    ($this->setMaintenance)($maintenanceOriginal);
                    $journal['maintenance_changed'] = false;
                    $this->saveJournal($journalPath, $journal);
                } catch (\Throwable $maintenanceError) {
                    try {
                        ($this->log)('Unable to restore WHMCS maintenance mode: ' . $maintenanceError->getMessage());
                    } catch (\Throwable) {
                    }
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<string> */
    public static function executionTimeWarnings(): array
    {
        $current = (int) ini_get('max_execution_time');
        if ($current === 0 || $current >= 120) {
            return [];
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $effective = (int) ini_get('max_execution_time');
        return $effective === 0 || $effective >= 120 ? [] : ["PHP max_execution_time is {$effective}s; the update may be terminated before completion"];
    }

    /** @param list<Manifest> $incoming @param list<Manifest> $installed @return list<Manifest> */
    private function validateIncoming(array $incoming, array $installed, Release $release): array
    {
        $expectedPackage = $installed[0]->package;
        $expectedRepository = $installed[0]->repository;
        $expectedAsset = $installed[0]->asset;
        $keys = [];
        $roots = [];
        foreach ($incoming as $manifest) {
            if ($manifest->package !== $expectedPackage || $manifest->repository !== $expectedRepository || $manifest->asset !== $expectedAsset || $manifest->version !== $release->version) {
                throw new RuntimeException('Release manifests do not match the installed package and selected release');
            }
            $manifest->assertCompatible(PHP_VERSION, $this->whmcsVersion);
            if (isset($keys[$manifest->componentKey()])) {
                throw new RuntimeException("Duplicate release component {$manifest->componentKey()}");
            }
            $destination = $manifest->destination($this->whmcsRoot);
            if (!is_dir(dirname($destination))) {
                throw new RuntimeException("Component destination parent does not exist: " . dirname($destination));
            }
            $keys[$manifest->componentKey()] = true;
            $roots[] = dirname($manifest->manifestPath);
        }
        foreach ($installed as $manifest) {
            if (!isset($keys[$manifest->componentKey()])) {
                throw new RuntimeException("Release omits installed component {$manifest->componentKey()}");
            }
        }
        return $incoming;
    }

    /** @param list<Manifest> $incoming @param list<Manifest> $installed */
    private function assertFreeSpace(array $incoming, array $installed, int $archiveSize): void
    {
        $needed = $archiveSize;
        $targetNeeded = [];
        foreach ($incoming as $manifest) {
            $componentSize = 0;
            foreach (Fs::inventory(dirname($manifest->manifestPath)) as $file) {
                $needed += $file['size'];
                $componentSize += $file['size'];
            }
            $parent = dirname($manifest->destination($this->whmcsRoot));
            $stat = stat($parent);
            if ($stat === false) {
                throw new RuntimeException("Unable to inspect destination filesystem {$parent}");
            }
            $device = (string) $stat['dev'];
            $targetNeeded[$device]['path'] = $parent;
            $targetNeeded[$device]['bytes'] = ($targetNeeded[$device]['bytes'] ?? 0) + $componentSize;
        }
        foreach ($installed as $manifest) {
            foreach (Fs::inventory($manifest->destination($this->whmcsRoot)) as $file) {
                $needed += $file['size'];
            }
        }
        $free = disk_free_space($this->storage);
        if ($free === false || $free < $needed + 50 * 1024 * 1024) {
            throw new RuntimeException('Insufficient free disk space for staging and rollback');
        }
        foreach ($targetNeeded as $target) {
            $parent = $target['path'];
            $bytes = $target['bytes'];
            $targetFree = disk_free_space($parent);
            if ($targetFree === false || $targetFree < $bytes + 20 * 1024 * 1024) {
                throw new RuntimeException("Insufficient free disk space for deployment under {$parent}");
            }
        }
    }

    private function saveJournal(string $path, array &$journal): void
    {
        $journal['updated_at'] = gmdate(DATE_ATOM);
        Fs::atomicJson($path, $journal);
    }

    private function reverse(array $journal): void
    {
        foreach (array_reverse($journal['swaps'] ?? []) as $swap) {
            if (!is_array($swap)) {
                continue;
            }
            if (is_dir($swap['old'] ?? '')) {
                if (is_dir($swap['destination'])) {
                    Fs::remove($swap['destination']);
                }
                if (!rename($swap['old'], $swap['destination'])) {
                    throw new RuntimeException("Unable to restore {$swap['component']}");
                }
            } elseif (!($swap['had_original'] ?? true)
                && !file_exists($swap['deployment'] ?? '') && !is_link($swap['deployment'] ?? '')
                && is_dir($swap['destination'] ?? '')) {
                Fs::remove($swap['destination']);
            }
            if (is_dir($swap['deployment'] ?? '')) {
                Fs::remove($swap['deployment']);
            }
        }
    }

    private function retainOnly(string $root, string $keep): void
    {
        foreach (new DirectoryIterator($root) as $entry) {
            if (!$entry->isDot() && $entry->getFilename() !== $keep) {
                Fs::remove($entry->getPathname());
            }
        }
    }

    /** @param list<Manifest> $manifests */
    private function assertNewDestinationsAvailable(array $manifests): void
    {
        foreach ($manifests as $manifest) {
            $destination = $manifest->destination($this->whmcsRoot);
            if (file_exists($destination) || is_link($destination)) {
                throw new RuntimeException("New component destination already exists: {$destination}");
            }
        }
    }

    private static function maintenanceEnabled(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'on', 'true', 'yes'], true);
    }

    private static function invalidateOpcache(string $root): void
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        foreach (Fs::inventory($root) as $relative => $_) {
            if (str_ends_with(strtolower($relative), '.php')) {
                @opcache_invalidate($root . '/' . $relative, true);
            }
        }
    }
}

final class Recovery
{
    /** @param callable(string):void $setMaintenance @param callable(string):void $log */
    public static function pending(string $storage, callable $setMaintenance, callable $log): int
    {
        $directory = $storage . '/transactions';
        if (!is_dir($directory)) {
            return 0;
        }
        $lock = fopen($storage . '/updater.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        $recovered = 0;
        try {
            foreach (glob($directory . '/*.json') ?: [] as $path) {
                try {
                    $journal = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($journal) || ($journal['status'] ?? '') === 'recovered') {
                        continue;
                    }
                    if (($journal['status'] ?? '') === 'committed') {
                        self::cleanupCommitted($journal);
                        continue;
                    }
                    self::reverseJournal($journal);
                    if (($journal['maintenance_changed'] ?? false) && is_string($journal['maintenance_original'] ?? null)) {
                        $setMaintenance($journal['maintenance_original']);
                        $journal['maintenance_changed'] = false;
                    }
                    $journal['status'] = 'recovered';
                    $journal['updated_at'] = gmdate(DATE_ATOM);
                    Fs::atomicJson($path, $journal);
                    ++$recovered;
                    $log("Recovered interrupted transaction {$journal['id']}");
                } catch (\Throwable $e) {
                    $log("Unable to recover transaction " . basename($path) . ': ' . $e->getMessage());
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $recovered;
    }

    /** @param array<string,mixed> $journal */
    private static function reverseJournal(array $journal): void
    {
        if (($journal['schema'] ?? null) !== 1 || !is_array($journal['swaps'] ?? null)) {
            throw new RuntimeException('Invalid transaction journal');
        }
        foreach (array_reverse($journal['swaps']) as $swap) {
            if (!is_array($swap) || !isset($swap['destination'], $swap['old'], $swap['deployment'], $swap['had_original'])) {
                throw new RuntimeException('Invalid transaction swap record');
            }
            self::assertSwap($swap, (string) ($journal['whmcs_root'] ?? ''));
            if (is_dir($swap['old'])) {
                if (is_dir($swap['destination'])) {
                    Fs::remove($swap['destination']);
                }
                if (!rename($swap['old'], $swap['destination'])) {
                    throw new RuntimeException("Unable to recover {$swap['destination']}");
                }
            } elseif (!$swap['had_original']
                && !file_exists($swap['deployment']) && !is_link($swap['deployment'])
                && is_dir($swap['destination'])) {
                Fs::remove($swap['destination']);
            }
            if (is_dir($swap['deployment'])) {
                Fs::remove($swap['deployment']);
            }
        }
    }

    /** @param array<string,mixed> $journal */
    private static function cleanupCommitted(array $journal): void
    {
        if (($journal['schema'] ?? null) !== 1 || !is_array($journal['swaps'] ?? null)) {
            throw new RuntimeException('Invalid committed transaction journal');
        }
        foreach ($journal['swaps'] as $swap) {
            if (!is_array($swap)) {
                throw new RuntimeException('Invalid committed transaction swap record');
            }
            self::assertSwap($swap, (string) ($journal['whmcs_root'] ?? ''));
            foreach (['old', 'deployment'] as $key) {
                if (($swap[$key] ?? '') !== '' && is_dir($swap[$key])) {
                    Fs::remove($swap[$key]);
                }
            }
        }
    }

    /** @param array<string,mixed> $swap */
    private static function assertSwap(array $swap, string $whmcsRoot): void
    {
        $destination = (string) $swap['destination'];
        $old = (string) $swap['old'];
        $deployment = (string) $swap['deployment'];
        $removeOnly = (bool) ($swap['remove_only'] ?? false);
        $allowedParents = array_map(static fn (string $root): string => $whmcsRoot . '/' . $root, Manifest::ROOTS);
        if ($destination === '' || $whmcsRoot === '' || !in_array(dirname($destination), $allowedParents, true)
            || !preg_match('/^[a-z][a-z0-9_-]*$/D', basename($destination))
            || dirname($destination) !== dirname($old) || (!$removeOnly && dirname($destination) !== dirname($deployment))
            || !str_starts_with(basename($old), '.pluginupdater-old-')
            || (!$removeOnly && !str_starts_with(basename($deployment), '.pluginupdater-new-'))) {
            throw new RuntimeException('Unsafe transaction recovery path');
        }
    }
}
