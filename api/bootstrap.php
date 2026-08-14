<?php

/*
╔══════════════════════════════════════════════════════════════════════╗
║                    JioTV+ Proxy Localhost                            ║
║                                                                      ║
║                    Crafted With 💚 by LazyyXD                        ║
╚══════════════════════════════════════════════════════════════════════╝
*/

declare(strict_types=1);

const VERCEL_URL = 'https://jiotv-connect.vercel.app';

const API_SEND_OTP = '/api/auth/send-otp';
const API_VERIFY_OTP = '/api/auth/verify-otp';
const API_CHANNELS = '/api/connect/channels';
const API_ME = '/api/auth/me';
const STREAM_ENDPOINT = '/api/connect/stream/';
const LICENSE_ENDPOINT = '/api/connect/license/';

const CONNECT_TIMEOUT = 5;
const REQUEST_TIMEOUT = 20;
const STATUS_TIMEOUT = 3;
const CDN_TIMEOUT = 8;
const MAX_RETRIES = 2;
const CACHE_BUFFER = 300;

const RETRYABLE_STATUSES = [408, 425, 429, 500, 502, 503, 504];

const RETRYABLE_CURL_ERRORS = [
    CURLE_COULDNT_RESOLVE_HOST,
    CURLE_COULDNT_CONNECT,
    CURLE_OPERATION_TIMEDOUT,
    CURLE_RECV_ERROR,
    CURLE_SEND_ERROR,
    CURLE_GOT_NOTHING
];

$baseDirectory = dirname(__DIR__);
$dataDirectory = $baseDirectory . DIRECTORY_SEPARATOR . 'data';
$sessionFile = $dataDirectory . DIRECTORY_SEPARATOR . 'session.json';
$channelsFile = $dataDirectory . DIRECTORY_SEPARATOR . 'channels.json';
$streamUrlsFile = $dataDirectory . DIRECTORY_SEPARATOR . 'stream_urls.json';

function respondError(
    string $message,
    int $statusCode = 400,
    array $extra = []
): never {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(
            [
                'status' => 'error',
                'error' => $message
            ],
            $extra
        ),
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function readJsonFile(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $content = file_get_contents($file);

    if ($content === false || trim($content) === '') {
        return [];
    }

    try {
        $data = json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        return [];
    }

    return is_array($data) ? $data : [];
}

function writeJsonFile(string $file, array $data): bool
{
    $directory = dirname($file);

    if (!is_dir($directory)) {
        return false;
    }

    try {
        $content = json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        return false;
    }

    return atomicWrite($file, $content . PHP_EOL);
}

function writeCacheFile(string $file, string $content): bool
{
    $directory = dirname($file);

    if (!is_dir($directory)) {
        return false;
    }

    return atomicWrite($file, $content);
}

function atomicWrite(string $file, string $content): bool
{
    $directory = dirname($file);

    $temporaryFile = $directory
        . DIRECTORY_SEPARATOR
        . '.'
        . basename($file)
        . '.tmp';

    if (
        file_put_contents(
            $temporaryFile,
            $content,
            LOCK_EX
        ) === false
    ) {
        return false;
    }

    @chmod($temporaryFile, 0640);

    return rename($temporaryFile, $file);
}

function ensureDataDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
        respondError('Unable to initialize application storage', 500);
    }
}

function isRetryableCurlError(int $errorNumber): bool
{
    return in_array($errorNumber, RETRYABLE_CURL_ERRORS, true);
}

function isRetryableHttpStatus(int $statusCode): bool
{
    return in_array($statusCode, RETRYABLE_STATUSES, true);
}

function isValidChannelId(string $channelId): bool
{
    return preg_match(
        '/^[A-Za-z0-9._~-]{1,128}$/',
        $channelId
    ) === 1;
}

function loadSession(string $sessionFile): array
{
    if (!is_file($sessionFile)) {
        respondError('Not authenticated', 401);
    }

    $sessionData = readJsonFile($sessionFile);

    if (
        empty($sessionData) ||
        empty($sessionData['api_key']) ||
        !is_string($sessionData['api_key'])
    ) {
        respondError('Invalid session', 401);
    }

    $apiKey = trim($sessionData['api_key']);

    if ($apiKey === '') {
        respondError('Invalid session', 401);
    }

    return [$sessionData, $apiKey];
}

function fetchChannels(string $url, string $apiKey): array
{
    $lastResult = [
        'ok' => false,
        'status' => 0,
        'body' => '',
        'error' => 'Unable to contact upstream service',
        'errno' => 0
    ];

    for ($attempt = 0; $attempt <= MAX_RETRIES; $attempt++) {
        $curl = curl_init($url);

        if ($curl === false) {
            return $lastResult;
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPGET => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: JioTV-Proxy-Localhost/1.0',
                    'X-API-Key: ' . $apiKey
                ]
            ]
        );

        $response = curl_exec($curl);
        $curlErrorNumber = curl_errno($curl);
        $curlErrorMessage = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        $responseBody = is_string($response) ? $response : '';

        $lastResult = [
            'ok' => $curlErrorNumber === 0 && $httpCode >= 200 && $httpCode < 300,
            'status' => $httpCode,
            'body' => $responseBody,
            'error' => $curlErrorMessage,
            'errno' => $curlErrorNumber
        ];

        $shouldRetry = $attempt < MAX_RETRIES && (
            isRetryableCurlError($curlErrorNumber) ||
            isRetryableHttpStatus($httpCode)
        );

        if (!$shouldRetry) {
            break;
        }

        usleep(250000 * (2 ** $attempt));
    }

    return $lastResult;
}
