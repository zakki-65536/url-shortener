<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require '../config.php';

if (!empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}

$error = '';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim((string)($_POST['username'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  if ($username === '' || $password === '') {
    $error = 'ユーザー名とパスワードを入力してください。';
  } else {
    $stmt = $pdo->prepare(
      'SELECT id, username, password_hash
             FROM users
             WHERE username = :username
             LIMIT 1'
    );

    $stmt->execute([
      ':username' => $username,
    ]);

    $user = $stmt->fetch();

    if (
      $user !== false &&
      password_verify($password, $user['password_hash'])
    ) {
      // ログイン成功時にセッションIDを変更
      session_regenerate_id(true);

      $_SESSION['user_id'] = (int)$user['id'];
      $_SESSION['username'] = $user['username'];
      $_SESSION['logged_in_at'] = time();

      unset($_SESSION['csrf_token']);

      header('Location: index.php');
      exit;
    }

    $error = 'ユーザー名またはパスワードが正しくありません。';
  }
}
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>ログイン</title>
  <link rel="stylesheet" href="style.css">
</head>

<body class="login-page">
  <main class="login-layout">
    <section class="login-card" aria-labelledby="login-title">
      <header class="login-card__header">
        <div class="login-logo" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="25" height="25" fill="none">
            <path d="M8.75 10.5V8a3.25 3.25 0 0 1 6.5 0v2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            <rect x="5.5" y="10.5" width="13" height="9" rx="2.4" stroke="currentColor" stroke-width="1.8" />
            <path d="M12 14.1v2.7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          </svg>
        </div>

        <p class="eyebrow">ACCOUNT</p>
        <h1 id="login-title">ログイン</h1>
        <p class="login-card__description">
          ユーザー名とパスワードを入力してください。
        </p>
      </header>

      <form class="login-form" method="post" action="login.php" novalidate>
        <input
          type="hidden"
          name="csrf_token"
          value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <?php if ($error !== ''): ?>
          <div class="form-alert" role="alert">
            <span class="form-alert__icon" aria-hidden="true">!</span>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        <?php endif; ?>

        <div class="form-group">
          <label for="username">ユーザー名</label>
          <input
            id="username"
            type="text"
            name="username"
            placeholder="name@example.com"
            autocomplete="username"
            inputmode="username"
            maxlength="255"
            required
            autofocus>
        </div>

        <div class="form-group">
          <label for="password">パスワード</label>
          <div class="password-field">
            <input
              id="password"
              type="password"
              name="password"
              autocomplete="current-password"
              required>
          </div>
        </div>

        <button hidden type="submit" name="enter"></button>

        <button class="button button--primary login-submit" type="submit">
          ログイン
        </button>
      </form>

    </section>
  </main>

</body>

</html>