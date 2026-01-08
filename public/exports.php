<?php
// public/exports.php
declare(strict_types=1);
session_start();

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../lib/helpers.php";

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
}

if (!isset($_SESSION["export_sel"]) || !is_array($_SESSION["export_sel"])) {
  $_SESSION["export_sel"] = [];
}

function sel_add_many(array $ids): void {
  foreach ($ids as $id) {
    $id = (int)$id;
    if ($id > 0) $_SESSION["export_sel"][$id] = 1;
  }
}

function sel_remove_many(array $ids): void {
  foreach ($ids as $id) {
    $id = (int)$id;
    if ($id > 0) unset($_SESSION["export_sel"][$id]);
  }
}

function sel_replace(array $ids): void {
  $out = [];
  foreach ($ids as $id) {
    $id = (int)$id;
    if ($id > 0) $out[$id] = 1;
  }
  $_SESSION["export_sel"] = $out;
}

function sel_count(): int {
  return count($_SESSION["export_sel"]);
}

function sel_ids(): array {
  $ids = array_keys($_SESSION["export_sel"]);
  $out = [];
  foreach ($ids as $id) {
    $id = (int)$id;
    if ($id > 0) $out[] = $id;
  }
  sort($out);
  return $out;
}

function qp(array $over = []): string {
  $q = $_GET;
  foreach ($over as $k => $v) $q[$k] = $v;
  return http_build_query($q);
}

function render_bold(string $text): string {
  if ($text === "") return "";
  $safe = h($text);
  return (string)preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);
}

function normalize_existing_content_ids(PDO $pdo, array $ids): array {
  $ids = array_values(array_unique(array_map("intval", $ids)));
  $ids = array_values(array_filter($ids, fn($v) => $v > 0));
  if (!$ids) return [];

  $ph = implode(",", array_fill(0, count($ids), "?"));
  $st = $pdo->prepare("SELECT id FROM contentcode WHERE id IN ($ph)");
  $st->execute($ids);
  $existing = array_map("intval", array_column($st->fetchAll(), "id"));
  sort($existing);
  return $existing;
}

$action = (string)($_GET["action"] ?? "select");

$app_id_filter = get_int("app_id");
$module_id_filter = get_int("module_id");
$q = trim((string)($_GET["q"] ?? ""));
$sort = (string)($_GET["sort"] ?? "updated_at");
$dir = strtolower((string)($_GET["dir"] ?? "desc"));

$allowedSort = [
  "updated_at" => "c.updated_at",
  "file_path" => "c.file_path",
];

$orderBy = $allowedSort[$sort] ?? "c.updated_at";
$orderDir = $dir === "asc" ? "ASC" : "DESC";

$page = max(1, get_int("page"));
$perPage = 150;
$offset = ($page - 1) * $perPage;

$apps = $pdo->query("SELECT id, app_name FROM appsname ORDER BY updated_at DESC")->fetchAll();

