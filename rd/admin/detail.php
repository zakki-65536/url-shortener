<?php

declare(strict_types=1);

require_once '../config.php';
require_once __DIR__ . '/session.php';

requireLogin();
$csrfToken = getCsrfToken();

const APP_TIMEZONE = 'Asia/Tokyo';

// HTMLエスケープ
function detailEscape(mixed $value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// SQL識別子を安全にバッククォートで囲む
function quoteIdentifier(string $identifier): string
{
  if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
    throw new InvalidArgumentException('Invalid SQL identifier.');
  }

  return '`' . $identifier . '`';
}

// 棒グラフの高さ・幅を0〜100%で返す
function chartPercent(int $value, int $maximum): string
{
  if ($value <= 0 || $maximum <= 0) {
    return '0';
  }

  // 少数件でも棒が視認できるよう、0件以外は最低3%にします。
  $percent = max(3, min(100, ($value / $maximum) * 100));

  return number_format($percent, 2, '.', '');
}

// エラー画面
function renderErrorPage(int $statusCode, string $title, string $message): never
{
  http_response_code($statusCode);
?>
  <!DOCTYPE html>
  <html lang="ja">

  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= detailEscape($title) ?></title>
    <link rel="stylesheet" href="detail.css">
  </head>

  <body>
    <main class="error-page">
      <section class="panel error-panel">
        <p class="eyebrow">URL SHORTENER</p>
        <h1><?= detailEscape($title) ?></h1>
        <p><?= detailEscape($message) ?></p>
        <a class="button button--primary" href="./">一覧へ戻る</a>
      </section>
    </main>
  </body>

  </html>
<?php
  exit;
}

$linkId = filter_input(
  INPUT_GET,
  'id',
  FILTER_VALIDATE_INT,
  ['options' => ['min_range' => 1]]
);

if (!is_int($linkId)) {
  renderErrorPage(400, 'URLを確認できません', '正しいURL IDを指定してください。');
}

$pdo = db();

$linkStatement = $pdo->prepare(
  'SELECT
        id,
        code,
        destination_url,
        place,
        target,
        is_active,
        expires_at,
        created_at
     FROM short_links
     WHERE id = :id
     LIMIT 1'
);
$linkStatement->execute([':id' => $linkId]);
$link = $linkStatement->fetch(PDO::FETCH_ASSOC);

if (!$link) {
  renderErrorPage(404, 'URLが見つかりません', '指定された短縮URLは存在しないか、削除されています。');
}

$timezone = new DateTimeZone(APP_TIMEZONE);
$now = new DateTimeImmutable('now', $timezone);

$start24Hours = $now->sub(new DateInterval('PT24H'));
$start7Days = $now->sub(new DateInterval('P7D'));
$start3Days = $now->sub(new DateInterval('P3D'));

// 今日を含む過去30日分。
$start30Days = $now->setTime(0, 0)->sub(new DateInterval('P29D'));
$endTomorrow = $now->setTime(0, 0)->add(new DateInterval('P1D'));

// 現在の時間帯を含む直近168時間（7日間）。
$currentHour = $now->setTime((int) $now->format('H'), 0, 0);
$start168Hours = $currentHour->sub(new DateInterval('PT167H'));
$endNextHour = $currentHour->add(new DateInterval('PT1H'));

$table = "access_logs";
$linkColumn = "short_link_id";
$dateColumn = "accessed_at";
$visitorColumn = "visitor_hash";
$referrerColumn = "referrer_host";

$statistics = [
  'total_access' => 0,
  'last_24_hours' => 0,
  'unique_all' => 0,
  'unique_7_days' => 0,
  'unique_3_days' => 0,
];

$dailyAccess = [];
for ($i = 0; $i < 30; $i++) {
  $date = $start30Days->add(new DateInterval('P' . $i . 'D'));
  $dailyAccess[$date->format('Y-m-d')] = 0;
}

$hourlyAccess = [];
for ($i = 0; $i < 168; $i++) {
  $hour = $start168Hours->add(new DateInterval('PT' . $i . 'H'));
  $hourlyAccess[$hour->format('Y-m-d H:00:00')] = 0;
}

$referrerDomains = [];
$analyticsError = null;

