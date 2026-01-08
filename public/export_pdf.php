<?php
// public/export_pdf.php
declare(strict_types=1);
session_start();
require __DIR__ . "/../config/db.php";
require __DIR__ . "/../lib/helpers.php";

$app_id = get_int("app_id");
$module_id = get_int("module_id");

$view = isset($_GET["view"]) ? (string)$_GET["view"] : "content";
$view = strtolower(trim($view));
if (!in_array($view, ["content", "summary"], true)) $view = "content";

$q = trim((string)($_GET["q"] ?? ""));
$sort = (string)($_GET["sort"] ?? "name");
$dir = strtolower((string)($_GET["dir"] ?? "asc"));

$allowedSort = [
  "name" => "c.file_path",
  "last_updated" => "c.updated_at",
];

$orderBy = $allowedSort[$sort] ?? "c.file_path";
$orderDir = $dir === "desc" ? "DESC" : "ASC";

$apps = $pdo->query("SELECT id, app_name FROM appsname ORDER BY app_name ASC")->fetchAll();
$modules = [];
if ($app_id > 0) {
  $st = $pdo->prepare("SELECT id, module_name FROM moduleapps WHERE app_id=? ORDER BY module_name ASC");
  $st->execute([$app_id]);
  $modules = $st->fetchAll();
}

$where = [];
$params = [];

if ($app_id > 0) { $where[] = "c.app_id = ?"; $params[] = $app_id; }
if ($module_id > 0) { $where[] = "c.module_id = ?"; $params[] = $module_id; }

if ($q !== "") {
  $where[] = "(c.file_path LIKE ? OR c.title LIKE ? OR a.app_name LIKE ? OR m.module_name LIKE ?)";
  $like = "%" . $q . "%";
  array_push($params, $like, $like, $like, $like);
}

$sql = "
  SELECT c.*, a.app_name, m.module_name
  FROM contentcode c
  JOIN appsname a ON a.id = c.app_id
  JOIN moduleapps m ON m.id = c.module_id
";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);

$sql .= " ORDER BY {$orderBy} {$orderDir}, c.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$app_name = "";
if ($app_id > 0) {
  foreach ($apps as $a) {
    if ((int)$a["id"] === $app_id) { $app_name = (string)$a["app_name"]; break; }
  }
}

$module_name = "";
if ($module_id > 0) {
  $st2 = $pdo->prepare("SELECT module_name FROM moduleapps WHERE id=?");
  $st2->execute([$module_id]);
  $module_name = (string)($st2->fetchColumn() ?: "");
}

$title = "Export Content Code";
if ($app_name !== "") $title .= " | App: " . $app_name;
if ($module_name !== "") $title .= " | Module: " . $module_name;
$title .= $view === "summary" ? " | View: Summary" : " | View: Content";

$qsBase = "app_id=" . (int)$app_id . "&module_id=" . (int)$module_id . "&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir);
$qsContent = $qsBase . "&view=content";
$qsSummary = $qsBase . "&view=summary";

function pick_body(array $r, string $view): string {
  if ($view === "summary") return (string)($r["summarycode"] ?? "");
  return (string)($r["content"] ?? "");
}

function render_bold(string $text): string {
  if ($text === "") return "";
  $safe = h($text);
  return (string)preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);
}

function toggle_dir(string $currentDir): string {
  return strtolower($currentDir) === "asc" ? "desc" : "asc";
}

$nameDirNext = $sort === "name" ? toggle_dir($dir) : "asc";
$updDirNext = $sort === "last_updated" ? toggle_dir($dir) : "desc";
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?=h($title)?></title>
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
    .active{font-weight:bold;text-decoration:underline}
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
      <a href="index.php">Back</a>
      <a href="contents.php?app_id=<?= (int)$app_id ?>&module_id=<?= (int)$module_id ?>">Manage Contents</a>

      <a class="<?= $view === "content" ? "active" : "" ?>" href="?<?=h($qsContent)?>">Show Content</a>
      <a class="<?= $view === "summary" ? "active" : "" ?>" href="?<?=h($qsSummary)?>">Show Summary</a>

      <button class="btn" onclick="window.print()">Print</button>
    </div>

    <form method="get" class="row" style="margin-top:10px">
      <input type="hidden" name="app_id" value="<?= (int)$app_id ?>">
      <input type="hidden" name="module_id" value="<?= (int)$module_id ?>">
      <input type="hidden" name="view" value="<?= h($view) ?>">

      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search name, title, app, module" style="min-width:280px">

      <select name="sort">
        <option value="name" <?= $sort === "name" ? "selected" : "" ?>>Sort name</option>
        <option value="last_updated" <?= $sort === "last_updated" ? "selected" : "" ?>>Sort last updated</option>
      </select>

      <select name="dir">
        <option value="asc" <?= $dir === "asc" ? "selected" : "" ?>>ASC</option>
        <option value="desc" <?= $dir === "desc" ? "selected" : "" ?>>DESC</option>
      </select>

      <button class="btn" type="submit">Apply</button>
      <a class="btn" href="?app_id=<?= (int)$app_id ?>&module_id=<?= (int)$module_id ?>&view=<?= h($view) ?>">Reset</a>

      <a class="btn" href="?<?= h($qsBase . "&view=" . $view . "&sort=name&dir=" . $nameDirNext) ?>">Toggle name</a>
      <a class="btn" href="?<?= h($qsBase . "&view=" . $view . "&sort=last_updated&dir=" . $updDirNext) ?>">Toggle updated</a>

      <div class="meta">Ctrl P lalu pilih Save as PDF</div>
    </form>
  </div>

  <h2><?=h($title)?></h2>
  <div class="meta">Mode: <span class="badge"><?=h($view === "summary" ? "summarycode" : "content")?></span></div>
  <div class="meta">Search: <span class="badge"><?= h($q !== "" ? $q : "-") ?></span></div>
  <div class="meta">Sort: <span class="badge"><?= h($sort) ?></span> <span class="badge"><?= h($orderDir) ?></span></div>
  <div class="meta">Total: <?= count($rows) ?> items</div>

  <?php $no = 1; foreach ($rows as $r): ?>
    <div class="item">
      <div class="hdr">
        <span class="idx"><?= (int)$no ?>.</span>
        App: <?=h($r["app_name"])?> | File: <?=h($r["file_path"])?>
        <?php if (!empty($r["updated_at"])): ?>
          | Updated: <?= h((string)$r["updated_at"]) ?>
        <?php endif; ?>
      </div>

      <div class="meta">
        <span class="idx">module_name: <?=h($r["module_name"])?></span>
        <?php if (!empty($r["title"])): ?>
          <span class="badge">title: <?= h((string)$r["title"]) ?></span>
        <?php endif; ?>
      </div>

      <div style="margin-top:10px">
        <pre><?=render_bold(pick_body($r, $view))?></pre>
      </div>
    </div>
  <?php $no++; endforeach; ?>
</body>
</html>