$modules = [];
if ($app_id_filter > 0) {
  $st = $pdo->prepare("SELECT id, module_name FROM moduleapps WHERE app_id=? ORDER BY module_name ASC");
  $st->execute([$app_id_filter]);
  $modules = $st->fetchAll();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $mode = post_str("mode");

  if ($mode === "update_selection" || $mode === "replace_selection") {
    $checked = $_POST["checked_ids"] ?? [];
    $shown = $_POST["shown_ids"] ?? [];

    $checked = is_array($checked) ? $checked : [];
    $shown = is_array($shown) ? $shown : [];

    $shownInt = [];
    foreach ($shown as $sid) {
      $sid = (int)$sid;
      if ($sid > 0) $shownInt[] = $sid;
    }

    $checkedInt = [];
    foreach ($checked as $cid) {
      $cid = (int)$cid;
      if ($cid > 0) $checkedInt[] = $cid;
    }

    if ($mode === "replace_selection") {
      $validChecked = normalize_existing_content_ids($pdo, $checkedInt);
      sel_replace($validChecked);
      flash_set("Selection replaced. Total " . sel_count());
      redirect("exports.php?" . qp(["action" => "select"]));
    }

    $toRemove = array_diff($shownInt, $checkedInt);

    sel_add_many($checkedInt);
    sel_remove_many($toRemove);

    $validNow = normalize_existing_content_ids($pdo, sel_ids());
    $_SESSION["export_sel"] = array_fill_keys($validNow, 1);

    flash_set("Selection saved. Total " . sel_count());
    redirect("exports.php?" . qp(["action" => "select"]));
  }

  if ($mode === "clear_selection") {
    $_SESSION["export_sel"] = [];
    flash_set("Selection cleared");
    redirect("exports.php?" . qp(["action" => "select"]));
  }

  if ($mode === "create_export") {
    $fileName = trim(post_str("file_name"));
    $view = trim(post_str("view_mode"));
    if ($view !== "content" && $view !== "summary") $view = "content";

    $ids = sel_ids();

    if ($fileName === "") {
      flash_set("File name required");
      redirect("exports.php?" . qp(["action" => "select"]));
    }

    if (!$ids) {
      flash_set("No selection");
      redirect("exports.php?" . qp(["action" => "select"]));
    }

    $snap = [
      "app_id" => $app_id_filter,
      "module_id" => $module_id_filter,
      "q" => $q,
      "sort" => $sort,
      "dir" => $dir,
      "created_from" => "exports.php",
    ];

    try {
      $pdo->beginTransaction();

      $validIds = normalize_existing_content_ids($pdo, $ids);
      if (!$validIds) {
        throw new RuntimeException("Selection invalid. All content_id not found in contentcode");
      }

      $st = $pdo->prepare("INSERT INTO content_exports(file_name, view_mode, filter_snapshot, selected_count) VALUES(?,?,?,?)");
      $st->execute([
        $fileName,
        $view,
        json_encode($snap, JSON_UNESCAPED_UNICODE),
        count($validIds),
      ]);

      $exportId = (int)$pdo->lastInsertId();

      $ins = $pdo->prepare("INSERT INTO content_export_items(export_id, content_id, sort_no) VALUES(?,?,?)");
      $no = 1;
      foreach ($validIds as $cid) {
        $ins->execute([$exportId, $cid, $no]);
        $no++;
      }

      $_SESSION["export_sel"] = array_fill_keys($validIds, 1);

      $pdo->commit();

      flash_set("Export created. ID " . $exportId);
      redirect("export_print.php?export_id=" . $exportId);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set("DB error: " . $e->getMessage());
      redirect("exports.php?" . qp(["action" => "select"]));
    }
  }

  if ($mode === "delete_export") {
    $exportId = (int)post_str("export_id");
    if ($exportId > 0) {
      try {
        $st = $pdo->prepare("DELETE FROM content_exports WHERE id=?");
        $st->execute([$exportId]);
        flash_set("Export deleted");
      } catch (Throwable $e) {
        flash_set("DB error: " . $e->getMessage());
      }
    }
    redirect("exports.php?action=history");
  }

  flash_set("Invalid mode");
  redirect("exports.php?" . qp(["action" => "select"]));
}

$where = [];
$params = [];

if ($app_id_filter > 0) { $where[] = "c.app_id = ?"; $params[] = $app_id_filter; }
if ($module_id_filter > 0) { $where[] = "c.module_id = ?"; $params[] = $module_id_filter; }

if ($q !== "") {
  $where[] = "(c.file_path LIKE ? OR c.title LIKE ? OR a.app_name LIKE ? OR m.module_name LIKE ?)";
  $like = "%" . $q . "%";
  array_push($params, $like, $like, $like, $like);
}

$baseSql = "
  FROM contentcode c
  JOIN appsname a ON a.id = c.app_id
  JOIN moduleapps m ON m.id = c.module_id
";

$whereSql = $where ? (" WHERE " . implode(" AND ", $where)) : "";

