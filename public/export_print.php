<?php
// public/export_print.php
declare(strict_types=1);
session_start();

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../lib/helpers.php";

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
}

$exportId = get_int("export_id");
if ($exportId <= 0) {
  echo "export_id invalid";
  exit;
}

$st = $pdo->prepare("SELECT * FROM content_exports WHERE id=?");
$st->execute([$exportId]);
$ex = $st->fetch();
if (!$ex) {
  echo "export not found";
  exit;
}

$view = (string)($ex["view_mode"] ?? "");
if ($view !== "content" && $view !== "summary") $view = "content";

if (isset($_GET["mark_printed"]) && $_GET["mark_printed"] === "1") {
  try {
    $st = $pdo->prepare("UPDATE content_exports SET printed_at = NOW() WHERE id=?");
    $st->execute([$exportId]);
  } catch (Throwable $e) {
  }
  redirect("export_print.php?export_id=" . $exportId);
}

/*
REMARK
- Add search + sort like export_pdf.php
- Keep legacy default order by ei.sort_no ASC, ei.id ASC
- Sorting applies after legacy order, so old logic still drives stable ordering
*/
$q = trim((string)($_GET["q"] ?? ""));
$sort = (string)($_GET["sort"] ?? "export_order");
$dir = strtolower((string)($_GET["dir"] ?? "asc"));

$allowedSort = [
  // legacy default
  "export_order" => "ei.sort_no",

  // extras similar to export_pdf.php
  "name" => "c.file_path",
  "last_updated" => "c.updated_at",
  "app" => "a.app_name",
  "module" => "m.module_name",
];

$orderBy = $allowedSort[$sort] ?? "ei.sort_no";
$orderDir = $dir === "desc" ? "DESC" : "ASC";

/*
REMARK
- If sort=export_order keep legacy strictly
- Otherwise, apply user sort first
  then keep legacy order as fallback for stable results
*/
if ($sort === "export_order") {
  $orderSql = "ei.sort_no ASC, ei.id ASC";
} else {
  $orderSql = "{$orderBy} {$orderDir}, ei.sort_no ASC, ei.id ASC, c.id DESC";
}

/*
REMARK
- Search supports file_path, title, app_name, module_name
- Optional also searches body fields to help print filtering
*/
$where = ["ei.export_id = ?"];
$params = [$exportId];

if ($q !== "") {
  $where[] = "(
    c.file_path LIKE ?
    OR c.title LIKE ?
    OR a.app_name LIKE ?
    OR m.module_name LIKE ?
    OR c.content LIKE ?
    OR c.summarycode LIKE ?
  )";
  $like = "%" . $q . "%";
  array_push($params, $like, $like, $like, $like, $like, $like);
}

$sql = "
  SELECT ei.sort_no, c.*, a.app_name, m.module_name
  FROM content_export_items ei
  JOIN contentcode c ON c.id = ei.content_id
  JOIN appsname a ON a.id = c.app_id
  JOIN moduleapps m ON m.id = c.module_id
  WHERE " . implode(" AND ", $where) . "
  ORDER BY {$orderSql}
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

function pick_body(array $r, string $view): string
{
  if ($view === "summary") return (string)($r["summarycode"] ?? "");
  return (string)($r["content"] ?? "");
}

function render_bold(string $text): string
{
  if ($text === "") return "";
  $safe = h($text);
  return (string)preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);
}

function toggle_dir(string $currentDir): string
{
  return strtolower($currentDir) === "asc" ? "desc" : "asc";
}

$title = "Export";
$title .= " | " . (string)($ex["file_name"] ?? "");
$title .= $view === "summary" ? " | View: Summary" : " | View: Content";

$qsBase =
  "export_id=" . (int)$exportId .
  "&q=" . urlencode($q) .
  "&sort=" . urlencode($sort) .
  "&dir=" . urlencode($dir);

