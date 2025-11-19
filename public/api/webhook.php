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

                $isFromMe = isOutgoingMessage($message);

                $groupName = resolveGroupName($message, $contacts, $conversationId, $isFromMe, $value);

                $groupId = upsertGroup($pdo, [
                    'wa_id' => $conversationId,
                    'name' => $groupName,
                    'channel' => $channelIdentifier,
                    'last_message_at' => $sentAt,
                ]);

                $messageData = normalizeMessage($message, $contacts);
                $messageData['sent_at'] = $sentAt;

                if (!empty($messageData['media']) && is_array($messageData['media'])) {
                    $persisted = persistMediaAttachment($messageData['media'], $conversationId, $sentAt, $messageData['wa_message_id'] ?? null);

                    if ($persisted) {
                        $messageData['media_path'] = $persisted['relative_path'];
                        $messageData['media_mime'] = $persisted['mime'];
                        $messageData['media_size'] = $persisted['size'];
                        $messageData['media_duration'] = $persisted['duration'];
                        $messageData['media_caption'] = $persisted['caption'];
                        $messageData['media_original_name'] = $persisted['original_name'];

                        if (($messageData['message_body'] ?? '') === '' && $persisted['caption']) {
                            $messageData['message_body'] = $persisted['caption'];
                        }
                    }
                }

                unset($messageData['media']);

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

function decryptWhatsAppMedia(string $binary, array $media): ?string
{
    $mediaKeyBase64 = $media['media_key'] ?? null;
    if (!$mediaKeyBase64) {
        return null;
    }

    $mediaKey = base64_decode($mediaKeyBase64, true);

    if ($mediaKey === false) {
        return null;
    }

    $type = strtolower((string) ($media['type'] ?? 'media'));
    $infoMap = [
        'image' => 'WhatsApp Image Keys',
        'audio' => 'WhatsApp Audio Keys',
        'video' => 'WhatsApp Video Keys',
        'document' => 'WhatsApp Document Keys',
        'sticker' => 'WhatsApp Image Keys',
    ];

    $info = $infoMap[$type] ?? 'WhatsApp Media Keys';

    $derived = hkdfSHA256($mediaKey, 112, $info);

    if ($derived === null) {
        return null;
    }

    $iv = substr($derived, 0, 16);
    $cipherKey = substr($derived, 16, 32);
    $macKey = substr($derived, 48, 32);

    if (strlen($binary) <= 10) {
        return null;
    }

    $mac = substr($binary, -10);
    $ciphertext = substr($binary, 0, -10);

    $calcMac = substr(hash_hmac('sha256', $ciphertext . $iv, $macKey, true), 0, 10);

    if (!hash_equals($mac, $calcMac)) {
        return null;
    }

    $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv);

    if ($plaintext === false) {
        return null;
    }

    return $plaintext;
}

function hkdfSHA256(string $ikm, int $length, string $info = '', ?string $salt = null): ?string
{
    $hashLength = 32;
    $salt = $salt ?? str_repeat("\0", $hashLength);
    $prk = hash_hmac('sha256', $ikm, $salt, true);

    $blocks = (int) ceil($length / $hashLength);
    $okm = '';
    $previous = '';

    for ($block = 1; $block <= $blocks; $block++) {
        $previous = hash_hmac('sha256', $previous . $info . chr($block), $prk, true);
        $okm .= $previous;
    }

    return substr($okm, 0, $length);
}

/**
 * Persists a downloaded attachment to disk.
 */
function persistMediaAttachment(?array $media, string $conversationId, string $sentAt, ?string $messageId): ?array
{
    if (!$media || empty($media['url'])) {
        return null;
    }

    $root = defined('MEDIA_STORAGE_PATH') ? MEDIA_STORAGE_PATH : (__DIR__ . '/../../data/media');

    try {
        $binary = downloadMediaFile($media['url']);
    } catch (Throwable $exception) {
        error_log('Failed to download media: ' . $exception->getMessage());
        return null;
    }

    $decrypted = decryptWhatsAppMedia($binary, $media);

    if ($decrypted !== null) {
        $binary = $decrypted;
    }

    try {
        $timestamp = new DateTimeImmutable($sentAt);
    } catch (Throwable $exception) {
        $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    $year = $timestamp->format('Y');
    $month = $timestamp->format('m');
    $safeConversation = sanitizeConversationPath($conversationId);

    $relativeDirectory = $year . '/' . $month . '/' . $safeConversation;
    $absoluteDirectory = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeDirectory;

    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
        error_log('Unable to create media directory: ' . $absoluteDirectory);
        return null;
    }

    $sequence = nextMediaSequence($absoluteDirectory);
    $extension = guessExtensionFromMime($media['mime'] ?? '') ?? 'bin';
    $baseName = $timestamp->format('Ymd_His');

    if ($messageId) {
        $baseName .= '_' . substr(preg_replace('/[^a-f0-9]/i', '', $messageId), 0, 6);
    }

    $fileName = sprintf('%s-%s.%s', $baseName, str_pad((string) $sequence, 4, '0', STR_PAD_LEFT), $extension);
    $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $fileName;

    if (file_put_contents($absolutePath, $binary) === false) {
        error_log('Unable to write media file: ' . $absolutePath);
        return null;
    }

    return [
        'relative_path' => trim($relativeDirectory . '/' . $fileName, '/'),
        'absolute_path' => $absolutePath,
        'mime' => $media['mime'] ?? 'application/octet-stream',
        'size' => @filesize($absolutePath) ?: $media['size'] ?? null,
        'duration' => $media['duration'] ?? null,
        'caption' => $media['caption'] ?? null,
        'original_name' => $media['filename'] ?? null,
    ];
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

function sanitizeConversationPath(string $identifier): string
{
    $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);

    return trim($sanitized, '_') ?: 'conversation';
}

function nextMediaSequence(string $directory): int
{
    $max = 0;

    if (is_dir($directory)) {
        $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $file) {
            $name = $file->getFilename();

            if (preg_match('/-(\d{4})\.[^.]+$/', $name, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }
    }

    return $max + 1;
}

function guessExtensionFromMime(?string $mime): ?string
{
    if (!$mime) {
        return null;
    }

    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'audio/ogg' => 'ogg',
        'audio/opus' => 'opus',
        'audio/mpeg' => 'mp3',
        'audio/amr' => 'amr',
        'audio/aac' => 'aac',
        'video/mp4' => 'mp4',
        'application/pdf' => 'pdf',
    ];

    if (isset($map[$mime])) {
        return $map[$mime];
    }

    if (str_starts_with($mime, 'image/')) {
        return substr($mime, 6);
    }

    if (str_starts_with($mime, 'audio/')) {
        return substr($mime, 6);
    }

    if (str_starts_with($mime, 'video/')) {
        return substr($mime, 6);
    }

    return null;
}
