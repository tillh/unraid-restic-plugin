#!/usr/bin/php
<?php
declare(strict_types=1);

const RESTIC_PLUGIN_NAME = 'restic';
const RESTIC_PLUGIN_DIR = '/usr/local/emhttp/plugins/restic';
const RESTIC_DATA_DIR = '/boot/config/plugins/restic';
const RESTIC_BIN_DIR = RESTIC_DATA_DIR . '/bin';
const RESTIC_STATE_FILE = RESTIC_DATA_DIR . '/state.json';
const RESTIC_PERSISTENT_BINARY = RESTIC_BIN_DIR . '/restic';
const RESTIC_RUNTIME_BINARY = '/usr/local/bin/restic';
const RESTIC_WRAPPER_BINARY = RESTIC_PLUGIN_DIR . '/bin/restic-wrapper';
const RESTIC_MANAGER_PATH = '/usr/local/sbin/restic-manager';
const RESTIC_RELEASE_API = 'https://api.github.com/repos/restic/restic/releases/latest';
const RESTIC_HTTP_USER_AGENT = 'unraid-restic-plugin';

final class ResticManager
{
    public static function status(bool $checkLatest = true): array
    {
        $installed = self::hasPersistentBinary();
        $installedVersion = $installed ? self::detectInstalledVersion() : null;
        $latestVersion = null;
        $latestTag = null;
        $latestError = null;

        if ($checkLatest) {
            try {
                $latest = self::fetchLatestRelease();
                $latestVersion = $latest['version'];
                $latestTag = $latest['tag'];
            } catch (\Throwable $throwable) {
                $latestError = $throwable->getMessage();
            }
        }

        $updateAvailable = false;
        if ($installedVersion !== null && $latestVersion !== null) {
            $updateAvailable = version_compare($installedVersion, $latestVersion, '<');
        }

        self::ensureRuntimeLink();

        $runtimeLink = null;
        if (is_link(RESTIC_RUNTIME_BINARY)) {
            $runtimeLink = readlink(RESTIC_RUNTIME_BINARY) ?: null;
        }

        $note = $installed
            ? ($updateAvailable ? 'An update is available.' : 'Restic is installed.')
            : 'Restic is not installed.';

        if ($latestError !== null) {
            $note = $note . ' Latest version check failed: ' . $latestError;
        }

        return [
            'installed' => $installed,
            'installedVersion' => $installedVersion,
            'latestVersion' => $latestVersion,
            'latestTag' => $latestTag,
            'updateAvailable' => $updateAvailable,
            'binaryPath' => RESTIC_RUNTIME_BINARY,
            'persistentBinaryPath' => RESTIC_PERSISTENT_BINARY,
            'managerPath' => RESTIC_MANAGER_PATH,
            'runtimeLink' => $runtimeLink,
            'note' => $note,
        ];
    }

    public static function installLatest(): array
    {
        self::ensureDirectories();

        $release = self::fetchLatestRelease();
        $currentVersion = self::hasPersistentBinary() ? self::detectInstalledVersion() : null;

        if ($currentVersion !== null && version_compare($currentVersion, $release['version'], '>=')) {
        self::ensureRuntimeLink();

        return [
            'message' => sprintf('restic %s is already installed.', $currentVersion),
                'status' => self::status(true),
            ];
        }

        $asset = self::selectBinaryAsset($release);
        $checksumUrl = self::selectChecksumUrl($release);
        $archivePath = RESTIC_BIN_DIR . '/restic.download.bz2';
        $binaryTempPath = RESTIC_BIN_DIR . '/restic.new';

        self::deleteIfExists($archivePath);
        self::deleteIfExists($binaryTempPath);

        self::downloadToFile($asset['browser_download_url'], $archivePath);

        $checksums = self::downloadText($checksumUrl);
        $expectedChecksum = self::extractChecksum($checksums, $asset['name']);
        $actualChecksum = hash_file('sha256', $archivePath);

        if ($expectedChecksum !== $actualChecksum) {
            self::deleteIfExists($archivePath);
            throw new \RuntimeException(sprintf(
                'Checksum verification failed for %s. Expected %s, got %s.',
                $asset['name'],
                $expectedChecksum,
                $actualChecksum
            ));
        }

        self::decompressArchive($archivePath, $binaryTempPath);
        chmod($binaryTempPath, 0755);

        self::moveIntoPlace($binaryTempPath, RESTIC_PERSISTENT_BINARY);
        self::deleteIfExists($archivePath);

        self::writeState([
            'installed_version' => $release['version'],
            'tag' => $release['tag'],
            'asset_name' => $asset['name'],
            'asset_url' => $asset['browser_download_url'],
            'installed_at' => gmdate(DATE_ATOM),
        ]);

        self::ensureRuntimeLink();

        return [
            'message' => sprintf('Installed restic %s.', $release['version']),
            'status' => self::status(true),
        ];
    }

