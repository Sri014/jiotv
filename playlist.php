<?php

/*
╔══════════════════════════════════════════════════════════════════════╗
║                    JioTV+ Proxy Localhost                            ║
║                                                                      ║
║                    Crafted With 💚 by LazyyXD                        ║
╚══════════════════════════════════════════════════════════════════════╝
*/

require_once __DIR__ . '/api/bootstrap.php';

function respondPlaylistError(
    string $message,
    int $statusCode,
    array $extra = []
): never {
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');

    echo $message;

    if (!empty($extra)) {
        echo "\n";
        echo json_encode(
            $extra,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );
    }

    exit;
}

function escapeM3uAttribute(mixed $value): string
{
    $value = is_scalar($value) ? (string) $value : '';

    $value = str_replace(
        ["\r", "\n", "\t", '"'],
        [' ', ' ', ' ', '&quot;'],
        $value
    );

    return trim($value);
}

function normalizeBoolean(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int) $value === 1;
    }

    if (is_string($value)) {
        return in_array(
            strtolower(trim($value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    return false;
}

function normalizeStreamType(mixed $value): string
{
    $value = strtolower(
        trim(is_scalar($value) ? (string) $value : '')
    );

    return $value === 'dash' ? 'dash' : 'hls';
}

function getBaseUrl(): string
{
    $isHttps = false;

    if (!empty($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === '1')) {
        $isHttps = true;
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $isHttps = true;
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        $isHttps = true;
    } elseif (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        $isHttps = true;
    }

    $protocol = $isHttps ? 'https' : 'http';

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    if (str_contains($host, ',')) {
        $host = trim(explode(',', $host)[0]);
    }

    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $host);
    if ($host === '') {
        $host = 'localhost';
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    $dir = rtrim($dir, '/');

    if ($dir === '' || $dir === '.' || $dir === '/' || str_contains($dir, ':')) {
        $dir = '';
    }

    return $protocol . '://' . $host . $dir;
}

function buildPlaylist(array $channels, string $baseUrl): string
{
    $lines = [
        '#EXTM3U x-tvg-url="https://tsepg.cf/epg.xml.gz"'
    ];

    foreach ($channels as $channel) {
        if (!is_array($channel)) {
            continue;
        }

        $id = escapeM3uAttribute($channel['tvg-id'] ?? '');

        if ($id === '') {
            continue;
        }

        $name = escapeM3uAttribute(
            $channel['tvg-nam'] ?? $channel['name'] ?? $id
        );

        $logo = escapeM3uAttribute($channel['tvg-logo'] ?? '');
        $groupTitle = escapeM3uAttribute($channel['group-title'] ?? '');
        $groupLogo = escapeM3uAttribute($channel['group-logo'] ?? '');
        $streamType = normalizeStreamType($channel['stream-type'] ?? 'hls');
        $isDrm = normalizeBoolean($channel['is-drm'] ?? false);

        $manifestExtension = $streamType === 'dash' ? 'mpd' : 'm3u8';
        $encodedId = rawurlencode($id);

        $streamUrl = $baseUrl . '/uplink/' . $encodedId . '.' . $manifestExtension;
        $licenseUrl = $baseUrl . '/uplink/' . $encodedId . '.json';

        $attributes = [
            'tvg-id="' . $id . '"',
            'tvg-name="' . $name . '"'
        ];

        if ($logo !== '') {
            $attributes[] = 'tvg-logo="' . $logo . '"';
        }

        if ($groupTitle !== '') {
            $attributes[] = 'group-title="' . $groupTitle . '"';
        }

        if ($groupLogo !== '') {
            $attributes[] = 'group-logo="' . $groupLogo . '"';
        }

        $lines[] = '#EXTINF:-1 ' . implode(' ', $attributes) . ',' . $name;

        $lines[] = $streamType === 'dash'
            ? '#KODIPROP:inputstream.adaptive.manifest_type=dash'
            : '#KODIPROP:inputstream.adaptive.manifest_type=hls';

        if ($isDrm) {
            $lines[] = '#KODIPROP:inputstream.adaptive.license_type=clearkey';
            $lines[] = '#KODIPROP:inputstream.adaptive.license_key=' . $licenseUrl;
        }

        $lines[] = $streamUrl;
        $lines[] = '';
    }

    return implode("\n", $lines) . "\n";
}

ensureDataDirectory($dataDirectory);

$sessionData = readJsonFile($sessionFile);

if (
    empty($sessionData) ||
    empty($sessionData['api_key']) ||
    !is_string($sessionData['api_key'])
) {
    respondPlaylistError('Not authenticated. Please login first.', 401);
}

$apiKey = trim($sessionData['api_key']);

if ($apiKey === '') {
    respondPlaylistError('Invalid session. Please login again.', 401);
}

$channels = readJsonFile($channelsFile);

if (count($channels) === 0) {
    $result = fetchChannels(
        VERCEL_URL . API_CHANNELS,
        $apiKey
    );

    if (!$result['ok']) {
        respondPlaylistError(
            'No channels available. Please login again.',
            502,
            [
                'upstream_status' => $result['status'],
                'curl_errno' => $result['errno']
            ]
        );
    }

    if ($result['body'] === '') {
        respondPlaylistError('Channels service returned an empty response.', 502);
    }

    try {
        $decodedChannels = json_decode(
            $result['body'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        respondPlaylistError('Channels service returned invalid JSON.', 502);
    }

    if (!is_array($decodedChannels) || count($decodedChannels) === 0) {
        respondPlaylistError('No valid channels were returned.', 502);
    }

    $channels = $decodedChannels;

    if (!writeJsonFile($channelsFile, $channels)) {
        respondPlaylistError('Channels loaded but could not be cached.', 500);
    }
}

$baseUrl = getBaseUrl();
$playlist = buildPlaylist($channels, $baseUrl);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $playlist;