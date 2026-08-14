<?php

/*
╔══════════════════════════════════════════════════════════════════════╗
║                    JioTV+ Proxy Localhost                            ║
║                                                                      ║
║                    Crafted With 💚 by LazyyXD                        ║
╚══════════════════════════════════════════════════════════════════════╝
*/

require_once __DIR__ . '/../api/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Accept, X-API-Key');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    http_response_code(204);
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function getRequestContentType(): string
{
    $contentType = $_SERVER['CONTENT_TYPE']
        ?? $_SERVER['HTTP_CONTENT_TYPE']
        ?? '';

    if (!is_string($contentType)) {
        return '';
    }

    $contentType = trim($contentType);

    if ($contentType === '') {
        return '';
    }

    $contentType = explode(';', $contentType, 2)[0];

    return strtolower(trim($contentType));
}

function isAllowedContentType(string $contentType): bool
{
    if ($contentType === '') {
        return true;
    }

    return preg_match(
        '/^(application\/octet-stream|application\/json|application\/protobuf|application\/vnd\.[a-z0-9.+-]+|text\/plain)$/i',
        $contentType
    ) === 1;
}

function requestLicense(
    string $url,
    string $apiKey,
    string $requestBody,
    string $contentType
): array {
    $lastResult = [
        'ok' => false,
        'status' => 0,
        'body' => '',
        'content_type' => '',
        'error' => 'Upstream request failed',
        'errno' => 0
    ];

    for ($attempt = 0; $attempt <= MAX_RETRIES; $attempt++) {
        $curl = curl_init($url);

        if ($curl === false) {
            return $lastResult;
        }

        $headers = [
            'Accept: */*',
            'User-Agent: JioTV-Proxy-Localhost/1.0',
            'X-API-Key: ' . $apiKey,
            'Content-Length: ' . strlen($requestBody)
        ];

        if ($contentType !== '') {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $requestBody,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]
        );

        $responseBody = curl_exec($curl);
        $curlErrorNumber = curl_errno($curl);
        $curlErrorMessage = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $responseContentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

        curl_close($curl);

        $body = is_string($responseBody) ? $responseBody : '';

        $lastResult = [
            'ok' => $curlErrorNumber === 0 && $httpCode >= 200 && $httpCode < 300,
            'status' => $httpCode,
            'body' => $body,
            'content_type' => $responseContentType,
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    respondError('Method Not Allowed', 405);
}

$channelId = $_GET['id'] ?? '';

if (!is_string($channelId)) {
    respondError('Invalid channel ID', 400);
}

$channelId = trim($channelId);

if ($channelId === '') {
    respondError('Missing channel ID', 400);
}

if (!isValidChannelId($channelId)) {
    respondError('Invalid channel ID format', 400);
}

[, $apiKey] = loadSession($sessionFile);

$requestBody = file_get_contents('php://input');

if ($requestBody === false) {
    respondError('Unable to read DRM challenge body', 400);
}

if ($requestBody === '') {
    respondError('Empty DRM challenge body', 400);
}

$contentType = getRequestContentType();

if (!isAllowedContentType($contentType)) {
    respondError('Unsupported challenge content type', 415);
}

$encodedChannelId = rawurlencode($channelId);

$licenseUrl = VERCEL_URL
    . LICENSE_ENDPOINT
    . $encodedChannelId;

$result = requestLicense(
    $licenseUrl,
    $apiKey,
    $requestBody,
    $contentType
);

if (!$result['ok']) {
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
        'Unable to contact license service',
        502,
        [
            'upstream_status' => $result['status'],
            'curl_errno' => $result['errno']
        ]
    );
}

if ($result['content_type'] !== '') {
    header('Content-Type: ' . $result['content_type']);
} elseif ($contentType !== '') {
    header('Content-Type: ' . $contentType);
} else {
    header('Content-Type: application/octet-stream');
}

http_response_code($result['status']);
echo $result['body'];