    public static function ensureRuntime(): array
    {
        self::ensureRuntimeLink();
        if (!self::hasPersistentBinary()) {
            return self::installLatest();
        }

        return [
            'message' => sprintf('Runtime ready for restic %s.', self::detectInstalledVersion() ?? 'unknown'),
            'status' => self::status(true),
        ];
    }

    public static function removeManagedBinary(): array
    {
        if (is_link(RESTIC_RUNTIME_BINARY)) {
            $target = readlink(RESTIC_RUNTIME_BINARY);
            if ($target === RESTIC_WRAPPER_BINARY) {
                self::deleteIfExists(RESTIC_RUNTIME_BINARY);
            }
        }

        self::deleteIfExists(RESTIC_PERSISTENT_BINARY);
        self::deleteIfExists(RESTIC_BIN_DIR . '/restic.download.bz2');
        self::deleteIfExists(RESTIC_BIN_DIR . '/restic.new');
        self::deleteIfExists(RESTIC_STATE_FILE);

        return [
            'message' => 'Removed the managed restic binary.',
            'status' => self::status(true),
        ];
    }

    public static function latest(): array
    {
        $release = self::fetchLatestRelease();

        return [
            'tag' => $release['tag'],
            'version' => $release['version'],
            'asset' => self::selectBinaryAsset($release)['name'],
        ];
    }

