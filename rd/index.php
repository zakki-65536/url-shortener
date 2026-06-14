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
  exit('Not Found');
}

if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $code)) {
  http_response_code(404);
  exit('Not Found');
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
  exit('Not Found');
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
