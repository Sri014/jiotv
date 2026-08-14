<?php

/*
╔══════════════════════════════════════════════════════════════════════╗
║                    JioTV+ Proxy Localhost                            ║
║                                                                      ║
║                    Crafted With 💚 by LazyyXD                        ║
╚══════════════════════════════════════════════════════════════════════╝
*/

require_once __DIR__ . '/../api/bootstrap.php';

function normalizeFormat(string $format): string
{
    $format = strtolower(trim($format));

    if ($format === 'm3u8' || $format === 'hls') {
        return 'm3u8';
    }

    if ($format === 'mpd' || $format === 'dash') {
        return 'mpd';
    }

    respondError('Invalid format. Use m3u8 or mpd', 400);
}

function extractExpiry(string $url): int
{
    if (
        preg_match(
            '/(?:[?&])exp=(\d+)/i',
            $url,
            $matches
        ) !== 1
    ) {
        return 0;
    }

    return (int) $matches[1];
}

function convertManifestFormat(string $url, string $format): string
{
    if ($format === 'm3u8') {
        return preg_replace(
            '/index\.mpd(?=($|[?#]))/i',
            'index.m3u8',
            $url
        ) ?? $url;
    }

    return preg_replace(
        '/index\.m3u8(?=($|[?#]))/i',
        'index.mpd',
        $url
    ) ?? $url;
}

function isUsableCachedEntry(mixed $entry): bool
{
    return is_array($entry) &&
        isset($entry['url'], $entry['exp']) &&
        is_string($entry['url']) &&
        filter_var($entry['url'], FILTER_VALIDATE_URL) !== false &&
        is_numeric($entry['exp']) &&
        (int) $entry['exp'] > 0;
}

function isAllowedRedirectUrl(string $url): bool
{
    $parts = parse_url($url);

    if (
        !is_array($parts) ||
        empty($parts['scheme']) ||
        empty($parts['host'])
    ) {
        return false;
    }

    if (
        strtolower($parts['scheme']) !== 'https' &&
        strtolower($parts['scheme']) !== 'http'
    ) {
        return false;
    }

    return true;
}

function refreshCdnUrl(string $url): array
{
    $curl = curl_init($url);

    if ($curl === false) {
        return [
            'ok' => false,
            'url' => $url,
            'exp' => 0,
            'status' => 0,
            'error' => 'Unable to initialize CDN request',
            'errno' => 0
        ];
    }

    $responseHeaders = [];

    curl_setopt_array(
        $curl,
        [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => CDN_TIMEOUT,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $headerLine = trim($headerLine);

                if ($headerLine === '' || strpos($headerLine, ':') === false) {
                    return $length;
                }

                [$name, $value] = explode(':', $headerLine, 2);

                $name = strtolower(trim($name));
                $value = trim($value);

                $responseHeaders[$name][] = $value;

                return $length;
            }
        ]
    );

    curl_exec($curl);

    $curlErrorNumber = curl_errno($curl);
    $curlErrorMessage = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($curlErrorNumber !== 0 || $httpCode < 200 || $httpCode >= 400) {
        return [
            'ok' => false,
            'url' => $url,
            'exp' => 0,
            'status' => $httpCode,
            'error' => $curlErrorMessage,
            'errno' => $curlErrorNumber
        ];
    }

    $cookies = $responseHeaders['set-cookie'] ?? [];
    $newToken = '';

    foreach ($cookies as $cookie) {
        if (
            preg_match(
                '/^__hdnea__=([^;]+)/i',
                $cookie,
                $matches
            ) === 1
        ) {
            $newToken = trim($matches[1]);
            break;
        }
    }

    if ($newToken === '') {
        return [
            'ok' => false,
            'url' => $url,
            'exp' => 0,
            'status' => $httpCode,
            'error' => 'No refreshed CDN token found',
            'errno' => 0
        ];
    }

    $newExpiry = extractExpiry($newToken);

    if ($newExpiry <= time()) {
        return [
            'ok' => false,
            'url' => $url,
            'exp' => 0,
            'status' => $httpCode,
            'error' => 'Received expired CDN token',
            'errno' => 0
        ];
    }

    $updatedUrl = preg_replace(
        '/([?&])__hdnea__=[^&]*/i',
        '$1__hdnea__=' . rawurlencode($newToken),
        $url,
        1,
        $replacementCount
    );

    if ($updatedUrl === null || $replacementCount < 1) {
        $separator = str_contains($url, '?') ? '&' : '?';
        $updatedUrl = $url . $separator . '__hdnea__=' . rawurlencode($newToken);
    }

    return [
        'ok' => true,
        'url' => $updatedUrl,
        'exp' => $newExpiry,
        'status' => $httpCode,
        'error' => '',
        'errno' => 0
    ];
}

