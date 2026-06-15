<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$receivedToken = (string)($_POST['csrf_token'] ?? '');
$sessionToken = (string)($_SESSION['csrf_token'] ?? '');

if (
  $sessionToken === '' ||
  !hash_equals($sessionToken, $receivedToken)
) {
  http_response_code(403);
  exit('不正なリクエストです。');
}

// セッション内のデータを削除
$_SESSION = [];

// セッションCookieを削除
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();

  setcookie(session_name(), '', [
    'expires'  => time() - 42000,
    'path'     => $params['path'],
    'domain'   => $params['domain'],
    'secure'   => $params['secure'],
    'httponly' => $params['httponly'],
    'samesite' => 'Lax',
  ]);
}

// サーバー側のセッションを破棄
session_destroy();

header('Location: login.php');
exit;
