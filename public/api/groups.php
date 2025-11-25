<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/WapiClient.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$autoSync = env_bool('WAPI_AUTO_SYNC_CHATS', true);
$forceSync = shouldForceSync();

$baseUrl = env('WAPI_BASE_URL');
$authToken = env('WAPI_AUTH_TOKEN');
$instanceId = env('WAPI_INSTANCE_ID');

$client = null;
$connectedPhone = null;
$connected = null;

if ($baseUrl && $authToken && $instanceId) {
    try {
        $client = new WapiClient(
            $baseUrl,
            $authToken,
            (int) env('WAPI_REQUEST_TIMEOUT', 15),
            env_bool('WAPI_VERIFY_SSL', true)
        );

        [$connected, $connectedPhone] = resolveConnectionStatus($client, $instanceId);
    } catch (Throwable $exception) {
        $client = null;
        $connected = null;
        $connectedPhone = null;
    }
}

if ($connected === false) {
    echo json_encode([
        'data' => [],
        'meta' => [
            'sync' => [
                'success' => false,
                'synced' => 0,
                'error' => 'Instância desconectada. Conecte o número antes de listar os grupos.',
            ],
            'connected' => false,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$groups = fetchStoredGroups($pdo, $connectedPhone);
$syncMeta = null;

if ($forceSync || ($autoSync && count($groups) === 0)) {
    $syncMeta = syncGroupsFromWapi($pdo, $client, $instanceId, $connectedPhone);

    if (($syncMeta['success'] ?? false) && ($syncMeta['synced'] ?? 0) > 0) {
        $groups = fetchStoredGroups($pdo, $connectedPhone);
    }
}

$payload = [
    'data' => array_map(static fn (array $group): array => formatGroupForResponse($group), $groups),
];

if ($syncMeta !== null) {
    $payload['meta']['sync'] = $syncMeta;
}

if ($connectedPhone) {
    $payload['meta']['connected_phone'] = $connectedPhone;
}

if ($connected !== null) {
    $payload['meta']['connected'] = $connected;
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);

/**
 * Determines if a manual sync was requested.
 */
function shouldForceSync(): bool
{
    $candidates = ['refresh', 'sync', 'force'];

    foreach ($candidates as $candidate) {
        $value = filter_input(INPUT_GET, $candidate, FILTER_UNSAFE_RAW);

        if ($value === null) {
            continue;
        }

        $normalized = strtolower(trim((string) $value));

        if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'no') {
            return false;
        }

        return true;
    }

    return false;
}

/**
 * Returns the persisted groups from the database.
 */
function fetchStoredGroups(PDO $pdo, ?string $connectedPhone): array
{
    $query = 'SELECT
            g.id,
            g.wa_id,
            g.name,
            g.channel,
            g.avatar_url,
            g.last_message_at,
            (
                SELECT message_body
                FROM messages
                WHERE group_id = g.id
                ORDER BY sent_at DESC
                LIMIT 1
            ) AS last_message_body,
            (
                SELECT sent_at
                FROM messages
                WHERE group_id = g.id
                ORDER BY sent_at DESC
                LIMIT 1
            ) AS last_message_sent_at,
            (
                SELECT COUNT(*)
                FROM messages
                WHERE group_id = g.id
            ) AS message_count,
            (
                SELECT sender_name
                FROM messages
                WHERE group_id = g.id
                  AND is_from_me = 0
                  AND sender_name IS NOT NULL
                  AND TRIM(sender_name) <> \'\'
                ORDER BY sent_at DESC
                LIMIT 1
            ) AS last_sender_name,
            CASE
                WHEN LOWER(g.wa_id) LIKE \'%@g.us\' THEN \'group\'
                WHEN LOWER(g.wa_id) LIKE \'%@broadcast%\' THEN \'group\'
                ELSE \'contact\'
            END AS conversation_type
         FROM groups g';

    $params = [];

    if ($connectedPhone) {
        $query .= ' WHERE (g.channel = :channel OR g.channel IS NULL OR g.channel = :fallback_channel)';
        $params[':channel'] = $connectedPhone;
        $params[':fallback_channel'] = env('WAPI_FALLBACK_CHANNEL', 'whatsapp');
    }

    $query .= ' ORDER BY COALESCE(g.last_message_at, g.created_at) DESC';

    $statement = $pdo->prepare($query);
    $statement->execute($params);

    return $statement->fetchAll() ?: [];
}

/**
 * Attempts to resolve the connected phone number using the W-API device endpoint.
 */
function resolveConnectionStatus(?WapiClient $client, ?string $instanceId): array
{
    if (!$client || !$instanceId) {
        return [null, null];
    }

    try {
        $endpoint = resolveWapiEndpoint(env('WAPI_PROFILE_ENDPOINT', '/instance/device?instanceId={{id}}'), $instanceId);
        $payload = $client->get($endpoint);

        $connected = null;

        if (isset($payload['connected'])) {
            $connected = filter_var($payload['connected'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $phone = $payload['connectedPhone']
            ?? $payload['phone']
            ?? $payload['wid']
            ?? null;

        return [$connected, normalizePhone($phone)];
    } catch (Throwable $exception) {
        return [null, null];
    }
}

/**
 * Fetches chats from the W-API and synchronizes them with the local database.
 *
 * @return array{success:bool,synced:int,error?:string,source?:string,phone?:string}
 */
function syncGroupsFromWapi(PDO $pdo, ?WapiClient $client, ?string $instanceId, ?string $connectedPhone): array
{
    $baseUrl = env('WAPI_BASE_URL');
    $authToken = env('WAPI_AUTH_TOKEN');

    if (!$baseUrl || !$authToken || !$instanceId) {
        return [
            'success' => false,
            'synced' => 0,
            'error' => 'W-API configuration incompleta. Verifique as variáveis de ambiente.',
        ];
    }

    try {
        if (!$client) {
            $client = new WapiClient(
                $baseUrl,
                $authToken,
                (int) env('WAPI_REQUEST_TIMEOUT', 15),
                env_bool('WAPI_VERIFY_SSL', true)
            );
        }

        $connectedPhone = normalizePhone($connectedPhone) ?: resolveConnectionStatus($client, $instanceId)[1];

        $attempts = buildWapiSyncAttempts($instanceId);
        $lastError = null;
        $synced = 0;
        $source = null;

        foreach ($attempts as $attempt) {
            try {
                $endpoint = resolveWapiEndpoint($attempt['template'], $instanceId);
                $payload = $client->get($endpoint);

                if (($payload['error'] ?? false) === true) {
                    $lastError = $payload['message'] ?? 'W-API retornou erro.';
                    continue;
                }

                $items = $attempt['type'] === 'groups'
                    ? ($payload['groups'] ?? null)
                    : null;

                if ($items === null) {
                    $items = extractChatList($payload);
                }

                if (!is_array($items) || count($items) === 0) {
                    $lastError = 'Nenhum grupo foi retornado pela API.';
                    continue;
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $waId = (string) ($item['id']
                        ?? $item['jid']
                        ?? $item['wa_id']
                        ?? $item['phone']
                        ?? '');

                    if ($waId === '') {
                        continue;
                    }

                    $name = $item['subject']
                        ?? $item['name']
                        ?? $item['pushName']
                        ?? $item['chatName']
                        ?? $item['displayName']
                        ?? $waId;

                    $channel = $connectedPhone
                        ?? (
                            $attempt['type'] === 'chats'
                                ? ($item['type'] ?? 'group')
                                : 'group'
                        );

                    $lastMessageAt = resolveChatTimestamp(
                        $item['subjectTime']
                        ?? $item['creation']
                        ?? $item['lastMessageTimestamp']
                        ?? $item['lastMessageDate']
                        ?? $item['last_message_at']
                        ?? $item['timestamp']
                        ?? null
                    );

                    upsertGroup($pdo, [
                        'wa_id' => $waId,
                        'name' => $name,
                        'channel' => (string) $channel,
                        'last_message_at' => $lastMessageAt,
                    ], false);

                    $synced++;
                }

                $source = $attempt['type'];

                if ($synced > 0) {
                    break;
                }
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }
        }

        if ($synced === 0) {
            return [
                'success' => false,
                'synced' => 0,
                'error' => $lastError ?? 'Não foi possível sincronizar grupos via W-API.',
            ];
        }

        return [
            'success' => true,
            'synced' => $synced,
            'source' => $source,
            'phone' => $connectedPhone,
        ];
    } catch (Throwable $exception) {
        return [
            'success' => false,
            'synced' => 0,
            'error' => APP_DEBUG ? $exception->getMessage() : 'Não foi possível sincronizar os grupos via W-API.',
        ];
    }
}

/**
 * Builds the list of W-API endpoints to attempt for synchronization.
 *
 * @return array<int,array{template:string,type:string}>
 */
function buildWapiSyncAttempts(?string $instanceId): array
{
    $attempts = [];

    $groupTemplate = env('WAPI_FETCH_GROUPS_ENDPOINT');

    if ($groupTemplate === null || $groupTemplate === '') {
        $groupTemplate = '/group/get-all-groups?instanceId={{id}}';
    }

    if (strtolower((string) $groupTemplate) !== 'disable') {
        $attempts[] = [
            'template' => (string) $groupTemplate,
            'type' => 'groups',
        ];
    }

    $chatTemplate = env('WAPI_FETCH_CHATS_ENDPOINT');

    if ($chatTemplate === null || $chatTemplate === '') {
        $perPage = max((int) env('WAPI_CHATS_PER_PAGE', 100), 1);
        $page = max((int) env('WAPI_CHATS_PAGE', 1), 1);
        $chatTemplate = sprintf('/chats/fetch-chats?instanceId={{id}}&perPage=%d&page=%d', $perPage, $page);
    }

    if (strtolower((string) $chatTemplate) !== 'disable') {
        $attempts[] = [
            'template' => (string) $chatTemplate,
            'type' => 'chats',
        ];
    }

    return $attempts;
}

/**
 * Normalizes phone numbers to digits-only format.
 */
function normalizePhone($value): ?string
{
    if ($value === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', (string) $value);

    return $digits !== '' ? $digits : null;
}

/**
 * Normalizes different API payload shapes into a flat list of chats.
 */
function extractChatList($payload): array
{
    if (!is_array($payload)) {
        return [];
    }

    if (array_is_list($payload)) {
        return $payload;
    }

    $candidates = ['groups', 'chats', 'data', 'results', 'items', 'docs'];

    foreach ($candidates as $candidate) {
        if (!isset($payload[$candidate]) || !is_array($payload[$candidate])) {
            continue;
        }

        $list = extractChatList($payload[$candidate]);

        if (!empty($list) || array_is_list($payload[$candidate])) {
            return $list ?: $payload[$candidate];
        }
    }

    return [];
}

/**
 * Builds an endpoint string replacing instance placeholders.
 */
function resolveWapiEndpoint(?string $template, ?string $instanceId): string
{
    $path = $template ?: '';
    $placeholders = ['{{id}}', '{{instanceId}}'];

    foreach ($placeholders as $placeholder) {
        if (str_contains($path, $placeholder)) {
            if (!$instanceId) {
                throw new InvalidArgumentException('Instance id is required to resolve endpoint template.');
            }

            $path = str_replace($placeholder, $instanceId, $path);
        }
    }

    return $path ?: '/';
}

/**
 * Converts timestamps returned by W-API into ISO8601 strings.
 */
function resolveChatTimestamp($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $integer = (int) $value;

        // Values in milliseconds typically exceed current Unix time by a factor of 1000.
        if ($integer > 9999999999) {
            $integer = (int) round($integer / 1000);
        }

        return (new DateTimeImmutable('@' . $integer))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeInterface::ATOM);
    }

    if ($value instanceof DateTimeInterface) {
        return $value->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    try {
        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    } catch (Exception $exception) {
        return null;
    }
}

/**
 * Maps a database row into the API response format.
 */
function formatGroupForResponse(array $group): array
{
    $type = detectConversationType($group['conversation_type'] ?? null, $group['wa_id'] ?? null);

    return [
        'id' => (int) $group['id'],
        'wa_id' => $group['wa_id'],
        'name' => resolveDisplayName($group, $type),
        'channel' => $group['channel'],
        'avatar_url' => $group['avatar_url'] ?? null,
        'type' => $type,
        'is_group' => $type === 'group',
        'last_message_body' => $group['last_message_body'],
        'last_message_sent_at' => $group['last_message_sent_at'],
        'message_count' => (int) $group['message_count'],
    ];
}

/**
 * Determines the best label to show for a conversation.
 */
function resolveDisplayName(array $group, string $type): string
{
    $name = is_string($group['name'] ?? null) ? trim($group['name']) : '';

    if ($name !== '') {
        if ($type === 'group') {
            $lastSender = trim((string) ($group['last_sender_name'] ?? ''));

            if ($lastSender !== '' && strcasecmp($lastSender, $name) === 0) {
                $name = '';
            }
        }
    }

    if ($name !== '') {
        return $name;
    }

    if ($type === 'contact' && isset($group['last_sender_name'])) {
        $sender = trim((string) $group['last_sender_name']);

        if ($sender !== '') {
            return $sender;
        }
    }

    return formatWaId($group['wa_id'] ?? null);
}

/**
 * Formats a WA id removing protocol suffixes for display.
 */
function formatWaId(?string $waId): string
{
    if ($waId === null || $waId === '') {
        return 'Conversa sem nome';
    }

    $label = $waId;

    if (str_contains($label, '@')) {
        $label = strstr($label, '@', true) ?: $label;
    }

    $digitsOnly = preg_replace('/\D+/', '', $label);

    if ($digitsOnly !== '' && strlen($digitsOnly) >= 6) {
        return '+' . ltrim($digitsOnly, '0');
    }

    return $label;
}

/**
 * Returns "group" or "contact" based on the WA identifier.
 */
function detectConversationType($precomputed, ?string $waId): string
{
    if (is_string($precomputed) && $precomputed !== '') {
        return $precomputed;
    }

    $normalized = strtolower((string) $waId);

    if (str_contains($normalized, '@g.us') || str_contains($normalized, '@broadcast')) {
        return 'group';
    }

    return 'contact';
}
