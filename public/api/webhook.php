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

$logDirectory = dirname(WEBHOOK_LOG_PATH);
$logFile = WEBHOOK_LOG_PATH;

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

                $isFromMe = isOutgoingMessage($message);
                $isGroupChat = isGroupConversationId($conversationId);
                $groupName = resolveGroupName($message, $contacts, $conversationId, $isFromMe, $value);
                $avatarUrl = resolveAvatarUrl($message, $contacts, $value, $conversationId, $isGroupChat);

                $groupId = upsertGroup($pdo, [
                    'wa_id' => $conversationId,
                    'name' => $groupName,
                    'channel' => $channelIdentifier,
                    'avatar_url' => $avatarUrl,
                    'last_message_at' => $sentAt,
                ], true);

                $messageData = normalizeMessage($message, $contacts);
                $messageData['sent_at'] = $sentAt;
                $messageData = hydrateMediaMetadata($messageData);

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
function resolveGroupName(array $message, array $contacts, string $conversationId, bool $isFromMe, array $context = []): string
{
    $isGroup = isGroupConversationId($conversationId);

    $groupCandidates = [
        $message['group_name'] ?? null,
        $message['groupName'] ?? null,
        $message['chat_name'] ?? null,
        $message['chatName'] ?? null,
        $message['chat']['subject'] ?? null,
        $message['chat']['title'] ?? null,
        $message['chat']['name'] ?? null,
        $message['name'] ?? null,
        $contacts[$conversationId]['profile']['name'] ?? null,
        $contacts[$conversationId]['profile']['pushName'] ?? null,
        $context['chat']['subject'] ?? null,
        $context['chat']['title'] ?? null,
        $context['chat']['name'] ?? null,
        $context['name'] ?? null,
    ];

    foreach ($groupCandidates as $candidate) {
        if (is_string($candidate)) {
            $trimmed = trim($candidate);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }
    }

    if (!$isGroup) {
        $contactCandidates = [
            $message['chat']['name'] ?? null,
            $message['chat']['pushName'] ?? null,
            $context['chat']['name'] ?? null,
            $context['chat']['pushName'] ?? null,
            $contacts[$conversationId]['profile']['name'] ?? null,
            $contacts[$conversationId]['profile']['pushName'] ?? null,
            $message['sender_name'] ?? null,
        ];

        if (!$isFromMe) {
            $contactCandidates[] = $message['sender']['pushName'] ?? null;
            $contactCandidates[] = $message['sender']['name'] ?? null;
            $contactCandidates[] = $message['sender']['profile']['name'] ?? null;
            $contactCandidates[] = $message['pushName'] ?? null;
            $contactCandidates[] = $message['name'] ?? null;
            $contactCandidates[] = $context['sender']['pushName'] ?? null;
            $contactCandidates[] = $context['sender']['name'] ?? null;
            $contactCandidates[] = $context['sender']['profile']['name'] ?? null;
        }

        foreach ($contactCandidates as $candidate) {
            if (is_string($candidate)) {
                $trimmed = trim($candidate);

                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }
    }

    return $conversationId;
}

/**
 * Attempts to resolve a conversation avatar URL.
 */
function resolveAvatarUrl(array $message, array $contacts, array $context, string $conversationId, bool $isGroup): ?string
{
    $candidates = [
        $message['chat']['profilePicture'] ?? null,
        $context['chat']['profilePicture'] ?? null,
        $contacts[$conversationId]['profilePicture'] ?? null,
        $contacts[$conversationId]['profile']['picture'] ?? null,
    ];

    if (!$isGroup) {
        $candidates[] = $message['sender']['profilePicture'] ?? null;
        $candidates[] = $context['sender']['profilePicture'] ?? null;
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return $candidate;
        }
    }

    return null;
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

    $mediaAttachment = extractMediaAttachment($message);

    if ($body === null && isset($message['msgContent']) && is_array($message['msgContent'])) {
        $body = extractBody($message['msgContent']);
    }

    if ($body === null) {
        if ($mediaAttachment && isset($mediaAttachment['caption']) && $mediaAttachment['caption'] !== '') {
            $body = $mediaAttachment['caption'];
        } else {
            $body = labelForAttachment($type, $message);
        }

        if (($message['messageStubType'] ?? '') === 'CIPHERTEXT' && isset($message['messageStubParameters'][0])) {
            $body .= "\n" . trim((string) $message['messageStubParameters'][0]);
        }
    }

    $direction = isOutgoingMessage($message);

    if ($mediaAttachment) {
        $type = $mediaAttachment['type'] ?? $type;
    }

    return [
        'wa_message_id' => $message['id'] ?? null,
        'sender_name' => $senderName,
        'sender_phone' => $senderPhone,
        'message_type' => $type,
        'message_body' => trim((string) $body),
        'is_from_me' => (bool) $direction,
        'raw_payload' => $message,
        'media' => $mediaAttachment,
    ];
}

/**
 * Applies consistent media metadata to the normalized message array.
 */
