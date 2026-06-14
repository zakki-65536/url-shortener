<?php

declare(strict_types=1);

require '../config.php';

// CSRF対策
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'secure'   => true,
  'httponly' => true,
  'samesite' => 'Strict',
]);

session_start();

if (!isset($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedToken = $_POST['csrf_token'] ?? '';
  echo $csrfToken . "<br>";
  echo $postedToken . "<br>";

  if (
    !is_string($postedToken) ||
    !hash_equals($csrfToken, $postedToken)
  ) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $action = $_POST['action'] ?? 'create';

  if ($action === 'toggle') {
    $id = filter_input(
      INPUT_POST,
      'id',
      FILTER_VALIDATE_INT
    );

    if ($id) {
      $stmt = db()->prepare(
        'UPDATE short_links
          SET is_active =
            CASE
              WHEN is_active = 1 THEN 0
              ELSE 1
            END
          WHERE id = :id'
      );

      $stmt->execute([
        ':id' => $id,
      ]);
    }

    header('Location: ./');
    exit;
  }

  $destinationUrl = trim(
    (string) ($_POST['destination_url'] ?? '')
  );

  $title = trim(
    (string) ($_POST['title'] ?? '')
  );

  $customCode = trim(
    (string) ($_POST['code'] ?? '')
  );

  $errors = [];

  if (!isValidDestinationUrl($destinationUrl)) {
    $errors[] =
      '転送先URLには、正しいHTTPまたはHTTPSのURLを入力してください。';
  }

  if (
    $customCode !== '' &&
    !preg_match(
      '/^[A-Za-z0-9_-]{4,32}$/',
      $customCode
    )
  ) {
    $errors[] =
      'コードは4～32文字の英数字、ハイフン、アンダースコアで入力してください。';
  }

  if (mb_strlen($title, 'UTF-8') > 255) {
    $errors[] = 'タイトルは255文字以内で入力してください。';
  }

  if (!$errors) {
    $created = false;

    for ($attempt = 0; $attempt < 10; $attempt++) {
      $code = $customCode !== ''
        ? $customCode
        : generateShortCode(7);

      try {
        $stmt = db()->prepare(
          'INSERT INTO short_links (
              code,
              destination_url,
              title
            ) VALUES (
              :code,
              :destination_url,
              :title
            )'
        );

        $stmt->execute([
          ':code'            => $code,
          ':destination_url' => $destinationUrl,
          ':title'           => $title,
        ]);

        $_SESSION['message'] =
          '短縮URLを登録しました: ' .
          PUBLIC_BASE_URL . '/' . $code;

        $created = true;
        break;
      } catch (PDOException $e) {
        /*
                 * 自動生成コードの重複なら再生成します。
                 */
        if (
          $e->getCode() === '23000' &&
          $customCode === ''
        ) {
          continue;
        }

        if ($e->getCode() === '23000') {
          $errors[] =
            'その短縮コードはすでに使用されています。';
          break;
        }

        throw $e;
      }
    }

    if (!$created && !$errors) {
      $errors[] =
        '短縮コードを生成できませんでした。';
    }

    if ($created) {
      header('Location: ./');
      exit;
    }
  }
}

$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);

$stmt = db()->query(
  'SELECT
        id,
        code,
        destination_url,
        title,
        is_active,
        expires_at,
        created_at
     FROM short_links
     ORDER BY id DESC
     LIMIT 100'
);

$links = $stmt->fetchAll();
$totalLinks = (int) db()
  ->query('SELECT COUNT(*) FROM short_links')
  ->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1">
  <title>短縮URL管理ツール</title>
  <link rel="stylesheet" href="style.css">
</head>

