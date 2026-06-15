<?php

declare(strict_types=1);

ini_set('session.use_strict_mode', '1');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => '',
  'secure'   => $isHttps,
  'httponly' => true,
  'samesite' => 'Lax',
]);

session_start();

/**
 * 未ログインの場合はログインページへ移動
 */
function requireLogin(): void
{
  if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
  }
}

/**
 * CSRFトークンを取得
 */
function getCsrfToken(): string
{
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }

  return $_SESSION['csrf_token'];
}