try {
  $summaryStatement = $pdo->prepare(
    "SELECT
            COUNT(*) AS total_access,
            COALESCE(SUM(CASE WHEN {$dateColumn} >= :start_24_hours THEN 1 ELSE 0 END), 0)
                AS last_24_hours,
            COUNT(DISTINCT {$visitorColumn}) AS unique_all,
            COUNT(DISTINCT CASE
                WHEN {$dateColumn} >= :start_7_days THEN {$visitorColumn}
                ELSE NULL
            END) AS unique_7_days,
            COUNT(DISTINCT CASE
                WHEN {$dateColumn} >= :start_3_days THEN {$visitorColumn}
                ELSE NULL
            END) AS unique_3_days
         FROM {$table}
         WHERE {$linkColumn} = :link_id"
  );
  $summaryStatement->execute([
    ':start_24_hours' => $start24Hours->format('Y-m-d H:i:s'),
    ':start_7_days' => $start7Days->format('Y-m-d H:i:s'),
    ':start_3_days' => $start3Days->format('Y-m-d H:i:s'),
    ':link_id' => $linkId,
  ]);

  $summaryRow = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: [];
  foreach (array_keys($statistics) as $key) {
    $statistics[$key] = (int) ($summaryRow[$key] ?? 0);
  }

  $dailyStatement = $pdo->prepare(
    "SELECT
            DATE({$dateColumn}) AS access_date,
            COUNT(*) AS access_count
         FROM {$table}
         WHERE {$linkColumn} = :link_id
           AND {$dateColumn} >= :start_at
           AND {$dateColumn} < :end_at
         GROUP BY DATE({$dateColumn})
         ORDER BY access_date ASC"
  );
  $dailyStatement->execute([
    ':link_id' => $linkId,
    ':start_at' => $start30Days->format('Y-m-d H:i:s'),
    ':end_at' => $endTomorrow->format('Y-m-d H:i:s'),
  ]);

  foreach ($dailyStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (string) $row['access_date'];
    if (array_key_exists($key, $dailyAccess)) {
      $dailyAccess[$key] = (int) $row['access_count'];
    }
  }

  $hourlyStatement = $pdo->prepare(
    "SELECT
            DATE_FORMAT({$dateColumn}, '%Y-%m-%d %H:00:00') AS access_hour,
            COUNT(*) AS access_count
         FROM {$table}
         WHERE {$linkColumn} = :link_id
           AND {$dateColumn} >= :start_at
           AND {$dateColumn} < :end_at
         GROUP BY DATE_FORMAT({$dateColumn}, '%Y-%m-%d %H:00:00')
         ORDER BY access_hour ASC"
  );
  $hourlyStatement->execute([
    ':link_id' => $linkId,
    ':start_at' => $start168Hours->format('Y-m-d H:i:s'),
    ':end_at' => $endNextHour->format('Y-m-d H:i:s'),
  ]);

  foreach ($hourlyStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (string) $row['access_hour'];
    if (array_key_exists($key, $hourlyAccess)) {
      $hourlyAccess[$key] = (int) $row['access_count'];
    }
  }

  // URLからホスト名部分を取り出して集計
  $hostExpression =
    "LOWER(SUBSTRING_INDEX(" .
    "SUBSTRING_INDEX(" .
    "SUBSTRING_INDEX(TRIM({$referrerColumn}), '://', -1), '/', 1" .
    "), ':', 1))";

  $referrerStatement = $pdo->prepare(
    "SELECT
            CASE
                WHEN {$referrerColumn} IS NULL OR TRIM({$referrerColumn}) = ''
                    THEN '直接アクセス'
                WHEN {$hostExpression} = ''
                    THEN '直接アクセス'
                ELSE {$hostExpression}
            END AS referrer_domain,
            COUNT(*) AS access_count
         FROM {$table}
         WHERE {$linkColumn} = :link_id
         GROUP BY referrer_domain
         ORDER BY access_count DESC
         LIMIT 20"
  );
  $referrerStatement->execute([':link_id' => $linkId]);
  $referrerDomains = $referrerStatement->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
  error_log($exception->__toString());
  $analyticsError =
    'アクセス解析データを取得できませんでした。' .
    'detail.php冒頭のアクセスログ用テーブル名・列名を確認してください。';
}

$shortUrl = rtrim((string) PUBLIC_BASE_URL, '/') . '/' . rawurlencode((string) $link['code']);
$title = trim((string) $link['target'] . "【" . (string) $link['place'] . "】");
$pageTitle = $title !== '【】' ? $title : (string) $link['code'];
$createdAt = new DateTimeImmutable((string) $link['created_at'], $timezone);

$dailyMaximum = max(1, ...array_values($dailyAccess));
$hourlyMaximum = max(1, ...array_values($hourlyAccess));
$totalAccess = (int) $statistics['total_access'];
?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= detailEscape($pageTitle) ?> | アクセス解析</title>
  <link rel="stylesheet" href="detail.css">