<body>

  <div class="page-container">

    <header class="page-header">
      <div>
        <p class="eyebrow">URL SHORTENER</p>
        <h1>短縮URL管理</h1>
        <p class="page-description">
          短縮URLの登録とアクセス状況を管理できます。
        </p>
      </div>

      <button
        type="button"
        class="button button--primary"
        id="openCreateModal">
        <span aria-hidden="true">＋</span>
        新規URLを登録
      </button>
    </header>


    <main>
      <section class="panel" aria-labelledby="registeredUrlTitle">

        <div class="panel-header">
          <div>
            <h2 id="registeredUrlTitle">登録済みURL</h2>
            <p>行をクリックすると登録内容を確認できます。</p>
          </div>

          <div class="registered-count">
            <strong><?= number_format($totalLinks) ?></strong>
            <span>件</span>
          </div>
        </div>


        <div class="table-wrapper">
          <table class="url-table">
            <thead>
              <tr>
                <th scope="col">状態</th>
                <th scope="col">登録日</th>
                <th scope="col">タイトル</th>
                <th scope="col">短縮URL</th>
                <th scope="col">アクセス数</th>
              </tr>
            </thead>

            <tbody>
              <?php foreach ($links as $link): ?>
                <?php
                $shortUrl =
                  PUBLIC_BASE_URL . '/' . $link['code'];
                $displayCode = substr((string) $link['code'], -6);

                $stmt = db()->prepare(
                  'SELECT
                    COUNT(*) AS total_count,
                    SUM(
                        CASE
                            WHEN accessed_at >= CURDATE()
                            THEN 1
                            ELSE 0
                        END
                    ) AS today_count,
                    COUNT(
                        DISTINCT visitor_hash
                    ) AS estimated_unique_count

                    FROM access_logs
                    WHERE short_link_id = :id'
                );

                $stmt->execute([
                  ':id' => $link['id'],
                ]);
                $summary = $stmt->fetch();

                ?>
                <tr
                  class="url-row"
                  tabindex="0"
                  role="button"
                  aria-label="<?= e($link['title']) ?>の登録内容を表示"
                  data-title="<?= e($link['title']) ?>"
                  data-status="<?= $link['is_active']
                                  ? '有効'
                                  : '停止中' ?>"
                  data-created-at="<?= date('Y/m/d H:i', strtotime($link['created_at'])) ?>"
                  data-short-url="<?= e($shortUrl)  ?>"
                  data-destination-url="<?= e($link['destination_url'])  ?>"
                  data-detail-url="detail.php?id=<?= e($link['id'])  ?>">
                  <td data-label="状態">
                    <span class="status-badge status-badge--active">
                      <span
                        class="status-badge__dot"
                        aria-hidden="true"></span>
                      <?= $link['is_active']
                        ? '有効'
                        : '停止中' ?>
                    </span>
                  </td>

                  <td data-label="登録日">
                    <time datetime="2026-06-13">
                      <?= date('Y/m/d', strtotime($link['created_at'])) ?>
                    </time>
                  </td>

                  <td data-label="タイトル">
                    <strong class="url-title">
                      <?= e($link['title']) ?>
                    </strong>
                  </td>

                  <td data-label="短縮URL">
                    <!-- 短縮URLの末尾6文字だけを表示 -->
                    <span class="short-code">
                      <?= e($displayCode) ?>
                    </span>
                  </td>

                  <td data-label="アクセス数">
                    <div class="access-count">
                      <strong><?= e($summary['total_count'] ?? 0) ?></strong>
                      <span>回</span>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>

            </tbody>
          </table>
        </div>

      </section>
    </main>

  </div>


  <!-- 新規登録モーダル -->
  <dialog
    class="modal"
    id="createModal"
    aria-labelledby="createModalTitle">
    <div class="modal-content">
      <?php if ($message): ?>
        <p class="message"><?= e($message) ?></p>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="error">
          <?php foreach ($errors as $error): ?>
            <p><?= e($error) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>


      <header class="modal-header">
        <div>
          <p class="eyebrow">CREATE NEW URL</p>
          <h2 id="createModalTitle">短縮URLを新規登録</h2>
          <p>転送先URLと短縮コードを設定してください。</p>
        </div>

        <button
          type="button"
          class="modal-close"
          data-close-modal
          aria-label="閉じる">
          ×
        </button>
      </header>


      <form method="post" class="registration-form">
        <input
          type="hidden"
          name="csrf_token"
          value="<?= e($csrfToken) ?>">

        <input
          type="hidden"
          name="action"
          value="create">

        <div class="form-group">
          <label for="title">
            タイトル
            <span class="optional-label">任意</span>
          </label>

          <input
            type="text"
            id="title"
            name="title"
            maxlength="255"
            placeholder="キャンペーンページ">
        </div>

        <div class="form-group">
          <label for="destinationUrl">
            転送先URL
            <span class="required-label">必須</span>
          </label>

          <input
            type="url"
            id="destinationUrl"
            name="destination_url"
            required
            placeholder="https://example.com/page">
        </div>

        <div class="form-group">
          <label for="shortCode">
            短縮コード
            <span class="optional-label">任意</span>
          </label>

          <div class="short-code-input">
            <span class="short-code-input__prefix">
              /rd/
            </span>

            <input
              type="text"
              id="shortCode"
              name="code"
              minlength="4"
              maxlength="32"
              pattern="[A-Za-z0-9_-]+"
              placeholder="aB3x9K">
          </div>

          <p class="form-help">
            空欄の場合は自動生成されます。
          </p>
        </div>


        <footer class="modal-footer">
          <button
            type="button"
            class="button button--secondary"
            data-close-modal>
            キャンセル
          </button>

          <button
            type="submit"
            class="button button--primary">
            登録する
          </button>
        </footer>
      </form>

    </div>
  </dialog>


  <!-- URL詳細モーダル -->
  <dialog
    class="modal"
    id="detailModal"
    aria-labelledby="detailModalTitle">
    <div class="modal-content">

      <header class="modal-header">
        <div>
          <p class="eyebrow">URL INFORMATION</p>
          <h2 id="detailModalTitle">URL登録内容</h2>
          <p id="detailModalMeta"></p>
        </div>

        <button
          type="button"
          class="modal-close"
          data-close-modal
          aria-label="閉じる">
          ×
        </button>
      </header>


      <div class="detail-content">

        <dl class="detail-list">
          <div class="detail-list__item">
            <dt>状態</dt>
            <dd>
              <span
                class="status-badge status-badge--active"
                id="modalStatusBadge">
                <span
                  class="status-badge__dot"
                  aria-hidden="true"></span>

                <span id="modalStatus"></span>
              </span>
            </dd>
          </div>

          <div class="detail-list__item">
            <dt>短縮URL</dt>
            <dd>
              <a
                href="#"
                id="modalShortUrl"
                class="detail-url"
                target="_blank"
                rel="noopener noreferrer"></a>
            </dd>
          </div>

          <div class="detail-list__item">
            <dt>転送先URL</dt>
            <dd>
              <a
                href="#"
                id="modalDestinationUrl"
                class="detail-url"
                target="_blank"
                rel="noopener noreferrer"></a>
            </dd>
          </div>
        </dl>


        <footer class="modal-footer detail-footer">
          <button
            type="button"
            class="button button--secondary"
            data-close-modal>
            閉じる
          </button>

          <a
            href="#"
            class="button button--primary"
            id="modalDetailLink">
            詳細ページを見る
            <span aria-hidden="true">→</span>
          </a>
        </footer>

      </div>

    </div>
  </dialog>



</body>
<script src="index.js"></script>

</html>