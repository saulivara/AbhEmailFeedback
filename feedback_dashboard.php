<?php
// feedback_dashboard.php
// Requires: config.php (PDO $pdo connection to your MySQL database)

declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
require __DIR__ . '/config.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ---------- Inputs (GET) ----------
$allowedLimits = [25, 50, 100, 200];
$limit = (int)($_GET['limit'] ?? 50);
if (!in_array($limit, $allowedLimits, true)) $limit = 50;

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$sortKey = $_GET['sort'] ?? 'created_desc';
$sortMap = [
  'created_desc' => 'created_at DESC',
  'created_asc'  => 'created_at ASC',
  'rating_desc'  => 'rating DESC, created_at DESC',
  'rating_asc'   => 'rating ASC, created_at DESC',
  'pref_asc'     => 'preference ASC, created_at DESC',
  'pref_desc'    => 'preference DESC, created_at DESC',
  'email_asc'    => 'email ASC, created_at DESC',
  'email_desc'   => 'email DESC, created_at DESC',
  'campaign_asc' => 'campaign_uid ASC, created_at DESC',
  'campaign_desc'=> 'campaign_uid DESC, created_at DESC',
  'subject_asc'  => 'subject ASC, created_at DESC',
  'subject_desc' => 'subject DESC, created_at DESC',
];
$orderBy = $sortMap[$sortKey] ?? $sortMap['created_desc'];

$q       = trim((string)($_GET['q'] ?? ''));
$pref    = $_GET['pref'] ?? '';
$rmin    = isset($_GET['rmin']) ? (int)$_GET['rmin'] : null;
$rmax    = isset($_GET['rmax']) ? (int)$_GET['rmax'] : null;
$start   = trim((string)($_GET['start'] ?? '')); // Y-m-d
$end     = trim((string)($_GET['end'] ?? ''));   // Y-m-d

if ($rmin !== null && ($rmin < 1 || $rmin > 5)) $rmin = null;
if ($rmax !== null && ($rmax < 1 || $rmax > 5)) $rmax = null;
if ($rmin !== null && $rmax !== null && $rmin > $rmax) { $t = $rmin; $rmin = $rmax; $rmax = $t; }

$allowedPrefs = ['more','less','stop'];

// ---------- Build WHERE ----------
$conds = [];
$params = [];

if ($q !== '') {
  $conds[] = "(email LIKE :q OR campaign_uid LIKE :q OR subject LIKE :q OR comments LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}
if ($pref && in_array($pref, $allowedPrefs, true)) {
  $conds[] = "preference = :pref";
  $params[':pref'] = $pref;
}
if ($rmin !== null && $rmax !== null) {
  $conds[] = "rating BETWEEN :rmin AND :rmax";
  $params[':rmin'] = $rmin;
  $params[':rmax'] = $rmax;
} elseif ($rmin !== null) {
  $conds[] = "rating >= :rmin";
  $params[':rmin'] = $rmin;
} elseif ($rmax !== null) {
  $conds[] = "rating <= :rmax";
  $params[':rmax'] = $rmax;
}

$startDt = null; $endDt = null;
if ($start) {
  $startDt = DateTime::createFromFormat('Y-m-d', $start);
  if ($startDt) {
    $conds[] = "created_at >= :start";
    $params[':start'] = $startDt->format('Y-m-d 00:00:00');
  }
}
if ($end) {
  $endDt = DateTime::createFromFormat('Y-m-d', $end);
  if ($endDt) {
    $conds[] = "created_at <= :end";
    $params[':end'] = $endDt->format('Y-m-d 23:59:59');
  }
}

$whereSql = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

// ---------- Export CSV ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="email_ratings_' . date('Ymd_His') . '.csv"');

  $sql = "SELECT id, created_at, rating, preference, comments, campaign_uid, subscriber_uid, email, list_uid, subject, page_url, referrer, user_agent, tz, ip_address
          FROM email_ratings
          $whereSql
          ORDER BY $orderBy";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k => $v) $stmt->bindValue($k, $v);
  $stmt->execute();

  $out = fopen('php://output', 'w');
  fputcsv($out, ['id','created_at','rating','preference','comments','campaign_uid','subscriber_uid','email','list_uid','subject','page_url','referrer','user_agent','tz','ip_address']);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, $row);
  }
  fclose($out);
  exit;
}

// ---------- Counts & summary ----------
$sqlCount = "SELECT COUNT(*) AS c FROM email_ratings $whereSql";
$stmt = $pdo->prepare($sqlCount);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$total = (int)$stmt->fetchColumn();

