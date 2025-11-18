<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/WapiClient.php';

header('Content-Type: application/json; charset=utf-8');

$baseUrl = env('WAPI_BASE_URL');
$authToken = env('WAPI_AUTH_TOKEN');
$instanceId = env('WAPI_INSTANCE_ID');

if (!$baseUrl || !$authToken) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing W-API configuration. Please set WAPI_BASE_URL and WAPI_AUTH_TOKEN in your environment.',
    ]);
    exit;
}

$client = new WapiClient(
    $baseUrl,
    $authToken,
    (int) env('WAPI_REQUEST_TIMEOUT', 15),
    env_bool('WAPI_VERIFY_SSL', true)
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleInstanceAction($client, $instanceId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$statusEndpoint = resolveEndpoint(env('WAPI_STATUS_ENDPOINT', '/instance/status-instance?instanceId={{id}}'), $instanceId);
$profileEndpoint = resolveEndpoint(env('WAPI_PROFILE_ENDPOINT', '/instance/device?instanceId={{id}}'), $instanceId);
$qrEndpoint = resolveEndpoint(env('WAPI_QR_ENDPOINT', '/instance/qr-code?instanceId={{id}}&image=disable&syncContacts=disable'), $instanceId);

$response = [
    'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    'connected' => false,
    'status' => null,
    'profile' => null,
    'qr_code' => null,
];

try {
    $statusPayload = $client->get($statusEndpoint);
    $response['status'] = $statusPayload;
    $response['connected'] = determineConnectionState($statusPayload);
} catch (Throwable $exception) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Unable to fetch instance status from W-API',
        'details' => APP_DEBUG ? $exception->getMessage() : null,
    ]);
    exit;
}

