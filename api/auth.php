<?php

/*
╔══════════════════════════════════════════════════════════════════════╗
║                    JioTV+ Proxy Localhost                            ║
║                                                                      ║
║                    Crafted With 💚 by LazyyXD                        ║
╚══════════════════════════════════════════════════════════════════════╝
*/

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function fail(
    string $message,
    int $statusCode = 400,
    array $extra = []
): never {
    respond(
        array_merge(
            [
                'status' => 'error',
                'error' => $message
            ],
            $extra
        ),
        $statusCode
    );
}

function readJsonInput(): array
{
    $rawInput = file_get_contents('php://input');

    if ($rawInput === false || trim($rawInput) === '') {
        return [];
    }

    try {
        $input = json_decode(
            $rawInput,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        fail('Invalid JSON request body', 400);
    }

    if (!is_array($input)) {
        fail('Request body must be a JSON object', 400);
    }

    return $input;
}

function requireString(
    array $input,
    string $key,
    int $minimumLength = 1
): string {
    $value = $input[$key] ?? '';

    if (!is_string($value)) {
        fail("Invalid {$key}", 422);
    }

    $value = trim($value);

    if (strlen($value) < $minimumLength) {
        fail("Missing or invalid {$key}", 422);
    }

    return $value;
}

function request(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?array $body = null,
    int $timeout = REQUEST_TIMEOUT
): array {
    $payload = null;

    if ($body !== null) {
        try {
            $payload = json_encode(
                $body,
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE |
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'json' => null,
                'error' => 'Unable to encode request body',
                'errno' => 0
            ];
        }
    }

    $requestHeaders = array_merge(
        [
            'Accept: application/json',
            'User-Agent: JioTV-Proxy-Localhost/1.0'
        ],
        $body !== null
        ? ['Content-Type: application/json']
        : [],
        $headers
    );

    $lastResult = [
        'ok' => false,
        'status' => 0,
        'body' => '',
        'json' => null,
        'error' => 'Request failed',
        'errno' => 0
    ];

    for ($attempt = 0; $attempt <= MAX_RETRIES; $attempt++) {
        $curl = curl_init($url);

        if ($curl === false) {
            return $lastResult;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => min(CONNECT_TIMEOUT, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => strtoupper($method)
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($curl, $options);

        $responseBody = curl_exec($curl);
        $curlErrorNumber = curl_errno($curl);
        $curlErrorMessage = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        $responseText = is_string($responseBody) ? $responseBody : '';
        $responseJson = null;

        if ($responseText !== '') {
            try {
                $responseJson = json_decode(
                    $responseText,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                $responseJson = null;
            }
        }

        $lastResult = [
            'ok' => $curlErrorNumber === 0 && $httpCode >= 200 && $httpCode < 300,
            'status' => $httpCode,
            'body' => $responseText,
            'json' => $responseJson,
            'error' => $curlErrorMessage,
            'errno' => $curlErrorNumber
        ];

        $canRetry = $attempt < MAX_RETRIES && (
            isRetryableCurlError($curlErrorNumber) ||
            isRetryableHttpStatus($httpCode)
        );

        if (!$canRetry) {
            break;
        }

        usleep(250000 * (2 ** $attempt));
    }

    return $lastResult;
}

function proxyResponse(array $result): never
{
    if ($result['body'] !== '') {
        http_response_code(
            $result['status'] > 0 ? $result['status'] : 502
        );

        echo $result['body'];
        exit;
    }

    fail(
        'Unable to contact upstream service',
        502,
        [
            'upstream_status' => $result['status'],
            'curl_errno' => $result['errno']
        ]
    );
}

ensureDataDirectory($dataDirectory);

$action = isset($_GET['action']) && is_string($_GET['action'])
    ? trim($_GET['action'])
    : '';

$input = readJsonInput();

switch ($action) {
    case 'send_otp':
        $identifier = requireString($input, 'identifier');

        $result = request(
            VERCEL_URL . API_SEND_OTP,
            'POST',
            [],
            ['identifier' => $identifier]
        );

        proxyResponse($result);

    case 'verify_otp':
        $identifier = requireString($input, 'identifier');
        $otp = requireString($input, 'otp', 4);

        $result = request(
            VERCEL_URL . API_VERIFY_OTP,
            'POST',
            [],
            [
                'identifier' => $identifier,
                'otp' => $otp
            ]
        );

        if (!$result['ok']) {
            proxyResponse($result);
        }

        $responseData = is_array($result['json']) ? $result['json'] : [];

        if (
            empty($responseData['api_key']) ||
            !is_string($responseData['api_key'])
        ) {
            proxyResponse($result);
        }

        $sessionData = [
            'name' => isset($responseData['name']) && is_string($responseData['name'])
                ? $responseData['name']
                : '',
            'mobile_number' => $identifier,
            'api_key' => $responseData['api_key'],
            'expiry' => $responseData['expiry'] ?? null,
            'updated_at' => gmdate('c')
        ];

        if (!writeJsonFile($sessionFile, $sessionData)) {
            fail('Authentication succeeded but session could not be saved', 500);
        }

        $channelsResult = request(
            VERCEL_URL . API_CHANNELS,
            'GET',
            ['x-api-key: ' . $sessionData['api_key']],
            null,
            REQUEST_TIMEOUT
        );

        if (
            $channelsResult['status'] === 200 &&
            $channelsResult['body'] !== ''
        ) {
            writeCacheFile($channelsFile, $channelsResult['body']);
        }

        proxyResponse($result);

    case 'status':
        $sessionData = readJsonFile($sessionFile);

        if (empty($sessionData['api_key'])) {
            respond(['status' => 'logged_out']);
        }

        $meResult = request(
            VERCEL_URL . API_ME,
            'GET',
            ['x-api-key: ' . $sessionData['api_key']],
            null,
            STATUS_TIMEOUT
        );

        if ($meResult['ok'] && is_array($meResult['json'])) {
            $meData = $meResult['json'];

            if (array_key_exists('expiry', $meData)) {
                $sessionData['expiry'] = $meData['expiry'];
            }

            $sessionData['updated_at'] = gmdate('c');

            if (!writeJsonFile($sessionFile, $sessionData)) {
                $sessionData['storage_warning'] = 'Unable to update local session';
            }
        }

        respond([
            'status' => 'logged_in',
            'user' => $sessionData
        ]);

    case 'logout':
        if (!writeJsonFile($sessionFile, [])) {
            fail('Unable to clear local session', 500);
        }

        respond(['status' => 'success']);

    default:
        fail('Invalid action', 404);
}