$dirNext = toggle_dir($dir);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= h($title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:Arial;margin:20px}
    .toolbar{position:sticky;top:0;background:#fff;border-bottom:1px solid #ddd;padding:10px;margin:-20px -20px 20px -20px;z-index:10}
    .toolbar a,.toolbar button{margin-right:8px}
    .meta{color:#444;font-size:12px;margin-top:6px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .item{page-break-inside:avoid;border:1px solid #ddd;padding:12px;margin:12px 0}
    .hdr{font-weight:bold;margin-bottom:8px}
    pre{white-space:pre-wrap;margin:0;font-family:Courier,monospace;font-size:12px}
    .badge{display:inline-block;padding:2px 8px;border:1px solid #bbb;border-radius:10px;font-size:12px}
    .idx{font-weight:bold;margin-right:8px}
    input[type=text],select{padding:6px 8px;border:1px solid #bbb;border-radius:8px}
    .btn{padding:6px 10px;border:1px solid #bbb;border-radius:8px;background:#f7f7f7;cursor:pointer}
    .btn:hover{background:#eee}
    @media print{
      .toolbar{display:none}
      body{margin:0}
      .item{border:none;border-top:1px solid #ddd}
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <div class="row">
      <a href="exports.php">Back</a>
      <a href="exports.php?action=history">History</a>

      <button class="btn" onclick="window.print()">Print</button>
      <a class="btn" href="export_print.php?export_id=<?= (int)$exportId ?>&mark_printed=1">Mark printed</a>
    </div>

    <form method="get" class="row" style="margin-top:10px">
      <input type="hidden" name="export_id" value="<?= (int)$exportId ?>">

      <input
        type="text"
        name="q"
        value="<?= h($q) ?>"
        placeholder="Search file, title, app, module, content"
        style="min-width:320px"
      >

      <select name="sort">
        <option value="export_order" <?= $sort === "export_order" ? "selected" : "" ?>>Sort export order</option>
        <option value="name" <?= $sort === "name" ? "selected" : "" ?>>Sort name</option>
        <option value="last_updated" <?= $sort === "last_updated" ? "selected" : "" ?>>Sort last updated</option>
        <option value="app" <?= $sort === "app" ? "selected" : "" ?>>Sort app</option>
        <option value="module" <?= $sort === "module" ? "selected" : "" ?>>Sort module</option>
      </select>

      <select name="dir">
        <option value="asc" <?= $dir === "asc" ? "selected" : "" ?>>ASC</option>
        <option value="desc" <?= $dir === "desc" ? "selected" : "" ?>>DESC</option>
      </select>

      <button class="btn" type="submit">Apply</button>
      <a class="btn" href="?export_id=<?= (int)$exportId ?>">Reset</a>

      <a class="btn" href="?<?= h($qsBase . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dirNext)) ?>">Toggle dir</a>
    </form>

    <div class="meta">Ctrl P. Save as PDF. Nama file: <?= h((string)($ex["file_name"] ?? "")) ?></div>
  </div>

  <h2><?= h($title) ?></h2>

  <div class="meta">Export ID: <span class="badge"><?= (int)$exportId ?></span></div>
  <div class="meta">View: <span class="badge"><?= h($view) ?></span></div>
  <div class="meta">Search: <span class="badge"><?= h($q !== "" ? $q : "-") ?></span></div>
  <div class="meta">Sort: <span class="badge"><?= h($sort) ?></span> <span class="badge"><?= h($orderDir) ?></span></div>
  <div class="meta">Total: <?= (int)count($rows) ?> items</div>

  <?php $no = 1; foreach ($rows as $r): ?>
    <div class="item">
      <div class="hdr">
        <span class="idx"><?= (int)$no ?>.</span>
        App: <?= h((string)$r["app_name"]) ?> | File: <?= h((string)$r["file_path"]) ?>
        <?php if (!empty($r["updated_at"])): ?>
          | Updated: <?= h((string)$r["updated_at"]) ?>
        <?php endif; ?>
      </div>

      <div class="meta">
        <span class="idx">module_name: <?= h((string)$r["module_name"]) ?></span>
        <?php if (!empty($r["title"])): ?>
          <span class="badge">title: <?= h((string)$r["title"]) ?></span>
        <?php endif; ?>
      </div>

      <div style="margin-top:10px">
        <pre><?= render_bold(pick_body($r, $view)) ?></pre>
      </div>
    </div>
  <?php $no++; endforeach; ?>
</body>
</html>

