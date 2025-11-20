<?php

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$groupId = filter_input(INPUT_GET, 'group_id', FILTER_VALIDATE_INT);

if (!$groupId) {
    http_response_code(400);
    echo json_encode(['error' => 'group_id is required']);
    exit;
}

$pdo = db();

$group = $pdo->prepare('SELECT id, COALESCE(name, wa_id) AS name FROM groups WHERE id = :id');
$group->execute([':id' => $groupId]);
$groupRow = $group->fetch();

if (!$groupRow) {
    http_response_code(404);
    echo json_encode(['error' => 'Group not found']);
    exit;
}

$query = $pdo->prepare(
    'SELECT id, wa_message_id, sender_name, sender_phone, message_type, message_body, is_from_me, sent_at,
            media_path, media_mime, media_size, media_duration, media_caption, media_original_name
     FROM messages
     WHERE group_id = :group_id
     ORDER BY sent_at ASC, id ASC'
);
$query->execute([':group_id' => $groupId]);

$messages = $query->fetchAll();

echo json_encode([
    'group' => [
        'id' => (int) $groupRow['id'],
        'name' => $groupRow['name'],
    ],
    'data' => array_map(static fn (array $message): array => [
        'id' => (int) $message['id'],
        'wa_message_id' => $message['wa_message_id'],
        'sender_name' => $message['sender_name'],
        'sender_phone' => $message['sender_phone'],
        'message_type' => $message['message_type'],
        'message_body' => $message['message_body'],
        'is_from_me' => (bool) $message['is_from_me'],
        'sent_at' => $message['sent_at'],
        'media' => buildMediaResponse($message),
    ], $messages),
], JSON_UNESCAPED_UNICODE);

function buildMediaResponse(array $message): ?array
{
    $remoteUrl = $message['media_url'] ?? null;
    $localPath = $message['media_path'] ?? null;

    if (!$remoteUrl && !$localPath) {
        return null;
    }

    $path = $localPath ? trim(str_replace(['\\'], '/', (string) $localPath), '/') : null;

    return [
        'url' => $remoteUrl ?: ('media.php?path=' . rawurlencode((string) $path)),
        'mime' => $message['media_mime'],
        'size' => isset($message['media_size']) ? (int) $message['media_size'] : null,
        'duration' => isset($message['media_duration']) ? (int) $message['media_duration'] : null,
        'caption' => $message['media_caption'],
        'original_name' => $message['media_original_name'],
    ];
}
