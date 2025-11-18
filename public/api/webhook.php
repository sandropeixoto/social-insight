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

$logDirectory = __DIR__ . '/../../data';
$logFile = $logDirectory . '/webhook.log';

if (is_dir($logDirectory)) {
    $entry = sprintf(
        "[%s] %s%s",
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        $body,
        PHP_EOL
    );

    @file_put_contents($logFile, $entry, FILE_APPEND);
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

            $channelIdentifier = resolveChannelIdentifier($value, $metadata, $messagingProduct);
            $messages = extractMessagePayloads($value);

            foreach ($messages as $message) {
                $conversationId = resolveConversationId($message, $value, $metadata);

                if (!$conversationId) {
                    continue;
                }

                $sentAt = resolveTimestamp(
                    $message['timestamp']
                    ?? $message['sent_at']
                    ?? $value['moment']
                    ?? $value['timestamp']
                    ?? null
                );

                $groupName = resolveGroupName($message, $contacts, $conversationId);

                $groupId = upsertGroup($pdo, [
                    'wa_id' => $conversationId,
                    'name' => $groupName,
                    'channel' => $channelIdentifier,
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
    $candidates = [
        $message['group_id'] ?? null,
        $message['groupId'] ?? null,
        $message['chat_id'] ?? null,
        $message['chatId'] ?? null,
        $message['chat']['id'] ?? null,
        $message['chat']['jid'] ?? null,
        $message['from'] ?? null,
        $message['author'] ?? null,
        $value['chat']['id'] ?? null,
        $value['chatId'] ?? null,
        $metadata['phone_number_id'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!empty($candidate)) {
            return (string) $candidate;
        }
    }

    return null;
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
        ?? ($message['chat']['name'] ?? null)
        ?? ($message['chat']['subject'] ?? null)
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
        ?? ($message['sender']['id'] ?? null)
        ?? null;

    $senderName = $message['sender_name']
        ?? $message['senderName']
        ?? ($message['sender']['pushName'] ?? null)
        ?? $message['pushName']
        ?? $message['name']
        ?? ($senderPhone && isset($contacts[$senderPhone]) ? ($contacts[$senderPhone]['profile']['name'] ?? null) : null)
        ?? 'Desconhecido';

    $body = extractBody($message);

    if ($body === null && isset($message['msgContent']) && is_array($message['msgContent'])) {
        $body = extractBody($message['msgContent']);
    }

    if ($body === null) {
        $body = labelForAttachment($type, $message);

        if (($message['messageStubType'] ?? '') === 'CIPHERTEXT' && isset($message['messageStubParameters'][0])) {
            $body .= "\n" . trim((string) $message['messageStubParameters'][0]);
        }
    }

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

    if (!empty($message['conversation'])) {
        return (string) $message['conversation'];
    }

    if (isset($message['extendedTextMessage']['text'])) {
        return (string) $message['extendedTextMessage']['text'];
    }

    if (isset($message['reactionMessage']['text'])) {
        return (string) $message['reactionMessage']['text'];
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

/**
 * Normalizes payloads from different providers into a flat list of messages.
 */
function extractMessagePayloads(array $value): array
{
    $messages = [];

    if (isset($value['messages']) && is_array($value['messages'])) {
        foreach ($value['messages'] as $message) {
            if (!is_array($message)) {
                continue;
            }

            $messages[] = mergeMessageContext($message, $value);
        }
    }

    if (!empty($messages)) {
        return $messages;
    }

    if (isset($value['msgContent']) && is_array($value['msgContent'])) {
        return [mergeMessageContext($value['msgContent'], $value)];
    }

    if (!empty($value['event'])) {
        return [mergeMessageContext($value, $value)];
    }

    return [];
}

/**
 * Adds contextual data (chat, sender, identifiers) to a message array.
 */
function mergeMessageContext(array $message, array $value): array
{
    if (!isset($message['id']) && isset($value['messageId'])) {
        $message['id'] = $value['messageId'];
    }

    if (!isset($message['wa_message_id']) && isset($value['messageId'])) {
        $message['wa_message_id'] = $value['messageId'];
    }

    if (!isset($message['fromMe']) && isset($value['fromMe'])) {
        $message['fromMe'] = $value['fromMe'];
    }

    if (!isset($message['from_me']) && isset($value['from_me'])) {
        $message['from_me'] = $value['from_me'];
    }

    if (!isset($message['timestamp']) && isset($value['moment'])) {
        $message['timestamp'] = $value['moment'];
    }

    if (isset($value['chat']) && is_array($value['chat'])) {
        $existingChat = isset($message['chat']) && is_array($message['chat']) ? $message['chat'] : [];
        $message['chat'] = array_merge($value['chat'], $existingChat);
    }

    if (!isset($message['chat_id']) && isset($value['chat']['id'])) {
        $message['chat_id'] = $value['chat']['id'];
    }

    if (!isset($message['group_id']) && isset($value['chat']['id'])) {
        $message['group_id'] = $value['chat']['id'];
    }

    if (isset($value['sender']) && is_array($value['sender'])) {
        $existingSender = isset($message['sender']) && is_array($message['sender']) ? $message['sender'] : [];
        $message['sender'] = array_merge($value['sender'], $existingSender);
    }

    if (!isset($message['from']) && isset($value['sender']['id'])) {
        $message['from'] = $value['sender']['id'];
    }

    if (!isset($message['pushName']) && isset($value['sender']['pushName'])) {
        $message['pushName'] = $value['sender']['pushName'];
    }

    return $message;
}

/**
 * Attempts to determine the channel identifier (connected phone or fallback).
 */
function resolveChannelIdentifier(array $value, array $metadata, string $fallback): string
{
    $candidates = [
        $value['connectedPhone'] ?? null,
        $value['connected_phone'] ?? null,
        $metadata['phone_number_id'] ?? null,
        $metadata['display_phone_number'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $normalized = normalizeDigits($candidate);

        if ($normalized !== null) {
            return $normalized;
        }
    }

    return $fallback;
}

/**
 * Removes non-numeric characters from phone identifiers.
 */
function normalizeDigits($value): ?string
{
    if ($value === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', (string) $value);

    return $digits !== '' ? $digits : null;
}
