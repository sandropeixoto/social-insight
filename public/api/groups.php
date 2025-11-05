<?php

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

$query = $pdo->query(
    'SELECT
        g.id,
        g.wa_id,
        COALESCE(g.name, g.wa_id) AS name,
        g.channel,
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
        ) AS message_count
     FROM groups g
     ORDER BY COALESCE(g.last_message_at, g.created_at) DESC'
);

$groups = $query->fetchAll();

echo json_encode([
    'data' => array_map(static fn (array $group): array => [
        'id' => (int) $group['id'],
        'wa_id' => $group['wa_id'],
        'name' => $group['name'],
        'channel' => $group['channel'],
        'last_message_body' => $group['last_message_body'],
        'last_message_sent_at' => $group['last_message_sent_at'],
        'message_count' => (int) $group['message_count'],
    ], $groups),
], JSON_UNESCAPED_UNICODE);