function hydrateMediaMetadata(array $messageData): array
{
    $media = $messageData['media'] ?? null;

    if (is_array($media) && !empty($media)) {
        $messageData['media_url'] = $media['url'] ?? null;
        $messageData['media_caption'] = $media['caption'] ?? null;
        $messageData['media_mime'] = $media['mime'] ?? null;
        $messageData['media_size'] = $media['size'] ?? null;
        $messageData['media_duration'] = $media['duration'] ?? null;
        $messageData['media_original_name'] = $media['filename'] ?? null;

        if (env_bool('MEDIA_DOWNLOAD_ENABLED', false) && !empty($media['url'])) {
            try {
                $fileContent = downloadMediaFile($media['url']);
                $relativePath = storeMediaFile($fileContent, $media['mime'] ?? 'application/octet-stream');
                $messageData['media_path'] = $relativePath;
            } catch (Throwable $exception) {
                error_log('Failed to download media for ' . ($messageData['wa_message_id'] ?? 'unknown') . ': ' . $exception->getMessage());
            }
        }
    }

    unset($messageData['media']);

    return $messageData;
}

function isOutgoingMessage(array $message): bool
{
    return (bool) ($message['from_me']
        ?? $message['fromMe']
        ?? (($message['status'] ?? null) === 'sent')
        ?? false);
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

/**
 * Determines if a conversation identifier belongs to a group.
 */
function isGroupConversationId(?string $identifier): bool
{
    if ($identifier === null) {
        return false;
    }

    $normalized = strtolower($identifier);

    return str_contains($normalized, '@g.us') || str_contains($normalized, '@broadcast');
}

/**
 * Attempts to extract a media attachment from the message payload.
 */
function extractMediaAttachment(array $message): ?array
{
    $attachmentMap = [
        'imageMessage' => 'image',
        'audioMessage' => 'audio',
        'stickerMessage' => 'sticker',
        'documentMessage' => 'document',
    ];

    foreach ($attachmentMap as $key => $type) {
        if (!isset($message[$key]) || !is_array($message[$key])) {
            continue;
        }

        $payload = $message[$key];

        return normalizeAttachmentPayload($payload, $type);
    }

    if (isset($message['message']) && is_array($message['message'])) {
        return extractMediaAttachment($message['message']);
    }

    if (isset($message['msgContent']) && is_array($message['msgContent'])) {
        return extractMediaAttachment($message['msgContent']);
    }

    return null;
}

/**
 * Normalizes raw attachment payloads into a consistent array.
 */
function normalizeAttachmentPayload(array $payload, string $type): array
{
    $url = resolveMediaUrl($payload);
    $mime = $payload['mimetype'] ?? 'application/octet-stream';
    $caption = $payload['caption'] ?? $payload['text'] ?? $payload['title'] ?? null;

    return [
        'type' => $type,
        'url' => $url,
        'mime' => $mime,
        'size' => isset($payload['fileLength']) ? (int) $payload['fileLength'] : null,
        'duration' => isset($payload['seconds']) ? (int) $payload['seconds'] : null,
        'caption' => $caption,
        'filename' => $payload['fileName'] ?? $payload['title'] ?? null,
        'sha256' => $payload['fileSha256'] ?? $payload['fileEncSha256'] ?? null,
        'waveform' => $payload['waveform'] ?? null,
        'media_key' => $payload['mediaKey'] ?? null,
        'file_enc_sha256' => $payload['fileEncSha256'] ?? null,
    ];
}

function resolveMediaUrl(array $payload): ?string
{
    $url = $payload['url'] ?? null;

    if (is_string($url) && $url !== '' && preg_match('#^https?://#i', $url)) {
        return $url;
    }

    $directPath = $payload['directPath'] ?? null;

    if (is_string($directPath) && $directPath !== '') {
        $base = rtrim(env('MEDIA_CDN_BASE_URL', 'https://mmg.whatsapp.net'), '/');

        return $base . '/' . ltrim($directPath, '/');
    }

    return $url ?: null;
}

/**
 * Stores media content and returns the relative path.
 */
function storeMediaFile(string $content, string $mime): string
{
    $directory = MEDIA_STORAGE_PATH;
    $extension = getExtensionForMimeType($mime);
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $path = $directory . DIRECTORY_SEPARATOR . $filename;

    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Unable to write media file to ' . $path);
    }

    return $filename;
}

/**
 * Returns a file extension for a given mime type.
 */
function getExtensionForMimeType(string $mime): string
{
    static $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'audio/aac' => 'aac',
        'audio/mp4' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/amr' => 'amr',
        'audio/ogg' => 'ogg',
        'video/mp4' => 'mp4',
        'application/pdf' => 'pdf',
    ];

    return $map[$mime] ?? 'bin';
}

/**
 * Downloads a remote file using cURL.
 */
function downloadMediaFile(string $url): string
{
    $handle = curl_init($url);

    if ($handle === false) {
        throw new RuntimeException('Unable to initialize media download.');
    }

    $verifySsl = env_bool('MEDIA_VERIFY_SSL', env_bool('WAPI_VERIFY_SSL', true));

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => (int) env('MEDIA_DOWNLOAD_TIMEOUT', 30),
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        CURLOPT_HTTPHEADER => ['User-Agent: SocialInsightBot/1.0'],
    ]);

    $result = curl_exec($handle);

    if ($result === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('Media download failed: ' . $error);
    }

    $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($statusCode >= 400) {
        throw new RuntimeException('Media download responded with HTTP ' . $statusCode);
    }

    return $result;
}