    private static function fetchLatestRelease(): array
    {
        $body = self::downloadText(RESTIC_RELEASE_API, [
            'Accept: application/vnd.github+json',
        ]);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload) || empty($payload['tag_name']) || empty($payload['assets'])) {
            throw new \RuntimeException('Latest release metadata is incomplete.');
        }

        return [
            'tag' => (string) $payload['tag_name'],
            'version' => ltrim((string) $payload['tag_name'], 'v'),
            'assets' => array_values(array_filter($payload['assets'], 'is_array')),
        ];
    }

    private static function selectBinaryAsset(array $release): array
    {
        $arch = self::detectedResticArch();
        $expectedName = sprintf('restic_%s_linux_%s.bz2', $release['version'], $arch);

        foreach ($release['assets'] as $asset) {
            if (($asset['name'] ?? '') === $expectedName && !empty($asset['browser_download_url'])) {
                return $asset;
            }
        }

        throw new \RuntimeException(sprintf(
            'Unable to find a restic asset for architecture %s.',
            $arch
        ));
    }

    private static function selectChecksumUrl(array $release): string
    {
        foreach ($release['assets'] as $asset) {
            if (($asset['name'] ?? '') === 'SHA256SUMS' && !empty($asset['browser_download_url'])) {
                return (string) $asset['browser_download_url'];
            }
        }

        return sprintf(
            'https://github.com/restic/restic/releases/download/%s/SHA256SUMS',
            rawurlencode($release['tag'])
        );
    }

    private static function detectedResticArch(): string
    {
        $machine = php_uname('m');

        return match ($machine) {
            'x86_64', 'amd64' => 'amd64',
            'i386', 'i486', 'i586', 'i686' => '386',
            'aarch64', 'arm64' => 'arm64',
            'armv5l', 'armv6l', 'armv7l' => 'arm',
            default => throw new \RuntimeException(sprintf('Unsupported architecture: %s', $machine)),
        };
    }

    private static function ensureDirectories(): void
    {
        foreach ([RESTIC_DATA_DIR, RESTIC_BIN_DIR] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Unable to create directory: %s', $directory));
            }
        }
    }

    private static function hasPersistentBinary(): bool
    {
        return is_file(RESTIC_PERSISTENT_BINARY) && is_executable(RESTIC_PERSISTENT_BINARY);
    }

    private static function detectInstalledVersion(): ?string
    {
        if (!self::hasPersistentBinary()) {
            $state = self::readState();
            return isset($state['installed_version']) ? (string) $state['installed_version'] : null;
        }

        try {
            $output = self::runCommand([RESTIC_PERSISTENT_BINARY, 'version', '--json']);
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            if (isset($payload['version']) && is_string($payload['version'])) {
                return ltrim($payload['version'], 'v');
            }
        } catch (\Throwable) {
        }

        try {
            $output = self::runCommand([RESTIC_PERSISTENT_BINARY, 'version']);
            if (preg_match('/restic\s+([0-9]+\.[0-9]+\.[0-9]+)/i', $output, $matches) === 1) {
                return $matches[1];
            }
        } catch (\Throwable) {
        }

        $state = self::readState();
        return isset($state['installed_version']) ? (string) $state['installed_version'] : null;
    }

    private static function ensureRuntimeLink(): void
    {
        if (!is_file(RESTIC_WRAPPER_BINARY) || !is_executable(RESTIC_WRAPPER_BINARY)) {
            throw new \RuntimeException(sprintf(
                'Missing restic wrapper at %s.',
                RESTIC_WRAPPER_BINARY
            ));
        }

        if (file_exists(RESTIC_RUNTIME_BINARY) || is_link(RESTIC_RUNTIME_BINARY)) {
            if (is_link(RESTIC_RUNTIME_BINARY)) {
                $target = readlink(RESTIC_RUNTIME_BINARY);
                if ($target === RESTIC_WRAPPER_BINARY) {
                    return;
                }

                self::deleteIfExists(RESTIC_RUNTIME_BINARY);
            } else {
                throw new \RuntimeException(sprintf(
                    'Refusing to replace unmanaged binary at %s.',
                    RESTIC_RUNTIME_BINARY
                ));
            }
        }

        if (!symlink(RESTIC_WRAPPER_BINARY, RESTIC_RUNTIME_BINARY)) {
            throw new \RuntimeException(sprintf(
                'Unable to create runtime link at %s.',
                RESTIC_RUNTIME_BINARY
            ));
        }
    }

    private static function writeState(array $state): void
    {
        self::ensureDirectories();

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents(RESTIC_STATE_FILE, $json . PHP_EOL) === false) {
            throw new \RuntimeException('Unable to write restic state.');
        }
    }

    private static function readState(): array
    {
        if (!is_file(RESTIC_STATE_FILE)) {
            return [];
        }

        $contents = file_get_contents(RESTIC_STATE_FILE);
        if ($contents === false || $contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function downloadText(string $url, array $extraHeaders = []): string
    {
        $stream = self::openRemoteStream($url, $extraHeaders);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read response from %s.', $url));
        }

        return $contents;
    }

    private static function downloadToFile(string $url, string $destination): void
    {
        $stream = self::openRemoteStream($url);
        $output = fopen($destination, 'wb');

        if ($output === false) {
            fclose($stream);
            throw new \RuntimeException(sprintf('Unable to open %s for writing.', $destination));
        }

        $bytes = stream_copy_to_stream($stream, $output);
        fclose($stream);
        fclose($output);

        if ($bytes === false || $bytes === 0) {
            self::deleteIfExists($destination);
            throw new \RuntimeException(sprintf('Download from %s returned no data.', $url));
        }
    }

    private static function openRemoteStream(string $url, array $extraHeaders = [])
    {
        $headers = array_merge([
            'User-Agent: ' . RESTIC_HTTP_USER_AGENT,
        ], $extraHeaders);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 120,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = self::extractStatusCode($responseHeaders);

        if ($stream === false || $statusCode >= 400) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new \RuntimeException(sprintf(
                'Request to %s failed with HTTP status %s.',
                $url,
                $statusCode > 0 ? (string) $statusCode : 'unknown'
            ));
        }

        return $stream;
    }

    private static function extractStatusCode(array $headers): int
    {
        $statusCode = 0;

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $statusCode = (int) $matches[1];
            }
        }

        return $statusCode;
    }

    private static function extractChecksum(string $checksums, string $assetName): string
    {
        foreach (preg_split('/\r?\n/', trim($checksums)) as $line) {
            if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim($line), $matches) === 1) {
                if ($matches[2] === $assetName) {
                    return strtolower($matches[1]);
                }
            }
        }

        throw new \RuntimeException(sprintf('Unable to find checksum for %s.', $assetName));
    }

    private static function decompressArchive(string $archivePath, string $destination): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $destination, 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['bzip2', '-dc', $archivePath], $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start bzip2 to unpack restic.');
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            self::deleteIfExists($destination);
            throw new \RuntimeException(sprintf(
                'Failed to unpack restic archive: %s',
                trim($stderr) !== '' ? trim($stderr) : 'bzip2 exited with a non-zero status.'
            ));
        }
    }

    private static function moveIntoPlace(string $source, string $destination): void
    {
        self::deleteIfExists($destination);

        if (@rename($source, $destination)) {
            return;
        }

        if (!@copy($source, $destination)) {
            throw new \RuntimeException(sprintf('Unable to move %s into place.', $destination));
        }

        self::deleteIfExists($source);
    }

    private static function runCommand(array $command): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Unable to start command: %s', implode(' ', $command)));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException(trim($stderr) !== '' ? trim($stderr) : sprintf(
                'Command failed: %s',
                implode(' ', $command)
            ));
        }

        return trim((string) $stdout);
    }

    private static function deleteIfExists(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        }
    }
}