</head>

<body>
  <div class="page-container">
    <header class="page-header">
      <div>
        <a class="back-link" href="./">
          <span aria-hidden="true">←</span>
          登録済みURLへ戻る
        </a>
        <p class="eyebrow">URL ANALYTICS</p>
        <h1><?= detailEscape($pageTitle) ?></h1>
        <p class="page-description">
          <?= detailEscape($createdAt->format('Y/m/d H:i')) ?> 登録
        </p>
      </div>

      <span class="status-badge <?= $link['is_active'] ? 'status-badge--active' : 'status-badge--inactive' ?>">
        <span class="status-badge__dot" aria-hidden="true"></span>
        <?= $link['is_active'] ? '有効' : '停止中' ?>
      </span>
    </header>

    <main class="content-stack">
      <?php if ($analyticsError !== null): ?>
        <div class="notice notice--error" role="alert">
          <?= detailEscape($analyticsError) ?>
        </div>
      <?php endif; ?>

      <section class="panel link-overview" aria-labelledby="linkOverviewTitle">
        <div class="section-heading">
          <div>
            <p class="eyebrow">LINK INFORMATION</p>
            <h2 id="linkOverviewTitle">URL情報</h2>
          </div>
        </div>

        <dl class="url-information">
          <div class="url-information__item">
            <dt>短縮URL</dt>
            <dd>
              <div class="copy-row">
                <span class="copy-value" title="<?= detailEscape($shortUrl) ?>">
                  <?= detailEscape($shortUrl) ?>
                </span>

                <button
                  class="copy-button"
                  type="button"
                  aria-label="短縮URLをコピー">
                  <span class="copy-button__label">コピー</span>
                </button>
              </div>
            </dd>
          </div>

          <div class="url-information__item">
            <dt>転送先</dt>
            <dd>
              <div class="copy-row">
                <a class="detail-url-target" href="<?= detailEscape($link['destination_url']) ?>">
                  <?= detailEscape($link['destination_url']) ?>
                </a>
              </div>
            </dd>
          </div>
        </dl>
      </section>

      <section aria-labelledby="summaryTitle">
        <div class="section-heading section-heading--outside">
          <div>
            <p class="eyebrow">SUMMARY</p>
            <h2 id="summaryTitle">アクセス概要</h2>
          </div>
        </div>

        <div class="metric-grid">
          <article class="metric-card metric-card--primary">
            <p class="metric-card__label">合計アクセス</p>
            <p class="metric-card__value">
              <?= number_format($statistics['total_access']) ?>
              <span>回</span>
            </p>
            <p class="metric-card__note">全期間</p>
          </article>

          <article class="metric-card">
            <p class="metric-card__label">直近24時間</p>
            <p class="metric-card__value">
              <?= number_format($statistics['last_24_hours']) ?>
              <span>回</span>
            </p>
            <p class="metric-card__note">現在時刻から24時間前まで</p>
          </article>

          <article class="metric-card">
            <p class="metric-card__label">推定ユニーク数</p>
            <p class="metric-card__value">
              <?= number_format($statistics['unique_all']) ?>
              <span>人</span>
            </p>
            <p class="metric-card__note">全期間</p>
          </article>

          <article class="metric-card">
            <p class="metric-card__label">推定ユニーク数</p>
            <p class="metric-card__value">
              <?= number_format($statistics['unique_7_days']) ?>
              <span>人</span>
            </p>
            <p class="metric-card__note">直近1週間</p>
          </article>

          <article class="metric-card">
            <p class="metric-card__label">推定ユニーク数</p>
            <p class="metric-card__value">
              <?= number_format($statistics['unique_3_days']) ?>
              <span>人</span>
            </p>
            <p class="metric-card__note">直近3日</p>
          </article>
        </div>
      </section>

      <section class="panel" aria-labelledby="dailyAccessTitle">
        <div class="section-heading">
          <div>
            <p class="eyebrow">DAILY ACCESS</p>
            <h2 id="dailyAccessTitle">過去30日の日別アクセス</h2>
            <p>今日を含む30日間のアクセス数です。</p>
          </div>
        </div>

        <div class="chart-scroll" tabindex="0" aria-label="過去30日の日別アクセスグラフ">
          <div class="bar-chart bar-chart--daily">
            <?php $dailyIndex = 0; ?>
            <?php foreach ($dailyAccess as $dateKey => $count): ?>
              <?php
              $date = new DateTimeImmutable($dateKey, $timezone);
              $showLabel = $dailyIndex % 3 === 0 || $dailyIndex === 29;
              ?>
              <div
                class="bar-chart__item"
                title="<?= detailEscape($date->format('Y/m/d')) ?>: <?= number_format($count) ?>回"
                aria-label="<?= detailEscape($date->format('Y年m月d日')) ?> <?= number_format($count) ?>回">
                <span class="bar-chart__value"><?= number_format($count) ?></span>
                <span
                  class="bar-chart__bar"
                  style="--bar-size: <?= chartPercent($count, $dailyMaximum) ?>%;"
                  aria-hidden="true"></span>
                <time
                  class="bar-chart__label <?= $showLabel ? '' : 'bar-chart__label--hidden' ?>"
                  datetime="<?= detailEscape($dateKey) ?>">
                  <?= detailEscape($date->format('m/d')) ?>
                </time>
              </div>
              <?php $dailyIndex++; ?>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="panel" aria-labelledby="hourlyAccessTitle">
        <div class="section-heading">
          <div>
            <p class="eyebrow">HOURLY ACCESS</p>
            <h2 id="hourlyAccessTitle">直近1週間の1時間ごとのアクセス</h2>
            <p>現在の時間帯を含む直近168時間です。横にスクロールして確認できます。</p>
          </div>
        </div>

        <div class="chart-scroll" tabindex="0" aria-label="直近1週間の1時間ごとのアクセスグラフ">
          <div class="bar-chart bar-chart--hourly">
            <?php $hourlyIndex = 0; ?>
            <?php foreach ($hourlyAccess as $hourKey => $count): ?>
              <?php
              $hour = new DateTimeImmutable($hourKey, $timezone);
              $showLabel = $hourlyIndex === 0 || $hour->format('H') === '00';
              ?>
              <div
                class="bar-chart__item"
                title="<?= detailEscape($hour->format('Y/m/d H:00')) ?>: <?= number_format($count) ?>回"
                aria-label="<?= detailEscape($hour->format('Y年m月d日 H時')) ?> <?= number_format($count) ?>回">
                <span class="bar-chart__value bar-chart__value--compact">
                  <?= $count > 0 ? number_format($count) : '' ?>
                </span>
                <span
                  class="bar-chart__bar"
                  style="--bar-size: <?= chartPercent($count, $hourlyMaximum) ?>%;"
                  aria-hidden="true"></span>
                <time
                  class="bar-chart__label bar-chart__label--hour <?= $showLabel ? '' : 'bar-chart__label--hidden' ?>"
                  datetime="<?= detailEscape($hour->format(DateTimeInterface::ATOM)) ?>">
                  <?= detailEscape($hour->format('m/d H時')) ?>
                </time>
              </div>
              <?php $hourlyIndex++; ?>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="panel" aria-labelledby="referrerTitle">
        <div class="section-heading">
          <div>
            <p class="eyebrow">REFERRERS</p>
            <h2 id="referrerTitle">参照元ドメイン</h2>
            <p>全期間のアクセスを参照元ドメイン別に集計しています。上位20件を表示します。</p>
          </div>
        </div>

        <?php if (!$referrerDomains): ?>
          <p class="empty-state">参照元データはまだありません。</p>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="referrer-table">
              <thead>
                <tr>
                  <th scope="col">参照元ドメイン</th>
                  <th scope="col">アクセス数</th>
                  <th scope="col">割合</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($referrerDomains as $referrer): ?>
                  <?php
                  $referrerCount = (int) $referrer['access_count'];
                  $share = $totalAccess > 0 ? ($referrerCount / $totalAccess) * 100 : 0;
                  ?>
                  <tr>
                    <td data-label="参照元ドメイン">
                      <strong><?= detailEscape($referrer['referrer_domain']) ?></strong>
                    </td>
                    <td data-label="アクセス数">
                      <?= number_format($referrerCount) ?>回
                    </td>
                    <td data-label="割合">
                      <div class="share-cell">
                        <div class="share-track" aria-hidden="true">
                          <span style="--share-size: <?= number_format($share, 2, '.', '') ?>%;"></span>
                        </div>
                        <span><?= number_format($share, 1) ?>%</span>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </main>

    <footer>
      <div style="text-align: center; margin-top: 48px;">
        <form action="logout.php" method="POST">
          <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <button class="button button--secondary" type="submit">
            ログアウト
          </button>
        </form>
        <span style="display: block; margin-top: 16px;">&copy; 2026 okzks</span>
      </div>
    </footer>
  </div>

</body>
<script src="index.js"></script>

</html>