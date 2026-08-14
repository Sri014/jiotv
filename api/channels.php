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
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ensureDataDirectory($dataDirectory);

[, $apiKey] = loadSession($sessionFile);

$result = fetchChannels(
    VERCEL_URL . API_CHANNELS,
    $apiKey
);

if (!$result['ok']) {
    if ($result['body'] !== '') {
        http_response_code(
            $result['status'] > 0 ? $result['status'] : 502
        );

        echo $result['body'];
        exit;
    }

    respondError(
        'Unable to fetch channels',
        502,
        [
            'upstream_status' => $result['status'],
            'curl_errno' => $result['errno']
        ]
    );
}

if ($result['body'] === '') {
    respondError('Upstream returned an empty channels response', 502);
}

if (!writeCacheFile($channelsFile, $result['body'])) {
    respondError('Channels fetched successfully but cache could not be saved', 500);
}

http_response_code(200);
echo $result['body'];