function restic_cli_main(array $argv): int
{
    $command = $argv[1] ?? 'status';
    $json = in_array('--json', $argv, true);

    try {
        $result = match ($command) {
            'status' => ['status' => ResticManager::status(true)],
            'install', 'update' => ResticManager::installLatest(),
            'ensure-runtime' => ResticManager::ensureRuntime(),
            'remove' => ResticManager::removeManagedBinary(),
            'latest' => ['latest' => ResticManager::latest()],
            'help', '--help', '-h' => null,
            default => throw new \InvalidArgumentException(sprintf('Unknown command: %s', $command)),
        };

        if ($result === null) {
            echo <<<TXT
Usage: restic-manager <command> [--json]

Commands:
  status          Show installed and latest versions
  install         Install the latest stable restic release
  update          Alias for install
  ensure-runtime  Restore /usr/local/bin/restic from the persisted binary
  remove          Remove the managed restic binary
  latest          Show the latest available release

TXT;
            return 0;
        }

        if ($json) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return 0;
        }

        if (isset($result['latest'])) {
            echo sprintf("Latest restic: %s (%s)\n", $result['latest']['version'], $result['latest']['tag']);
            echo sprintf("Asset: %s\n", $result['latest']['asset']);
            return 0;
        }

        if (isset($result['message'])) {
            echo $result['message'] . PHP_EOL;
        }

        if (isset($result['status'])) {
            $status = $result['status'];
            echo sprintf("Installed: %s\n", $status['installed'] ? ($status['installedVersion'] ?? 'unknown') : 'no');
            echo sprintf("Latest: %s\n", $status['latestVersion'] ?? 'unavailable');
            echo sprintf("Update available: %s\n", $status['updateAvailable'] ? 'yes' : 'no');
            echo sprintf("Binary: %s\n", $status['binaryPath']);
        }

        return 0;
    } catch (\Throwable $throwable) {
        if ($json) {
            echo json_encode([
                'success' => false,
                'error' => $throwable->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        } else {
            fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
        }

        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(restic_cli_main($argv));
}
