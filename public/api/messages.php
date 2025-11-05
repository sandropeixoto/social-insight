<?php

declare(strict_types=1);

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
    'SELECT id, wa_message_id, sender_name, sender_phone, message_type, message_body, is_from_me, sent_at
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
    ], $messages),
], JSON_UNESCAPED_UNICODE);
