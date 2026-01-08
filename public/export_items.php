<?php
// public/export_items.php
declare(strict_types=1);
session_start();

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../lib/helpers.php";

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
}

function qp(array $over = []): string {
  $q = $_GET;
  foreach ($over as $k => $v) $q[$k] = $v;
  return http_build_query($q);
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

function resequence_sort_no(PDO $pdo, int $exportId): void {
  $st = $pdo->prepare("SELECT id FROM content_export_items WHERE export_id=? ORDER BY sort_no ASC, id ASC");
  $st->execute([$exportId]);
  $ids = array_map("intval", array_column($st->fetchAll(), "id"));

  $upd = $pdo->prepare("UPDATE content_export_items SET sort_no=? WHERE id=?");
  $no = 1;
  foreach ($ids as $itemId) {
    $upd->execute([$no, $itemId]);
    $no++;
  }

  $cnt = count($ids);
  $st2 = $pdo->prepare("UPDATE content_exports SET selected_count=? WHERE id=?");
  $st2->execute([$cnt, $exportId]);
}

$exportId = (int)($_GET["export_id"] ?? 0);
if ($exportId <= 0) {
  flash_set("export_id required");
  redirect("exports.php?action=history");
}

$st = $pdo->prepare("SELECT * FROM content_exports WHERE id=?");
$st->execute([$exportId]);
$export = $st->fetch();
if (!$export) {
  flash_set("Export not found");
  redirect("exports.php?action=history");
}

$app_id_filter = (int)($_GET["app_id"] ?? 0);
$module_id_filter = (int)($_GET["module_id"] ?? 0);
$q = trim((string)($_GET["q"] ?? ""));
$sort = (string)($_GET["sort"] ?? "updated_at");
$dir = strtolower((string)($_GET["dir"] ?? "desc"));

$allowedSort = [
  "updated_at" => "c.updated_at",
  "file_path" => "c.file_path",
];
$orderBy = $allowedSort[$sort] ?? "c.updated_at";
$orderDir = $dir === "asc" ? "ASC" : "DESC";

$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;

$apps = $pdo->query("SELECT id, app_name FROM appsname ORDER BY app_name ASC")->fetchAll();

$modules = [];
if ($app_id_filter > 0) {
  $st = $pdo->prepare("SELECT id, module_name FROM moduleapps WHERE app_id=? ORDER BY module_name ASC");
  $st->execute([$app_id_filter]);
  $modules = $st->fetchAll();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $mode = post_str("mode");

  if ($mode === "remove_items") {
    $contentIds = $_POST["content_ids"] ?? [];
    $contentIds = is_array($contentIds) ? $contentIds : [];
    $contentIds = array_map("intval", $contentIds);
    $contentIds = array_values(array_filter($contentIds, fn($v) => $v > 0));

    if (!$contentIds) {
      flash_set("No items selected");
      redirect("export_items.php?export_id=" . $exportId);
    }

    try {
      $pdo->beginTransaction();

      $ph = implode(",", array_fill(0, count($contentIds), "?"));
      $sql = "DELETE FROM content_export_items WHERE export_id=? AND content_id IN ($ph)";
      $st = $pdo->prepare($sql);
      $st->execute(array_merge([$exportId], $contentIds));

      resequence_sort_no($pdo, $exportId);

      $pdo->commit();
      flash_set("Items removed");
      redirect("export_items.php?export_id=" . $exportId);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set("DB error: " . $e->getMessage());
      redirect("export_items.php?export_id=" . $exportId);
    }
  }

  if ($mode === "add_items") {
    $checked = $_POST["checked_ids"] ?? [];
    $checked = is_array($checked) ? $checked : [];
    $checkedInt = array_map("intval", $checked);
    $checkedInt = array_values(array_filter($checkedInt, fn($v) => $v > 0));

    $manualIdsRaw = trim((string)post_str("manual_ids"));
    if ($manualIdsRaw !== "") {
      $parts = preg_split("~[^\d]+~", $manualIdsRaw) ?: [];
      foreach ($parts as $p) {
        $v = (int)$p;
        if ($v > 0) $checkedInt[] = $v;
      }
    }

    $checkedInt = array_values(array_unique($checkedInt));
    if (!$checkedInt) {
      flash_set("No items selected");
      redirect("export_items.php?export_id=" . $exportId);
    }

    try {
      $pdo->beginTransaction();

      $validIds = normalize_existing_content_ids($pdo, $checkedInt);
      if (!$validIds) {
        throw new RuntimeException("Invalid content ids");
      }

      $st = $pdo->prepare("SELECT content_id FROM content_export_items WHERE export_id=?");
      $st->execute([$exportId]);
      $existing = array_map("intval", array_column($st->fetchAll(), "content_id"));
      $existingSet = array_fill_keys($existing, 1);

      $toAdd = [];
      foreach ($validIds as $cid) {
        if (!isset($existingSet[$cid])) $toAdd[] = $cid;
      }

      if (!$toAdd) {
        resequence_sort_no($pdo, $exportId);
        $pdo->commit();
        flash_set("No new items to add");
        redirect("export_items.php?export_id=" . $exportId);
      }

      $st = $pdo->prepare("SELECT COALESCE(MAX(sort_no),0) AS mx FROM content_export_items WHERE export_id=?");
      $st->execute([$exportId]);
      $mx = (int)($st->fetch()["mx"] ?? 0);

      $ins = $pdo->prepare("INSERT INTO content_export_items(export_id, content_id, sort_no) VALUES(?,?,?)");
      $no = $mx + 1;
      foreach ($toAdd as $cid) {
        $ins->execute([$exportId, $cid, $no]);
        $no++;
      }

      resequence_sort_no($pdo, $exportId);

      $pdo->commit();
      flash_set("Items added: " . count($toAdd));
      redirect("export_items.php?export_id=" . $exportId);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set("DB error: " . $e->getMessage());
      redirect("export_items.php?export_id=" . $exportId);
    }
  }

  flash_set("Invalid mode");
  redirect("export_items.php?export_id=" . $exportId);
}

$st = $pdo->prepare("
  SELECT i.content_id, i.sort_no, c.file_path, c.title, c.updated_at, a.app_name, m.module_name
  FROM content_export_items i
  JOIN contentcode c ON c.id = i.content_id
  JOIN appsname a ON a.id = c.app_id
  JOIN moduleapps m ON m.id = c.module_id
  WHERE i.export_id=?
  ORDER BY i.sort_no ASC, i.id ASC
");
$st->execute([$exportId]);
$currentItems = $st->fetchAll();

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

$currentSet = [];
foreach ($currentItems as $it) {
  $cid = (int)($it["content_id"] ?? 0);
  if ($cid > 0) $currentSet[$cid] = 1;
}

$returnQs = qp([
  "export_id" => $exportId,
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
  <title>Edit Export Items</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{--bg:#0b1220;--card:#111b2e;--card2:#0f1a2c;--text:#e6eefc;--muted:#a6b3cc;--line:#22314f;--primary:#6ea8ff;--danger:#ff6b6b;--shadow:0 12px 30px rgba(0,0,0,.35);--radius:14px}
    *{box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;margin:0;background:linear-gradient(180deg,#070c16,var(--bg));color:var(--text)}
    a{color:var(--primary);text-decoration:none}
    a:hover{text-decoration:underline}
    .container{max-width:1300px;margin:0 auto;padding:18px}
    .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:10px;flex-wrap:wrap}
    .brand{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
    .badge{font-size:12px;color:var(--muted);border:1px solid var(--line);padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.03)}
    .card{background:linear-gradient(180deg,var(--card),var(--card2));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:14px;margin-bottom:14px}
    .flash{border-left:6px solid var(--primary);background:rgba(110,168,255,.10)}
    .flash.err{border-left-color:var(--danger);background:rgba(255,107,107,.10)}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
    label{font-size:12px;color:var(--muted);display:block;margin:8px 0 6px}
    input[type=text],select,textarea{padding:10px 10px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--text);outline:none}
    textarea{min-height:42px}
    .btn{border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--text);padding:10px 12px;border-radius:12px;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
    .btn.primary{border-color:rgba(110,168,255,.5);background:rgba(110,168,255,.12)}
    .btn.danger{border-color:rgba(255,107,107,.5);background:rgba(255,107,107,.12)}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid var(--line);font-size:12px;color:var(--muted);background:rgba(255,255,255,.03)}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid var(--line);padding:10px;vertical-align:top}
    th{font-size:12px;color:var(--muted);text-align:left}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
    .small{font-size:12px;color:var(--muted)}
    .split{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media(max-width:980px){.split{grid-template-columns:1fr}}
    .pager{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between}
    .pager .left,.pager .right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  </style>
</head>
<body>
<div class="container">

  <div class="topbar">
    <div class="brand">
      <div>
        <div style="font-size:18px;font-weight:700">Edit Export Items</div>
        <div class="small">
          export_id <span class="mono"><?= (int)$exportId ?></span>
          file <span class="mono"><?= h((string)$export["file_name"]) ?></span>
          view <span class="mono"><?= h((string)$export["view_mode"]) ?></span>
        </div>
      </div>
      <span class="badge">content_export_items</span>
      <span class="pill">items <?= (int)count($currentItems) ?></span>
    </div>

    <div class="row">
      <a class="btn" href="exports.php?action=history">Back</a>
      <a class="btn primary" href="export_print.php?export_id=<?= (int)$exportId ?>">Open print</a>
    </div>
  </div>

  <?php if ($flash !== ""): ?>
    <?php $isErr = stripos($flash, "error") !== false || stripos($flash, "failed") !== false || stripos($flash, "DB") !== false; ?>
    <div class="card flash <?= $isErr ? "err" : "" ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="split">
    <div class="card">
      <div style="font-weight:700;margin-bottom:10px">Current items</div>

      <form method="post">
        <input type="hidden" name="mode" value="remove_items">
        <table>
          <thead>
            <tr>
              <th style="width:70px">Remove</th>
              <th style="width:90px">Order</th>
              <th style="width:90px">ID</th>
              <th>Info</th>
              <th style="width:210px">Updated</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$currentItems): ?>
              <tr><td colspan="5" class="small">Empty</td></tr>
            <?php endif; ?>

            <?php foreach ($currentItems as $it): ?>
              <?php $cid = (int)($it["content_id"] ?? 0); ?>
              <tr>
                <td>
                  <input type="checkbox" name="content_ids[]" value="<?= $cid ?>">
                </td>
                <td class="mono"><?= (int)($it["sort_no"] ?? 0) ?></td>
                <td class="mono"><?= $cid ?></td>
                <td>
                  <div class="small">
                    <span class="pill"><?= h((string)$it["app_name"]) ?></span>
                    <span class="pill"><?= h((string)$it["module_name"]) ?></span>
                  </div>
                  <div class="mono" style="margin-top:8px"><?= h((string)$it["file_path"]) ?></div>
                  <?php if (!empty($it["title"])): ?>
                    <div class="small" style="margin-top:6px">title: <?= h((string)$it["title"]) ?></div>
                  <?php endif; ?>
                  <div style="margin-top:10px">
                    <a class="btn" href="content_edit.php?action=edit&id=<?= $cid ?>&return=export_items.php&return_qs=<?= h($returnQs) ?>">Edit content</a>
                  </div>
                </td>
                <td class="mono"><?= h((string)($it["updated_at"] ?? "")) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="row" style="margin-top:12px">
          <button class="btn danger" type="submit" onclick="return confirm('Remove selected items')">Remove selected</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div style="font-weight:700;margin-bottom:10px">Add items</div>

      <form method="get" action="export_items.php">
        <input type="hidden" name="export_id" value="<?= (int)$exportId ?>">
        <input type="hidden" name="page" value="1">
        <div class="row">
          <div>
            <label>App</label>
            <select style="width:260px" name="app_id">
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
            <select style="width:260px" name="module_id">
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
            <input style="width:260px" type="text" name="q" value="<?= h($q) ?>" placeholder="filepath, title, app, module">
          </div>

          <div>
            <label>Sort</label>
            <select style="width:260px" name="sort">
              <option value="updated_at" <?= $sort === "updated_at" ? "selected" : "" ?>>Last updated</option>
              <option value="file_path" <?= $sort === "file_path" ? "selected" : "" ?>>File path</option>
            </select>
          </div>

          <div>
            <label>Dir</label>
            <select style="width:260px" name="dir">
              <option value="desc" <?= $dir === "desc" ? "selected" : "" ?>>DESC</option>
              <option value="asc" <?= $dir === "asc" ? "selected" : "" ?>>ASC</option>
            </select>
          </div>

          <div style="display:flex;align-items:flex-end;gap:10px">
            <button class="btn primary" type="submit">Apply</button>
            <a class="btn" href="export_items.php?export_id=<?= (int)$exportId ?>">Reset</a>
            <span class="pill"><?= (int)$total ?> rows</span>
            <span class="pill">page <?= (int)$page ?>/<?= (int)$totalPages ?></span>
          </div>
        </div>
      </form>

      <div class="card" style="margin-top:14px">
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
              <a class="btn" href="export_items.php?<?= h(qp(["export_id"=>$exportId,"page" => $page - 1])) ?>">Prev</a>
            <?php endif; ?>

            <?php if ($nextDisabled): ?>
              <button class="btn" disabled>Next</button>
            <?php else: ?>
              <a class="btn" href="export_items.php?<?= h(qp(["export_id"=>$exportId,"page" => $page + 1])) ?>">Next</a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <form method="post" style="margin-top:12px">
        <input type="hidden" name="mode" value="add_items">

        <div class="row" style="align-items:flex-end">
          <div style="flex:1;min-width:260px">
            <label>Manual IDs</label>
            <textarea name="manual_ids" placeholder="example: 12, 15, 88"><?= h((string)($_POST["manual_ids"] ?? "")) ?></textarea>
            <div class="small">Numbers only. Any separators.</div>
          </div>

          <div>
            <button class="btn primary" type="submit">Add selected</button>
          </div>
        </div>

        <table style="margin-top:12px">
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
                $already = isset($currentSet[$cid]);
              ?>
              <tr>
                <td>
                  <?php if ($already): ?>
                    <span class="pill">added</span>
                  <?php else: ?>
                    <input type="checkbox" name="checked_ids[]" value="<?= $cid ?>">
                  <?php endif; ?>
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
                    <a class="btn" href="content_edit.php?action=edit&id=<?= $cid ?>&return=export_items.php&return_qs=<?= h($returnQs) ?>">Edit content</a>
                  </div>
                </td>
                <td class="mono"><?= h((string)($r["updated_at"] ?? "")) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </form>
    </div>
  </div>

</div>
</body>
</html>