$sqlSummary = "SELECT AVG(rating) AS avg_rating, COUNT(*) AS total FROM email_ratings $whereSql";
$stmt = $pdo->prepare($sqlSummary);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$summary = $stmt->fetch() ?: ['avg_rating'=>null,'total'=>0];
$avg = $summary['avg_rating'] ? round((float)$summary['avg_rating'], 2) : 0.0;

$dist = array_fill(1, 5, 0);
$sqlDist = "SELECT rating, COUNT(*) AS c FROM email_ratings $whereSql GROUP BY rating";
$stmt = $pdo->prepare($sqlDist);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
while ($r = $stmt->fetch()) {
  $dist[(int)$r['rating']] = (int)$r['c'];
}

$prefDist = ['more'=>0,'less'=>0,'stop'=>0];
$sqlPD = "SELECT preference, COUNT(*) AS c FROM email_ratings $whereSql GROUP BY preference";
$stmt = $pdo->prepare($sqlPD);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
while ($r = $stmt->fetch()) {
  if (isset($prefDist[$r['preference']])) $prefDist[$r['preference']] = (int)$r['c'];
}

// ---------- Rows ----------
$sqlRows = "SELECT id, created_at, rating, preference, comments, campaign_uid, subscriber_uid, email, list_uid, subject, tz, ip_address
            FROM email_ratings
            $whereSql
            ORDER BY $orderBy
            LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sqlRows);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$totalPages = max(1, (int)ceil($total / $limit));

