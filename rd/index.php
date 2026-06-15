<?php

declare(strict_types=1);

require 'config.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'HEAD'], true)) {
  http_response_code(405);
  header('Allow: GET, HEAD');
  exit('Method Not Allowed');
}

$code = $_GET['code'] ?? '';

if (!is_string($code)) {
  http_response_code(404);
  case404();
}

if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $code)) {
  http_response_code(404);
  case404();
}

try {
  $stmt = db()->prepare(
    'SELECT
      id,
      destination_url
      FROM short_links
      WHERE code = :code
        AND is_active = 1
        AND (
          expires_at IS NULL
          OR expires_at > NOW()
        )
      LIMIT 1'
  );

  $stmt->execute([
    ':code' => $code,
  ]);

  $link = $stmt->fetch();
} catch (Throwable $e) {
  error_log(
    'Short URL lookup failed: ' . $e->getMessage()
  );

  http_response_code(503);
  exit('Service Unavailable');
}

if (!$link) {
  http_response_code(404);
  case404();
}

$destinationUrl = (string) $link['destination_url'];

// DBに不正な値が入った場合に備え、転送時にも再検証します。
if (!isValidDestinationUrl($destinationUrl)) {
  error_log(
    'Invalid destination URL. link_id=' .
      (string) $link['id']
  );

  http_response_code(500);
  exit('Invalid redirect destination');
}

// HEADリクエストはアクセス数に含めない
if ($method === 'GET') {
  try {
    $logStmt = db()->prepare(
      'INSERT INTO access_logs (
          short_link_id,
          accessed_at,
          visitor_hash,
          user_agent,
          referrer_host,
          request_method
        ) VALUES (
          :short_link_id,
          NOW(),
          :visitor_hash,
          :user_agent,
          :referrer_host,
          :request_method
        )'
    );


    $userAgent = substr(
      $_SERVER['HTTP_USER_AGENT'] ?? '',
      0,
      512
    );

    $logStmt->execute([
      ':short_link_id' => (int) $link['id'],
      ':visitor_hash'  => createVisitorHash(),
      ':user_agent'    => $userAgent !== ''
        ? $userAgent
        : null,
      ':referrer_host' => getReferrerHost(),
      ':request_method' => $method,
    ]);
  } catch (Throwable $e) {
    error_log(
      'Access log insert failed: ' . $e->getMessage()
    );
  }
}

/*
 * 301ではなく302を使用します。
 * 後から転送先を変更しやすくするためです。
 */
header('Cache-Control: no-store, private');
header('Location: ' . $destinationUrl, true, 302);
exit;

function case404()
{
?>
  <!DOCTYPE html>
  <html lang="ja">

  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found</title>

    <style>
      * {
        box-sizing: border-box;
      }

      html,
      body {
        width: 100%;
        height: 100%;
        margin: 0;
      }

      body {
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #ffffff;
        color: #888888;
        font-family: Arial, "Helvetica Neue", "Hiragino Kaku Gothic ProN",
          "Yu Gothic", Meiryo, sans-serif;
        text-align: center;
      }

      .error-page {
        padding: 20px;
      }

      .error-code {
        margin: 0;
        font-size: clamp(100px, 20vw, 200px);
        font-weight: 700;
        line-height: 1;
        letter-spacing: 0.05em;
      }

      .error-title {
        margin: 24px 0 8px;
        font-size: clamp(24px, 5vw, 40px);
        font-weight: 400;
      }

      .error-message {
        margin: 0;
        font-size: clamp(15px, 3vw, 18px);
        line-height: 1.8;
      }
    </style>
  </head>

  <body>
    <main class="error-page">
      <h1 class="error-code">404</h1>
      <p class="error-title">Not Found.</p>
      <p class="error-message">ページが見つかりませんでした。</p>
    </main>
  </body>

  </html>

<?php
  exit;
}
