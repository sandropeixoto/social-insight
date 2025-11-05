<?php

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

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

$statusEndpoint = resolveEndpoint(env('WAPI_STATUS_ENDPOINT', '/instance/status-instance?instanceId={{id}}'), $instanceId);
$profileEndpoint = resolveEndpoint(env('WAPI_PROFILE_ENDPOINT', '/instance/device?instanceId={{id}}'), $instanceId);
$qrEndpoint = resolveEndpoint(env('WAPI_QR_ENDPOINT', '/instance/qr-code?instanceId={{id}}&image=enable&syncContacts=disable'), $instanceId);

$client = new WapiClient(
    $baseUrl,
    $authToken,
    (int) env('WAPI_REQUEST_TIMEOUT', 15),
    env_bool('WAPI_VERIFY_SSL', true)
);

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

    return [
        'name' => $name,
        'wid' => $wid,
        'is_business' => filter_var($profile['isBusiness'] ?? $profile['is_business'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'profile_picture_url' => $profilePicture,
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

    if (is_string($image) && str_starts_with($image, 'data:image')) {
        return ['type' => 'data-uri', 'value' => $image];
    }

    if (is_string($image) && preg_match('/^[A-Za-z0-9+\/=]+$/', $image)) {
        return ['type' => 'data-uri', 'value' => 'data:image/png;base64,' . $image];
    }

    if (isset($payload['url']) && filter_var($payload['url'], FILTER_VALIDATE_URL)) {
        return ['type' => 'url', 'value' => $payload['url']];
    }

    if (isset($payload['image']) && filter_var($payload['image'], FILTER_VALIDATE_URL)) {
        return ['type' => 'url', 'value' => $payload['image']];
    }

    return null;
}

final class WapiClient
{
    private string $baseUrl;
    private string $authToken;
    private int $timeout;
    private bool $verifySsl;

    public function __construct(string $baseUrl, string $authToken, int $timeout = 15, bool $verifySsl = true)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authToken = $authToken;
        $this->timeout = max($timeout, 5);
        $this->verifySsl = $verifySsl;
    }

    /**
     * Executes a GET request.
     */
    public function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    /**
     * Issues an HTTP request and returns the decoded JSON response.
     */
    private function request(string $method, string $endpoint): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL session.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->authToken,
            ],
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $result = curl_exec($handle);

        if ($result === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('W-API request failed: ' . $error);
        }

        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $decoded = json_decode($result, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new UnexpectedValueException('Unexpected response from W-API: ' . json_last_error_msg());
        }

        if ($statusCode >= 400) {
            $message = $decoded['message'] ?? $decoded['error'] ?? 'HTTP ' . $statusCode;
            throw new RuntimeException('W-API responded with error: ' . $message, $statusCode);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