function starHtml(int $r): string {
  $s = '';
  for ($i=1;$i<=5;$i++) $s .= '<span class="'.($i <= $r ? 'gold' : 'gray').'">★</span>';
  return $s;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Feedback Dashboard — Alan Walker Health News</title>
  <style>
    :root{
      --brand:#0b66e0; --brand-dark:#0a4fb0; --accent:#f1c40f;
      --text:#0f172a; --muted:#64748b; --bg:#ffffff; --bg2:#f8fafc;
      --border:#e5e7eb; --shell:1100px; --pad:16px; --gap:18px;
      --good:#10b981; --warn:#f59e0b; --bad:#ef4444;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;background:#f6f7f9;color:var(--text)}
    .shell{max-width:var(--shell);margin:0 auto;padding:0 var(--pad)}

    /* Banner (matches content width) */
    .banner{
      margin:var(--gap) 0;
      background: radial-gradient(1400px 320px at 10% -30%, #0e7aff 0, #0a59c8 45%, #083e8e 78%, #062a63 100%);
      border-radius:14px;padding:16px;display:flex;align-items:center;gap:16px;color:#fff;
      box-shadow:0 16px 40px rgba(10,89,200,.18);position:relative;overflow:hidden
    }
    .banner::before,.banner::after{content:"";position:absolute;border-radius:50%;background:radial-gradient(closest-side, rgba(255,255,255,.18), rgba(255,255,255,0));filter:blur(30px)}
    .banner::before{width:360px;height:360px;left:-28%;bottom:-28%}
    .banner::after{width:280px;height:280px;right:-22%;top:-42%;background:radial-gradient(closest-side, rgba(11,102,224,.45), rgba(11,102,224,0))}
    .headshotWrap{z-index:1;display:grid;place-items:center;flex:0 0 auto}
    .ring{--size:76px;width:var(--size);height:var(--size);padding:3px;border-radius:50%;background:conic-gradient(from 220deg, #a9d1ff, #7aaeff, #4c86ff, #a9d1ff);box-shadow:0 10px 24px rgba(0,0,0,.25), 0 2px 6px rgba(0,0,0,.12)}
    .headshot{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;background:#dce6f3;border:2px solid rgba(255,255,255,.65)}
    .brand h1{margin:0;font-size:20px;line-height:1.1;font-weight:800}
    .brand .sub{display:block;font-weight:600;opacity:.95;font-size:12px;letter-spacing:.5px;text-transform:uppercase}

    .row{display:flex;gap:16px;flex-wrap:wrap}
    .card{
      background:var(--bg);border:1px solid var(--border);border-radius:14px;
      box-shadow:0 8px 28px rgba(0,0,0,.06);padding:20px;margin:var(--gap) 0
    }
    .kpis{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:14px}
    .kpi{border:1px dashed #e2e8f0;border-radius:12px;padding:14px;background:var(--bg2)}
    .kpi h3{margin:0 0 6px;font-size:13px;color:#334155;text-transform:uppercase;letter-spacing:.6px}
    .kpi .val{font-size:24px;font-weight:800}
    .stars .gold{color:var(--accent)} .stars .gray{color:#cbd5e1}

    /* Filter form */
    .filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr;gap:10px}
    .filters .actions{display:flex;gap:8px;align-items:end}
    label.small{font-size:12px;color:#475569;margin-bottom:4px;display:block}
    input[type="text"], input[type="date"], select{
      width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;font:inherit
    }
    .btn{
      appearance:none;border:0;border-radius:10px;background:var(--brand);color:#fff;padding:10px 14px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(11,102,224,.28)
    }
    .btn.secondary{background:#0f172a;color:#fff}
    .btn.light{background:#e2e8f0;color:#0f172a}
    .btn.link{background:transparent;color:var(--brand);box-shadow:none;padding:0}

    /* Table */
    .tableWrap{overflow:auto;border:1px solid var(--border);border-radius:14px}
    table{width:100%;border-collapse:separate;border-spacing:0;background:#fff}
    thead th{
      position:sticky;top:0;background:#f8fafc;border-bottom:1px solid var(--border);text-align:left;font-size:12px;color:#334155;padding:10px
    }
    tbody td{border-bottom:1px solid var(--border);padding:10px;vertical-align:top;font-size:14px}
    tbody tr:nth-child(even){background:#fcfdff}
    .tag{display:inline-block;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:700}
    .tag.more{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
    .tag.less{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
    .tag.stop{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
    .nowrap{white-space:nowrap}
    .muted{color:var(--muted)}
    .truncate{max-width:380px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .stars{font-size:16px}

    /* Distribution bars */
    .bars{display:grid;grid-template-columns:120px 1fr 60px;gap:8px;align-items:center}
    .bar{height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden}
    .bar > span{display:block;height:100%;background:linear-gradient(90deg,#60a5fa,#3b82f6);border-radius:999px}

    /* Pagination */
    .pagination{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:12px}
    .pagination .page{padding:8px 12px;border-radius:10px;border:1px solid var(--border);background:#fff;text-decoration:none;color:#0f172a}
    .pagination .active{background:var(--brand);border-color:var(--brand);color:#fff}
    .pagination .disabled{opacity:.5;pointer-events:none}

    /* Modal */
    .modal{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;padding:16px}
    .modal.open{display:flex}
    .modalCard{background:#fff;max-width:720px;width:100%;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.35);overflow:hidden}
    .modalHead{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid var(--border)}
    .modalBody{padding:16px}
    .close{background:transparent;border:0;font-size:18px;cursor:pointer}

    @media (max-width:900px){
      .filters{grid-template-columns:1fr 1fr 1fr;grid-auto-rows:auto}
      .filters .actions{grid-column:1 / -1}
    }
  </style>
</head>
<body>
  <div class="shell">
    <header class="banner" role="banner">
      <div class="headshotWrap" aria-hidden="true">
        <div class="ring">
          <img class="headshot" src="https://advancedbiohealth.com/wp-content/uploads/2025/08/AlanWalker.jpeg" alt="Portrait of Alan Walker" />
        </div>
      </div>
      <div class="brand">
        <h1>Alan Walker <span class="sub">Health News</span></h1>
      </div>
    </header>

    <!-- KPIs -->
    <section class="card">
      <div class="kpis">
        <div class="kpi">
          <h3>Responses</h3>
          <div class="val"><?= number_format($summary['total'] ?? 0) ?></div>
        </div>
        <div class="kpi">
          <h3>Average Rating</h3>
          <div class="val"><span class="stars"><?= starHtml((int)round($avg)) ?></span> <?= number_format($avg, 2) ?>/5</div>
        </div>
        <div class="kpi">
          <h3>Preference Mix</h3>
          <div class="val" style="font-size:14px;font-weight:700">
            <span class="tag more">More: <?= $prefDist['more'] ?></span>
            <span class="tag less">Less: <?= $prefDist['less'] ?></span>
            <span class="tag stop">Stop: <?= $prefDist['stop'] ?></span>
          </div>
        </div>
      </div>

      <!-- Rating distribution bars -->
      <div style="margin-top:14px">
        <?php
          $totalForBars = array_sum($dist);
          for ($r=5; $r>=1; $r--) {
            $c = $dist[$r];
            $pct = $totalForBars ? round($c * 100 / $totalForBars, 1) : 0;
            echo '<div class="bars" title="'.h("$c responses").'">
                    <div class="stars">'.starHtml($r).'</div>
                    <div class="bar"><span style="width:'.$pct.'%"></span></div>
                    <div class="nowrap muted">'.$pct.'%</div>
                  </div>';
          }
        ?>
      </div>
    </section>

    <!-- Filters -->
    <section class="card">
      <form class="filters" method="get">
        <div>
          <label class="small" for="q">Search</label>
          <input type="text" id="q" name="q" placeholder="Email, campaign UID, subject, or comment" value="<?= h($q) ?>">
        </div>
        <div>
          <label class="small" for="start">Start date</label>
          <input type="date" id="start" name="start" value="<?= h($start) ?>">
        </div>
        <div>
          <label class="small" for="end">End date</label>
          <input type="date" id="end" name="end" value="<?= h($end) ?>">
        </div>
        <div>
          <label class="small">Rating range</label>
          <div class="row" style="gap:8px">
            <select name="rmin" aria-label="Min rating">
              <option value="">Min</option>
              <?php for($i=1;$i<=5;$i++): ?>
                <option value="<?= $i ?>" <?= ($rmin===$i?'selected':'') ?>><?= $i ?></option>
              <?php endfor; ?>
            </select>
            <select name="rmax" aria-label="Max rating">
              <option value="">Max</option>
              <?php for($i=1;$i<=5;$i++): ?>
                <option value="<?= $i ?>" <?= ($rmax===$i?'selected':'') ?>><?= $i ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <div>
          <label class="small" for="pref">Preference</label>
          <select id="pref" name="pref">
            <option value="">Any</option>
            <option value="more" <?= $pref==='more'?'selected':'' ?>>More</option>
            <option value="less" <?= $pref==='less'?'selected':'' ?>>Less</option>
            <option value="stop" <?= $pref==='stop'?'selected':'' ?>>Stop</option>
          </select>
        </div>

        <div>
          <label class="small" for="sort">Sort</label>
          <select id="sort" name="sort">
            <option value="created_desc" <?= $sortKey==='created_desc'?'selected':'' ?>>Newest first</option>
            <option value="created_asc"  <?= $sortKey==='created_asc'?'selected':''  ?>>Oldest first</option>
            <option value="rating_desc"  <?= $sortKey==='rating_desc'?'selected':''  ?>>Rating high→low</option>
            <option value="rating_asc"   <?= $sortKey==='rating_asc'?'selected':''   ?>>Rating low→high</option>
            <option value="pref_asc"     <?= $sortKey==='pref_asc'?'selected':''     ?>>Preference A→Z</option>
            <option value="pref_desc"    <?= $sortKey==='pref_desc'?'selected':''    ?>>Preference Z→A</option>
            <option value="email_asc"    <?= $sortKey==='email_asc'?'selected':''    ?>>Email A→Z</option>
            <option value="email_desc"   <?= $sortKey==='email_desc'?'selected':''   ?>>Email Z→A</option>
            <option value="campaign_asc" <?= $sortKey==='campaign_asc'?'selected':'' ?>>Campaign A→Z</option>
            <option value="campaign_desc"<?= $sortKey==='campaign_desc'?'selected':''?>>Campaign Z→A</option>
            <option value="subject_asc"  <?= $sortKey==='subject_asc'?'selected':''  ?>>Subject A→Z</option>
            <option value="subject_desc" <?= $sortKey==='subject_desc'?'selected':'' ?>>Subject Z→A</option>
          </select>
        </div>
        <div>
          <label class="small" for="limit">Page size</label>
          <select id="limit" name="limit">
            <?php foreach ($allowedLimits as $l): ?>
              <option value="<?= $l ?>" <?= $l===$limit?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="actions">
          <button class="btn" type="submit">Apply</button>
          <a class="btn light" href="?">Reset</a>
          <button class="btn secondary" type="submit" name="export" value="csv">Export CSV</button>
        </div>
      </form>
    </section>

    <!-- Results table -->
    <section class="card">
      <div class="tableWrap">
        <table>
          <thead>
            <tr>
              <th>When</th>
              <th>Rating</th>
              <th>Preference</th>
              <th>Subject</th>
              <th>Email</th>
              <th>Campaign</th>
              <th>Subscriber</th>
              <th>Comments</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="muted" style="text-align:center;padding:24px">No results for the selected filters.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="nowrap"><?= h($r['created_at']) ?><?= $r['tz']?' <span class="muted">('.h($r['tz']).')</span>':'' ?></td>
                <td><div class="stars"><?= starHtml((int)$r['rating']) ?></div></td>
                <td><span class="tag <?= h($r['preference']) ?>"><?= h(ucfirst($r['preference'])) ?></span></td>
                <td class="truncate" title="<?= h($r['subject'] ?? '') ?>"><?= h($r['subject'] ?? '') ?></td>
                <td class="truncate" title="<?= h($r['email'] ?? '') ?>"><a href="mailto:<?= h($r['email'] ?? '') ?>"><?= h($r['email'] ?? '') ?></a></td>
                <td class="truncate" title="<?= h($r['campaign_uid'] ?? '') ?>"><?= h($r['campaign_uid'] ?? '') ?></td>
                <td class="truncate" title="<?= h($r['subscriber_uid'] ?? '') ?>"><?= h($r['subscriber_uid'] ?? '') ?></td>
                <td class="truncate" title="<?= h($r['comments'] ?? '') ?>">
                  <?php
                    $snippet = mb_strimwidth((string)($r['comments'] ?? ''), 0, 80, (strlen((string)($r['comments'] ?? ''))>80?'…':''));
                    echo h($snippet);
                  ?>
                  <?php if (!empty($r['comments'])): ?>
                    <button class="btn link" data-modal='<?= h(json_encode($r, JSON_UNESCAPED_SLASHES)) ?>'>View</button>
                  <?php endif; ?>
                </td>
                <td class="nowrap muted"><?= h($r['ip_address'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="pagination">
        <?php
          // Build base query string without 'page'
          $qs = $_GET; unset($qs['page']);
          $base = '?' . http_build_query($qs);
          $prev = max(1, $page-1);
          $next = min($totalPages, $page+1);
        ?>
        <a class="page <?= $page<=1?'disabled':'' ?>" href="<?= $base.'&page='.$prev ?>">Prev</a>
        <?php
          // Simple pager: show first, last, current +/- 2
          $window = 2;
          $shown = [];
          for ($i=1;$i<=$totalPages;$i++){
            if ($i===1 || $i===$totalPages || abs($i-$page) <= $window){
              $shown[] = $i;
            }
          }
          $lastPrinted = 0;
          foreach ($shown as $i) {
            if ($i > $lastPrinted + 1) echo '<span class="muted">…</span>';
            echo '<a class="page '.($i===$page?'active':'').'" href="'.$base.'&page='.$i.'">'.$i.'</a>';
            $lastPrinted = $i;
          }
        ?>
        <a class="page <?= $page>=$totalPages?'disabled':'' ?>" href="<?= $base.'&page='.$next ?>">Next</a>
      </div>
    </section>

    <footer style="margin:var(--gap) 0 40px;color:#6b7280;font-size:12px;border-top:1px solid var(--border);padding-top:16px">
      <p>© Alan Walker Health News — Internal dashboard. Protect this page behind authentication.</p>
    </footer>
  </div>

  <!-- Modal -->
  <div class="modal" id="modal">
    <div class="modalCard">
      <div class="modalHead">
        <strong>Feedback details</strong>
        <button class="close" id="closeBtn" aria-label="Close">✕</button>
      </div>
      <div class="modalBody" id="modalBody">
        <!-- filled by JS -->
      </div>
    </div>
  </div>

  <script>
    // Open modal with full details
    const modal = document.getElementById('modal');
    const modalBody = document.getElementById('modalBody');
    const closeBtn = document.getElementById('closeBtn');

    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-modal]');
      if (!btn) return;
      const data = JSON.parse(btn.getAttribute('data-modal'));
      modalBody.innerHTML = `
        <div style="display:grid;grid-template-columns:150px 1fr;gap:8px">
          <div class="muted">When</div><div>${escapeHtml(data.created_at)} ${data.tz?`<span class="muted">(${escapeHtml(data.tz)})</span>`:''}</div>
          <div class="muted">Rating</div><div>${renderStars(+data.rating)}</div>
          <div class="muted">Preference</div><div><span class="tag ${escapeHtml(data.preference)}">${escapeHtml(capitalize(data.preference))}</span></div>
          <div class="muted">Subject</div><div>${escapeHtml(data.subject || '')}</div>
          <div class="muted">Email</div><div>${escapeHtml(data.email || '')}</div>
          <div class="muted">Campaign UID</div><div>${escapeHtml(data.campaign_uid || '')}</div>
          <div class="muted">Subscriber UID</div><div>${escapeHtml(data.subscriber_uid || '')}</div>
          <div class="muted">Comments</div><div style="white-space:pre-wrap">${escapeHtml(data.comments || '')}</div>
          <div class="muted">IP</div><div>${escapeHtml(data.ip_address || '')}</div>
        </div>`;
      modal.classList.add('open');
    });

    closeBtn.addEventListener('click', () => modal.classList.remove('open'));
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('open'); });

    function renderStars(r){
      let s = '';
      for (let i=1;i<=5;i++) s += `<span class="${i<=r?'gold':'gray'}">★</span>`;
      return `<span class="stars">${s}</span>`;
    }
    function capitalize(s){ return (s||'').charAt(0).toUpperCase() + (s||'').slice(1); }
    function escapeHtml(str){
      return (str ?? '').toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
  </script>
</body>
</html>