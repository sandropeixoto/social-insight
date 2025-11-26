<?php
require_once __DIR__ . '/../../bootstrap.php';

session_start();

$valid_username = env('ADMIN_USERNAME', 'admin');
$password_hash = env('ADMIN_PASSWORD_HASH');

if (!$password_hash) {
    // Fallback to a default password if the hash is not set in the environment.
    // This is not recommended for production.
    $password_hash = password_hash('password', PASSWORD_DEFAULT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === $valid_username && password_verify($password, $password_hash)) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = 1;
        header('Location: ../index.php');
        exit;
    } else {
        header('Location: ../login.php?error=Credenciais inválidas');
        exit;
    }
}

header('Location: ../login.php');
exit;