if ($response['connected']) {
    try {
        $profilePayload = $client->get($profileEndpoint);
        $response['profile'] = normalizeProfile($profilePayload);

        if (APP_DEBUG) {
            $response['profile_raw'] = $profilePayload;
        }
    } catch (Throwable $exception) {
        $response['profile_error'] = APP_DEBUG ? $exception->getMessage() : 'Unable to fetch profile info.';
    }
} else {
    try {
        $qrPayload = $client->get($qrEndpoint);
        $response['qr_code'] = normalizeQrCode($qrPayload);

        if (APP_DEBUG) {
            $response['qr_raw'] = $qrPayload;
        }
    } catch (Throwable $exception) {
        $response['qr_error'] = APP_DEBUG ? $exception->getMessage() : 'Unable to fetch QR Code.';
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

/**
 * Handles instance-related actions (POST requests).
 */
function handleInstanceAction(WapiClient $client, ?string $instanceId): void
{
    $rawBody = file_get_contents('php://input') ?: '';
    $payload = json_decode($rawBody, true);

    if (!is_array($payload)) {
        $payload = [];
    }

    $action = strtolower(trim((string) ($payload['action'] ?? $_POST['action'] ?? '')));

    if ($action === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing action parameter.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    switch ($action) {
        case 'disconnect':
            performDisconnect($client, $instanceId);
            return;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported action.'], JSON_UNESCAPED_UNICODE);
            return;
    }
}

/**
 * Executes the disconnect action against W-API.
 */
function performDisconnect(WapiClient $client, ?string $instanceId): void
{
    if (!$instanceId) {
        http_response_code(400);
        echo json_encode(['error' => 'Instance id is required to disconnect.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    try {
        $endpoint = resolveEndpoint(env('WAPI_DISCONNECT_ENDPOINT', '/instance/disconnect?instanceId={{id}}'), $instanceId);
        $payload = $client->get($endpoint);

        echo json_encode([
            'status' => 'ok',
            'action' => 'disconnect',
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        $statusCode = $exception->getCode();
        if (!is_int($statusCode) || $statusCode < 400) {
            $statusCode = 502;
        }

        http_response_code($statusCode);
        echo json_encode([
            'error' => 'Unable to disconnect instance via W-API.',
            'message' => $exception->getMessage(),
            'details' => APP_DEBUG ? $exception->getTraceAsString() : null,
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Resolves placeholders in an endpoint template.
 */
function resolveEndpoint(?string $template, ?string $instanceId): string
{
    $path = $template ?: '';

    $placeholders = ['{{id}}', '{{instanceId}}'];
    $containsPlaceholder = false;

    foreach ($placeholders as $placeholder) {
        if (str_contains($path, $placeholder)) {
            $containsPlaceholder = true;
            break;
        }
    }

    if ($containsPlaceholder) {
        if (!$instanceId) {
            throw new InvalidArgumentException('Instance id is required to resolve endpoint template.');
        }

        foreach ($placeholders as $placeholder) {
            $path = str_replace($placeholder, $instanceId, $path);
        }
    }

    return $path ?: '/';
}

/**
 * Inspects the payload to determine if the instance is connected.
 */
function determineConnectionState($payload): bool
{
    if (!is_array($payload)) {
        return false;
    }

    foreach (['connected', 'isConnected', 'is_connected', 'isLogged', 'logged'] as $key) {
        if (array_key_exists($key, $payload)) {
            return filter_var($payload[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }
    }

    if (isset($payload['state'])) {
        $state = strtoupper((string) $payload['state']);
        return in_array($state, ['CONNECTED', 'LOGGED', 'LOGGED_IN', 'ONLINE', 'RUNNING', 'AUTHENTICATED'], true);
    }

    if (isset($payload['status'])) {
        $status = strtoupper((string) $payload['status']);
        return in_array($status, ['CONNECTED', 'LOGGED', 'LOGGED_IN', 'ONLINE', 'RUNNING', 'AUTHENTICATED'], true);
    }

    if (isset($payload['session']) && is_array($payload['session'])) {
        return determineConnectionState($payload['session']);
    }

    return false;
}

/**
 * Extracts key profile fields.
 */
function normalizeProfile($profile): ?array
{
    if (!is_array($profile)) {
        return null;
    }

    $name = $profile['pushname']
        ?? $profile['pushName']
        ?? $profile['displayName']
        ?? $profile['name']
        ?? ($profile['connectedName'] ?? null)
        ?? null;

    $rawId = $profile['id'] ?? null;
    if (is_array($rawId) && isset($rawId['user'])) {
        $rawId = $rawId['user'];
    }

    $wid = $profile['wid']
        ?? $rawId
        ?? ($profile['user'] ?? null)
        ?? $profile['connectedPhone']
        ?? $profile['lid']
        ?? null;

    $profilePicture = $profile['profile_picture_url']
        ?? $profile['profilePictureUrl']
        ?? $profile['picture']
        ?? $profile['avatar']
        ?? null;

    $connectedPhone = $profile['connectedPhone']
        ?? $profile['phone']
        ?? $rawId;

    $isBusiness = filter_var($profile['isBusiness'] ?? $profile['is_business'] ?? false, FILTER_VALIDATE_BOOLEAN);

    return [
        'name' => $name,
        'wid' => $wid,
        'is_business' => $isBusiness,
        'profile_picture_url' => $profilePicture,
        'platform' => $profile['platform'] ?? null,
        'profilePictureUrl' => $profilePicture,
        'isBusiness' => $isBusiness,
        'info' => [
            'platform' => $profile['platform'] ?? null,
            'phone' => $connectedPhone,
            'about' => $profile['about'] ?? $profile['status'] ?? null,
            'lid' => $profile['lid'] ?? null,
        ],
    ];
}

/**
 * Normalizes QR code payloads to a single structure.
 */
function normalizeQrCode($payload): ?array
{
    if (!is_array($payload)) {
        return null;
    }

    $image = $payload['base64']
        ?? $payload['qrcode']
        ?? $payload['qrCode']
        ?? $payload['qr']
        ?? ($payload['data']['base64'] ?? null);

    if (is_string($image)) {
        if (str_starts_with($image, 'data:image')) {
            return ['type' => 'data-uri', 'value' => $image];
        }

        if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $image)) {
            $base64 = preg_replace('/\s+/', '', $image);
            return ['type' => 'data-uri', 'value' => 'data:image/png;base64,' . $base64];
        }
    }

    if (isset($payload['url']) && filter_var($payload['url'], FILTER_VALIDATE_URL)) {
        return ['type' => 'url', 'value' => $payload['url']];
    }

    if (isset($payload['image']) && filter_var($payload['image'], FILTER_VALIDATE_URL)) {
        return ['type' => 'url', 'value' => $payload['image']];
    }

    return null;
}
