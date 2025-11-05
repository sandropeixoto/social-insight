<?php

require_once __DIR__ . '/../../bootstrap.php';

// Basic webhook handshake for providers that expect verification.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['mode'] ?? null;
    $verifyToken = $_GET['hub_verify_token'] ?? $_GET['token'] ?? null;
    $challenge = $_GET['hub_challenge'] ?? $_GET['challenge'] ?? '';
    $expectedToken = $_ENV['WEBHOOK_VERIFY_TOKEN'] ?? getenv('WEBHOOK_VERIFY_TOKEN') ?: 'social_insight_token';

    if ($mode === 'subscribe' && hash_equals($expectedToken, (string) $verifyToken)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }

    http_response_code(403);
    echo 'Verification failed';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = file_get_contents('php://input');

if ($body === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Unable to read request body']);
    exit;
}

$payload = json_decode($body, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$entries = $payload['entry'] ?? [$payload];

$pdo = db();
$pdo->beginTransaction();

try {
    foreach ($entries as $entry) {
        $changes = $entry['changes'] ?? [$entry];

        foreach ($changes as $change) {
            $value = $change['value'] ?? $change;
            $messagingProduct = $value['messaging_product'] ?? $value['product'] ?? 'whatsapp';
            $metadata = $value['metadata'] ?? [];

            $contacts = [];
            foreach ($value['contacts'] ?? [] as $contact) {
                if (!isset($contact['wa_id'])) {
                    continue;
                }

                $contacts[$contact['wa_id']] = $contact;
            }

            foreach ($value['messages'] ?? [] as $message) {
                $conversationId = resolveConversationId($message, $value, $metadata);

                if (!$conversationId) {
                    continue;
                }

                $sentAt = resolveTimestamp($message['timestamp'] ?? null);

                $groupName = resolveGroupName($message, $contacts, $conversationId);

                $groupId = upsertGroup($pdo, [
                    'wa_id' => $conversationId,
                    'name' => $groupName,
                    'channel' => $messagingProduct,
                    'last_message_at' => $sentAt,
                ]);

                $messageData = normalizeMessage($message, $contacts);
                $messageData['sent_at'] = $sentAt;

                storeMessage($pdo, $groupId, $messageData);
            }
        }
    }

    $pdo->commit();

    echo json_encode(['status' => 'ok']);
} catch (Throwable $exception) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to persist webhook payload',
        'details' => $exception->getMessage(),
    ]);
}

/**
 * Attempts to resolve the conversation identifier (group or chat id).
 */
function resolveConversationId(array $message, array $value, array $metadata): ?string
{
    return $message['group_id']
        ?? $message['groupId']
        ?? $message['chat_id']
        ?? $message['chatId']
        ?? $message['from']
        ?? $message['author']
        ?? $metadata['phone_number_id']
        ?? null;
}

/**
 * Normalizes timestamps into ISO8601 format compatible with SQLite.
 */
function resolveTimestamp(null|int|string $timestamp): string
{
    if ($timestamp instanceof DateTimeInterface) {
        return $timestamp->format(DateTimeInterface::ATOM);
    }

    if (is_numeric($timestamp)) {
        $dateTime = (new DateTimeImmutable())->setTimestamp((int) $timestamp);
    } elseif (is_string($timestamp)) {
        $dateTime = new DateTimeImmutable($timestamp);
    } else {
        $dateTime = new DateTimeImmutable('now');
    }

    return $dateTime->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
}

/**
 * Attempts to extract a human-readable group name.
 */
function resolveGroupName(array $message, array $contacts, string $conversationId): string
{
    return $message['group_name']
        ?? $message['groupName']
        ?? $message['chat_name']
        ?? $message['chatName']
        ?? $message['name']
        ?? ($contacts[$conversationId]['profile']['name'] ?? null)
        ?? $contacts[array_key_first($contacts)]['profile']['name'] ?? $conversationId;
}

/**
 * Builds the message array expected by the persistence layer.
 */
function normalizeMessage(array $message, array $contacts): array
{
    $type = $message['type'] ?? $message['messageType'] ?? 'text';

    $senderPhone = $message['from']
        ?? $message['author']
        ?? $message['chatId']
        ?? null;

    $senderName = $message['sender_name']
        ?? $message['senderName']
        ?? $message['pushName']
        ?? $message['name']
        ?? ($senderPhone && isset($contacts[$senderPhone]) ? ($contacts[$senderPhone]['profile']['name'] ?? null) : null)
        ?? 'Desconhecido';

    $body = extractBody($message) ?? labelForAttachment($type, $message);

    $direction = $message['from_me']
        ?? $message['fromMe']
        ?? (($message['status'] ?? null) === 'sent')
        ?? false;

    return [
        'wa_message_id' => $message['id'] ?? null,
        'sender_name' => $senderName,
        'sender_phone' => $senderPhone,
        'message_type' => $type,
        'message_body' => trim((string) $body),
        'is_from_me' => (bool) $direction,
        'raw_payload' => $message,
    ];
}

/**
 * Extracts the textual body from different payload formats.
 */
function extractBody(array $message): ?string
{
    if (isset($message['text']) && is_array($message['text']) && isset($message['text']['body'])) {
        return (string) $message['text']['body'];
    }

    foreach (['message', 'body', 'caption'] as $key) {
        if (!empty($message[$key])) {
            return (string) $message[$key];
        }
    }

    if (isset($message['interactive']['body']['text'])) {
        return (string) $message['interactive']['body']['text'];
    }

    return null;
}

/**
 * Provides a compact label for media messages when content is unavailable.
 */
function labelForAttachment(string $type, array $message): string
{
    if ($type === 'text') {
        return '[mensagem sem conteúdo]';
    }

    $parts = [];
    $parts[] = strtoupper($type);

    if (!empty($message['media']['caption'])) {
        $parts[] = $message['media']['caption'];
    }

    return '[' . implode(' · ', $parts) . ']';
}