$st = $pdo->prepare("SELECT COUNT(*) AS cnt {$baseSql} {$whereSql}");
$st->execute($params);
$total = (int)($st->fetch()["cnt"] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));

if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

$listSql = "
  SELECT c.id, c.file_path, c.title, c.updated_at, a.app_name, m.module_name
  {$baseSql}
  {$whereSql}
  ORDER BY {$orderBy} {$orderDir}, c.id DESC
  LIMIT {$perPage} OFFSET {$offset}
";
$st = $pdo->prepare($listSql);
$st->execute($params);
$rows = $st->fetchAll();

$flash = flash_get();

$exports = [];
if ($action === "history") {
  $exports = $pdo->query("SELECT * FROM content_exports ORDER BY id DESC LIMIT 200")->fetchAll();
}

$validNow = normalize_existing_content_ids($pdo, sel_ids());
$_SESSION["export_sel"] = array_fill_keys($validNow, 1);

$returnQs = qp([
  "action" => "select",
  "page" => $page,
  "app_id" => $app_id_filter,
  "module_id" => $module_id_filter,
  "q" => $q,
  "sort" => $sort,
  "dir" => $dir,
]);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Export Selector</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{--bg:#0b1220;--card:#111b2e;--card2:#0f1a2c;--text:#e6eefc;--muted:#a6b3cc;--line:#22314f;--primary:#6ea8ff;--danger:#ff6b6b;--shadow:0 12px 30px rgba(0,0,0,.35);--radius:14px}
    *{box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;margin:0;background:linear-gradient(180deg,#070c16,var(--bg));color:var(--text)}
    a{color:var(--primary);text-decoration:none}
    a:hover{text-decoration:underline}
    .container{max-width:1200px;margin:0 auto;padding:18px}
    .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:10px;flex-wrap:wrap}
    .brand{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
    .badge{font-size:12px;color:var(--muted);border:1px solid var(--line);padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.03)}
    .card{background:linear-gradient(180deg,var(--card),var(--card2));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:14px;margin-bottom:14px}
    .flash{border-left:6px solid var(--primary);background:rgba(110,168,255,.10)}
    .flash.err{border-left-color:var(--danger);background:rgba(255,107,107,.10)}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
    label{font-size:12px;color:var(--muted);display:block;margin:8px 0 6px}
    input[type=text],select{padding:10px 10px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--text);outline:none}
    .btn{border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--text);padding:10px 12px;border-radius:12px;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
    .btn.primary{border-color:rgba(110,168,255,.5);background:rgba(110,168,255,.12)}
    .btn.danger{border-color:rgba(255,107,107,.5);background:rgba(255,107,107,.12)}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid var(--line);font-size:12px;color:var(--muted);background:rgba(255,255,255,.03)}
    .w-260{width:260px}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid var(--line);padding:10px;vertical-align:top}
    th{font-size:12px;color:var(--muted);text-align:left}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
    .small{font-size:12px;color:var(--muted)}
    .pager{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between}
    .pager .left,.pager .right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .btn[disabled]{opacity:.5;cursor:not-allowed}

    /* SELECT READABILITY FIX */
    select{color-scheme:dark}
    select, option, optgroup{background:var(--card2);color:var(--text)}
    option:checked{background:rgba(110,168,255,.22);color:var(--text)}
  </style>
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand">
      <div>
        <div style="font-size:18px;font-weight:700">Export Selector</div>
        <div class="small">Select rows. Print mode. Save snapshot</div>
      </div>
      <span class="badge">contentcode</span>
      <span class="pill">selected <?= (int)sel_count() ?></span>
    </div>

    <div class="row">
      <a class="btn" href="index.php">Back</a>
      <a class="btn <?= $action === "select" ? "primary" : "" ?>" href="exports.php?<?= h(qp(["action" => "select","page" => 1])) ?>">Select</a>
      <a class="btn <?= $action === "history" ? "primary" : "" ?>" href="exports.php?action=history">History</a>
      <?php if ($action !== "history"): ?>
        <a class="btn" href="content_edit.php?action=create&app_id=<?= (int)$app_id_filter ?>&module_id=<?= (int)$module_id_filter ?>&return=exports.php&return_qs=<?= h($returnQs) ?>">Add Content</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash !== ""): ?>
    <?php $isErr = stripos($flash, "error") !== false || stripos($flash, "failed") !== false || stripos($flash, "DB") !== false; ?>
    <div class="card flash <?= $isErr ? "err" : "" ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <?php if ($action === "history"): ?>
    <div class="card">
      <div style="font-weight:700;margin-bottom:10px">Export list</div>
      <table>
        <thead>
          <tr>
            <th style="width:90px">ID</th>
            <th>File</th>
            <th style="width:110px">View</th>
            <th style="width:130px">Selected</th>
            <th style="width:210px">Created</th>
            <th style="width:210px">Printed</th>
            <th style="width:360px">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($exports as $ex): ?>
            <tr>
              <td class="mono"><?= (int)$ex["id"] ?></td>
              <td><?= h((string)$ex["file_name"]) ?></td>
              <td class="mono"><?= h((string)$ex["view_mode"]) ?></td>
              <td class="mono"><?= (int)$ex["selected_count"] ?></td>
              <td class="mono"><?= h((string)$ex["created_at"]) ?></td>
              <td class="mono"><?= h((string)($ex["printed_at"] ?? "")) ?></td>
              <td>
                <a class="btn" href="export_print.php?export_id=<?= (int)$ex["id"] ?>">Open</a>
                <a class="btn primary" href="export_items.php?export_id=<?= (int)$ex["id"] ?>">Edit items</a>
                <form method="post" style="display:inline">
                  <input type="hidden" name="mode" value="delete_export">
                  <input type="hidden" name="export_id" value="<?= (int)$ex["id"] ?>">
                  <button class="btn danger" type="submit" onclick="return confirm('Delete this export')">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$exports): ?>
            <tr><td colspan="7" class="small">Empty</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="card">
      <div style="font-weight:700;margin-bottom:10px">Filter</div>
      <form method="get" action="exports.php">
        <input type="hidden" name="action" value="select">
        <input type="hidden" name="page" value="1">
        <div class="row">
          <div>
            <label>App</label>
            <select class="w-260" name="app_id">
              <option value="0">All apps</option>
              <?php foreach ($apps as $a): ?>
                <option value="<?= (int)$a["id"] ?>" <?= $app_id_filter === (int)$a["id"] ? "selected" : "" ?>>
                  <?= h((string)$a["app_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Module</label>
            <select class="w-260" name="module_id">
              <option value="0">All modules</option>
              <?php foreach ($modules as $m): ?>
                <option value="<?= (int)$m["id"] ?>" <?= $module_id_filter === (int)$m["id"] ? "selected" : "" ?>>
                  <?= h((string)$m["module_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Search</label>
            <input class="w-260" type="text" name="q" value="<?= h($q) ?>" placeholder="filepath, title, app, module">
          </div>

          <div>
            <label>Sort</label>
            <select class="w-260" name="sort">
              <option value="updated_at" <?= $sort === "updated_at" ? "selected" : "" ?>>Last updated</option>
              <option value="file_path" <?= $sort === "file_path" ? "selected" : "" ?>>File path</option>
            </select>
          </div>

          <div>
            <label>Dir</label>
            <select class="w-260" name="dir">
              <option value="desc" <?= $dir === "desc" ? "selected" : "" ?>>DESC</option>
              <option value="asc" <?= $dir === "asc" ? "selected" : "" ?>>ASC</option>
            </select>
          </div>

          <div style="display:flex;align-items:flex-end;gap:10px">
            <button class="btn primary" type="submit">Apply</button>
            <a class="btn" href="exports.php">Reset</a>
            <span class="pill"><?= (int)$total ?> rows</span>
            <span class="pill">page <?= (int)$page ?>/<?= (int)$totalPages ?></span>
          </div>
        </div>
      </form>
    </div>

    <div class="card">
      <div style="font-weight:700;margin-bottom:10px">Export</div>

      <form method="post" class="row" style="align-items:flex-end">
        <input type="hidden" name="mode" value="create_export">

        <div>
          <label>PDF file name</label>
          <input class="w-260" type="text" name="file_name" placeholder="example: appdocs-content-generator">
        </div>

        <div>
          <label>View</label>
          <select class="w-260" name="view_mode">
            <option value="content">content</option>
            <option value="summary">summarycode</option>
          </select>
        </div>

        <div style="display:flex;gap:10px">
          <button class="btn primary" type="submit">Open print view</button>
        </div>
      </form>

      <div class="row" style="margin-top:12px">
        <form method="post" style="display:inline">
          <input type="hidden" name="mode" value="clear_selection">
          <button class="btn danger" type="submit" onclick="return confirm('Clear selection')">Clear selection</button>
        </form>
        <span class="small">Ctrl P. Save as PDF. Browser print</span>
      </div>
    </div>

    <div class="card">
      <div class="pager">
        <div class="left">
          <span class="pill"><?= (int)$perPage ?> / page</span>
          <span class="pill">showing <?= (int)min($total, $offset + 1) ?> to <?= (int)min($total, $offset + $perPage) ?> of <?= (int)$total ?></span>
        </div>
        <div class="right">
          <?php $prevDisabled = $page <= 1; ?>
          <?php $nextDisabled = $page >= $totalPages; ?>
          <?php if ($prevDisabled): ?>
            <button class="btn" disabled>Prev</button>
          <?php else: ?>
            <a class="btn" href="exports.php?<?= h(qp(["page" => $page - 1])) ?>">Prev</a>
          <?php endif; ?>

          <?php if ($nextDisabled): ?>
            <button class="btn" disabled>Next</button>
          <?php else: ?>
            <a class="btn" href="exports.php?<?= h(qp(["page" => $page + 1])) ?>">Next</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div style="font-weight:700;margin-bottom:10px">Select rows</div>

      <form method="post">
        <table>
          <thead>
            <tr>
              <th style="width:70px">Pick</th>
              <th style="width:90px">ID</th>
              <th>Info</th>
              <th style="width:210px">Updated</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="4" class="small">Empty</td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $r): ?>
              <?php
                $cid = (int)$r["id"];
                $checked = isset($_SESSION["export_sel"][$cid]) ? "checked" : "";
              ?>
              <tr>
                <td>
                  <input type="checkbox" name="checked_ids[]" value="<?= $cid ?>" <?= $checked ?>>
                  <input type="hidden" name="shown_ids[]" value="<?= $cid ?>">
                </td>
                <td class="mono"><?= $cid ?></td>
                <td>
                  <div class="small">
                    <span class="pill"><?= h((string)$r["app_name"]) ?></span>
                    <span class="pill"><?= h((string)$r["module_name"]) ?></span>
                  </div>

                  <div class="mono" style="margin-top:8px"><?= h((string)$r["file_path"]) ?></div>

                  <?php if (!empty($r["title"])): ?>
                    <div class="small" style="margin-top:6px">title: <?= h((string)$r["title"]) ?></div>
                  <?php endif; ?>

                  <div style="margin-top:10px">
                    <a class="btn" href="content_edit.php?action=edit&id=<?= (int)$cid ?>&return=exports.php&return_qs=<?= h($returnQs) ?>">Edit</a>
                  </div>
                </td>
                <td class="mono"><?= h((string)($r["updated_at"] ?? "")) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="row" style="margin-top:12px">
          <button class="btn primary" type="submit" name="mode" value="update_selection">Save selection</button>
          <button class="btn" type="submit" name="mode" value="replace_selection" onclick="return confirm('Replace selection with only checked rows on this page')">Replace selection</button>
          <span class="pill">selected <?= (int)sel_count() ?></span>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