function fetchStreamRedirect(string $url, string $apiKey): array
{
    $lastResult = [
        'ok' => false,
        'status' => 0,
        'location' => '',
        'body' => '',
        'content_type' => '',
        'error' => 'Unable to contact stream service',
        'errno' => 0
    ];

    for ($attempt = 0; $attempt <= MAX_RETRIES; $attempt++) {
        $curl = curl_init($url);

        if ($curl === false) {
            return $lastResult;
        }

        $location = '';
        $responseHeaders = [];

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_HTTPGET => true,
                CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: */*',
                    'User-Agent: JioTV-Proxy-Localhost/1.0',
                    'X-API-Key: ' . $apiKey
                ],
                CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$location, &$responseHeaders): int {
                    $length = strlen($headerLine);
                    $headerLine = trim($headerLine);

                    if ($headerLine === '') {
                        return $length;
                    }

                    if (stripos($headerLine, 'Location:') === 0) {
                        $location = trim(substr($headerLine, strlen('Location:')));
                        return $length;
                    }

                    if (strpos($headerLine, ':') !== false) {
                        [$name, $value] = explode(':', $headerLine, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }

                    return $length;
                }
            ]
        );

        $responseBody = curl_exec($curl);
        $curlErrorNumber = curl_errno($curl);
        $curlErrorMessage = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

        curl_close($curl);

        $body = is_string($responseBody) ? $responseBody : '';

        $lastResult = [
            'ok' => $curlErrorNumber === 0 && $httpCode >= 200 && $httpCode < 400,
            'status' => $httpCode,
            'location' => $location,
            'body' => $body,
            'content_type' => $contentType,
            'error' => $curlErrorMessage,
            'errno' => $curlErrorNumber
        ];

        $retryable = $attempt < MAX_RETRIES && (
            isRetryableCurlError($curlErrorNumber) ||
            isRetryableHttpStatus($httpCode)
        );

        if (!$retryable) {
            break;
        }

        usleep(250000 * (2 ** $attempt));
    }

    return $lastResult;
}

function sendRedirectToLocation(string $location): never
{
    if (!isAllowedRedirectUrl($location)) {
        respondError('Invalid stream redirect URL', 502);
    }

    header('Cache-Control: private, no-store');
    header('Location: ' . $location, true, 302);
    exit;
}

const FALLBACK_HLS = 'https://embed-cloudfront.wistia.com/deliveries/a5b3ad5a1ef03c8c323a1bf5e2e9ae731ad09d89.m3u8';
const FALLBACK_MPD = 'https://axis.lazyspace.online/assets/status/geoblocked/index.mpd';

function getClientIp(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_X_CLIENT_IP',
        'REMOTE_ADDR'
    ];

    foreach ($headers as $header) {
        $value = $_SERVER[$header] ?? '';

        if (!is_string($value) || $value === '') {
            continue;
        }

        $ip = trim(explode(',', $value)[0]);

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

function isPrivateOrLocalIp(string $ip): bool
{
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
        return true;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }

    return preg_match(
        '/^(127\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|192\.0\.0\.|192\.0\.2\.|198\.51\.100\.|203\.0\.113\.|169\.254\.|100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|0\.|fc[0-9a-f]{2}:|fd[0-9a-f]{2}:|fe80:)/i',
        $ip
    ) === 1;
}

function isIndianIp(string $ip): bool
{
    if ($ip === '' || isPrivateOrLocalIp($ip)) {
        return true;
    }

    $curl = curl_init('https://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode,message');

    if ($curl === false) {
        return true;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($curl);
    $errno = curl_errno($curl);
    curl_close($curl);

    if ($errno !== 0 || !is_string($response) || $response === '') {
        return true;
    }

    try {
        $data = json_decode($response, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return true;
    }

    if (!is_array($data)) {
        return true;
    }

    if (($data['status'] ?? '') === 'fail') {
        return true;
    }

    return ($data['countryCode'] ?? '') === 'IN';
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, OPTIONS');
    respondError('Method Not Allowed', 405);
}

$channelId = $_GET['id'] ?? '';
$requestedFormat = $_GET['fmt'] ?? 'm3u8';

if (!is_string($channelId)) {
    respondError('Invalid channel ID', 400);
}

if (!is_string($requestedFormat)) {
    respondError('Invalid stream format', 400);
}

$channelId = trim($channelId);

if ($channelId === '') {
    respondError('Missing channel ID', 400);
}

if (!isValidChannelId($channelId)) {
    respondError('Invalid channel ID format', 400);
}

$format = normalizeFormat($requestedFormat);

$clientIp = getClientIp();

if (!isIndianIp($clientIp)) {
    $fallback = $format === 'mpd' ? FALLBACK_MPD : FALLBACK_HLS;
    sendRedirectToLocation($fallback);
}

[, $apiKey] = loadSession($sessionFile);

$cachedData = readJsonFile($streamUrlsFile);
$cachedEntry = $cachedData[$channelId] ?? null;

if (isUsableCachedEntry($cachedEntry)) {
    $cachedUrl = $cachedEntry['url'];
    $cachedExpiry = (int) $cachedEntry['exp'];

    if (time() < ($cachedExpiry - CACHE_BUFFER)) {
        $location = convertManifestFormat($cachedUrl, $format);
        sendRedirectToLocation($location);
    }

    $refreshResult = refreshCdnUrl($cachedUrl);

    if ($refreshResult['ok']) {
        $cachedData[$channelId] = [
            'url' => $refreshResult['url'],
            'exp' => $refreshResult['exp'],
            'updated_at' => gmdate('c')
        ];

        writeJsonFile($streamUrlsFile, $cachedData);

        $location = convertManifestFormat($refreshResult['url'], $format);
        sendRedirectToLocation($location);
    }
}

$encodedChannelId = rawurlencode($channelId);

$streamUrl = VERCEL_URL
    . STREAM_ENDPOINT
    . $encodedChannelId
    . '?fmt='
    . rawurlencode($format);

$result = fetchStreamRedirect($streamUrl, $apiKey);

if (!$result['ok'] && $result['body'] === '') {
    respondError(
        'Unable to contact stream service',
        502,
        [
            'upstream_status' => $result['status'],
            'curl_errno' => $result['errno']
        ]
    );
}

if ($result['location'] !== '') {
    $location = $result['location'];

    if (!isAllowedRedirectUrl($location)) {
        respondError('Invalid stream redirect URL', 502);
    }

    $expiry = extractExpiry($location);

    if ($expiry > time()) {
        $cachedData[$channelId] = [
            'url' => $location,
            'exp' => $expiry,
            'updated_at' => gmdate('c')
        ];

        writeJsonFile($streamUrlsFile, $cachedData);
    }

    $location = convertManifestFormat($location, $format);
    sendRedirectToLocation($location);
}

if ($result['body'] !== '') {
    http_response_code(
        $result['status'] > 0 ? $result['status'] : 502
    );

    if ($result['content_type'] !== '') {
        header('Content-Type: ' . $result['content_type']);
    } else {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo $result['body'];
    exit;
}

respondError(
    'Stream service returned no redirect',
    502,
    ['upstream_status' => $result['status']]
);
