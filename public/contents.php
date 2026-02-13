<?php
// public/contents.php
declare(strict_types=1);
session_start();

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../lib/helpers.php";
require_once __DIR__ . "/../config/openai.php";

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
}

/*
DB required

ALTER TABLE appsname
  ADD COLUMN root_path VARCHAR(512) NULL AFTER app_name;

ALTER TABLE contentcode
  ADD COLUMN fs_exists TINYINT(1) NOT NULL DEFAULT 0 AFTER content,
  ADD COLUMN fs_mtime DATETIME NULL AFTER fs_exists,
  ADD COLUMN fs_size BIGINT NULL AFTER fs_mtime,
  ADD COLUMN fs_hash CHAR(64) NULL AFTER fs_size,
  ADD COLUMN fs_checked_at DATETIME NULL AFTER fs_hash;
*/

function build_summary_prompt(string $filePath, string $title, string $content): array
{
  $system = "You are a senior software engineer. Write in English. Plain text. No markdown. Poin ringkas. Maks 3200 karakter.";
  $user =
    "File: {$filePath}\n" .
    "Title: {$title}\n\n" .
    "Content:\n{$content}\n\n" .
    "Task:\n" .
    "Buat ringkasan kode untuk disimpan ke field summarycode.\n" .
    "Isi minimal:\n" .
    "Tujuan file\n" .
    "Alur utama\n" .
    "method function apa saja\n" .
    "relasi dengan file lain\n" .
    "relasi dengan database apa\n" .
    "Validasi input\n" .
    "Query database\n" .
    "Resiko umum\n" .
    "Catatan pengembangan\n" .
    "Write in English\n";

  return [
    ["role" => "system", "content" => $system],
    ["role" => "user", "content" => $user],
  ];
}

function openai_chat(array $messages, array $opt = []): array
{
  if (!defined("OPENAI_API_KEY") || OPENAI_API_KEY === "") {
    return ["ok" => false, "error" => "OPENAI_API_KEY belum diset"];
  }

  $apiKey = OPENAI_API_KEY;

  $model = $opt["model"] ?? "gpt-4o-mini";
  $temperature = isset($opt["temperature"]) ? (float)$opt["temperature"] : 0.2;
  $maxTokens = isset($opt["max_tokens"]) ? (int)$opt["max_tokens"] : 600;

  $payload = [
    "model" => $model,
    "messages" => $messages,
    "temperature" => $temperature,
    "max_tokens" => $maxTokens,
  ];

  $ch = curl_init("https://api.openai.com/v1/chat/completions");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Content-Type: application/json",
      "Authorization: Bearer " . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 60,
  ]);

  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) return ["ok" => false, "error" => "cURL error: " . $err];

  $json = json_decode($raw, true);
  if (!is_array($json)) return ["ok" => false, "error" => "Response bukan JSON valid", "raw" => $raw];

  if ($http < 200 || $http >= 300) {
    $msg = $json["error"]["message"] ?? ("HTTP " . $http);
    return ["ok" => false, "error" => $msg, "raw" => $json];
  }

  $text = trim((string)($json["choices"][0]["message"]["content"] ?? ""));
  if ($text === "") return ["ok" => false, "error" => "AI output kosong"];

  return ["ok" => true, "text" => $text, "raw" => $json];
}

function summarize_latest_pending(PDO $pdo, int $limit = 5, int $appId = 0, int $moduleId = 0): array
{
  $limit = max(1, min(20, $limit));

  $where = ["(summarycode IS NULL OR TRIM(summarycode) = '')"];
  $params = [];

  if ($appId > 0) {
    $where[] = "app_id = ?";
    $params[] = $appId;
  }
  if ($moduleId > 0) {
    $where[] = "module_id = ?";
    $params[] = $moduleId;
  }

  $sql = "
    SELECT id, file_path, title, content
    FROM contentcode
    WHERE " . implode(" AND ", $where) . "
    ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
    LIMIT {$limit}
  ";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $targets = $st->fetchAll();

  $upd = $pdo->prepare("UPDATE contentcode SET summarycode=?, updated_at=NOW() WHERE id=?");

  $updated = 0;
  $failed = 0;
  $skipped = 0;
  $errors = [];
  $updatedFiles = [];

  foreach ($targets as $row) {
    $cid = (int)($row["id"] ?? 0);
    $filePath = trim((string)($row["file_path"] ?? ""));
    $title = trim((string)($row["title"] ?? ""));
    $content = trim((string)($row["content"] ?? ""));

    if ($cid <= 0 || $content === "") {
      $skipped++;
      continue;
    }

    $messages = build_summary_prompt($filePath, $title, $content);
    $resp = openai_chat($messages, [
      "model" => "gpt-4o-mini",
      "temperature" => 0.2,
      "max_tokens" => 900,
    ]);

    if (!$resp["ok"]) {
      $failed++;
      $errors[] = ($filePath !== "" ? $filePath : ("ID " . $cid)) . ": " . (string)($resp["error"] ?? "unknown");
      continue;
    }

    $summary = trim((string)$resp["text"]);
    if ($summary === "") {
      $failed++;
      $errors[] = ($filePath !== "" ? $filePath : ("ID " . $cid)) . ": AI output kosong";
      continue;
    }

    $upd->execute([$summary, $cid]);
    $updated++;
    $updatedFiles[] = $filePath !== "" ? $filePath : ("ID " . $cid);
  }

  return [
    "selected" => count($targets),
    "updated" => $updated,
    "failed" => $failed,
    "skipped" => $skipped,
    "errors" => $errors,
    "updated_files" => $updatedFiles,
  ];
}

function norm_sep(string $p): string
{
  $p = str_replace("\\", "/", $p);
  $p = preg_replace("~/{2,}~", "/", $p);
  return rtrim($p, "/");
}

function safe_join(string $root, string $rel): string
{
  $root = norm_sep($root);
  $rel = ltrim(norm_sep($rel), "/");
  return $root . "/" . $rel;
}

function qp(array $over = []): string
{
  $q = $_GET;
  foreach ($over as $k => $v) $q[$k] = $v;
  return http_build_query($q);
}

$action = (string)($_GET["action"] ?? "list");
$id = get_int("id");

$app_id_filter = get_int("app_id");
$module_id_filter = get_int("module_id");

$q = trim((string)($_GET["q"] ?? ""));
$sort = (string)($_GET["sort"] ?? "updated_at");
$dir = strtolower((string)($_GET["dir"] ?? "desc"));

$allowedSort = [
  "updated_at" => "c.updated_at",
  "file_path" => "c.file_path",
  "fs_checked_at" => "c.fs_checked_at",
];

$orderBy = $allowedSort[$sort] ?? "c.updated_at";
$orderDir = $dir === "asc" ? "ASC" : "DESC";

$page = max(1, get_int("page"));
$perPage = 150;
$offset = ($page - 1) * $perPage;

$apps = $pdo->query("SELECT id, app_name, root_path FROM appsname ORDER BY updated_at DESC")->fetchAll();

/* JSON endpoint for dependent module dropdown */
if ($action === "modules") {
  header("Content-Type: application/json; charset=utf-8");

  $appId = get_int("app_id");
  if ($appId <= 0) {
    echo json_encode(["ok" => true, "items" => []], JSON_UNESCAPED_UNICODE);
    exit;
  }

  try {
    $st = $pdo->prepare("SELECT id, module_name FROM moduleapps WHERE app_id=? ORDER BY module_name ASC");
    $st->execute([$appId]);
    $items = $st->fetchAll();
    echo json_encode(["ok" => true, "items" => $items], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode(["ok" => false, "error" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

$modules = [];
if ($app_id_filter > 0) {
  $st = $pdo->prepare("SELECT id, module_name FROM moduleapps WHERE app_id=? ORDER BY module_name ASC");
  $st->execute([$app_id_filter]);
  $modules = $st->fetchAll();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (post_str("mode") === "bulk_summary_5") {
    try {
      $bulkAppId = (int)post_str("bulk_app_id");
      $bulkModuleId = (int)post_str("bulk_module_id");
      $res = summarize_latest_pending($pdo, 5, $bulkAppId, $bulkModuleId);

      $msg = "Bulk summary selesai. selected {$res["selected"]}, updated {$res["updated"]}, failed {$res["failed"]}, skipped {$res["skipped"]}";
      if (!empty($res["updated_files"])) {
        $msg .= ". Updated files: " . implode(", ", $res["updated_files"]);
      }
      if (!empty($res["errors"])) {
        $msg .= ". " . implode(" | ", array_slice($res["errors"], 0, 3));
      }
      flash_set($msg);
    } catch (Throwable $e) {
      flash_set("Bulk summary gagal: " . $e->getMessage());
    }

    redirect("contents.php?app_id={$app_id_filter}&module_id={$module_id_filter}&page={$page}&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir));
  }

  $app_id = (int)post_str("app_id");
  $module_id = (int)post_str("module_id");
  $file_path = trim(post_str("file_path"));
  $title = trim(post_str("title"));
  $summarycode = trim(post_str("summarycode"));
  $content = trim((string)($_POST["content"] ?? ""));
  $ai_summary = post_str("ai_summary");

  if ($app_id <= 0 || $module_id <= 0 || $file_path === "" || $content === "") {
    flash_set("App, module, file path, content wajib");
    redirect("contents.php?app_id={$app_id_filter}&module_id={$module_id_filter}&page={$page}&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir));
  }

  /* Validate module belongs to app */
  $st = $pdo->prepare("SELECT COUNT(*) AS cnt FROM moduleapps WHERE id=? AND app_id=?");
  $st->execute([$module_id, $app_id]);
  $okPair = (int)($st->fetch()["cnt"] ?? 0);
  if ($okPair <= 0) {
    flash_set("Module tidak sesuai App");
    redirect("contents.php?app_id={$app_id_filter}&module_id={$module_id_filter}&page={$page}&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir));
  }

  if ($ai_summary === "1") {
    $messages = build_summary_prompt($file_path, $title, $content);
    $resp = openai_chat($messages, [
      "model" => "gpt-4o-mini",
      "temperature" => 0.2,
      "max_tokens" => 900,
    ]);

    if (!$resp["ok"]) {
      flash_set("AI summary gagal: " . ($resp["error"] ?? "unknown"));
      redirect("contents.php?app_id={$app_id_filter}&module_id={$module_id_filter}&page={$page}&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir));
    }

    $summarycode = trim((string)$resp["text"]);
  }

  $safeTitle = $title !== "" ? $title : null;
  $safeSummary = $summarycode !== "" ? $summarycode : null;

  try {
    if (post_str("mode") === "create") {
      $st = $pdo->prepare(
        "INSERT INTO contentcode (app_id, module_id, file_path, title, summarycode, content)
         VALUES (?, ?, ?, ?, ?, ?)"
      );
      $st->execute([$app_id, $module_id, $file_path, $safeTitle, $safeSummary, $content]);

      $newId = (int)$pdo->lastInsertId();
      flash_set("Content dibuat. ID " . $newId);
      redirect("contents.php?app_id={$app_id_filter}&module_id={$module_id_filter}&page={$page}&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir));
    }

    if (post_str("mode") === "update") {
      $cid = (int)post_str("id");

      $st = $pdo->prepare(
        "UPDATE contentcode
         SET app_id = ?, module_id = ?, file_path = ?, title = ?, summarycode = ?, content = ?
         WHERE id = ?"
      );
      $st->execute([$app_id, $module_id, $file_path, $safeTitle, $safeSummary, $content, $cid]);

      $affected = (int)$st->rowCount();
      flash_set("Content diupdate. Rows " . $affected);
      redirect("contents.php?app_id={$app_id_filter}&module_id={$module_id_filter}&page={$page}&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir));
    }

    flash_set("Mode tidak valid");
    redirect("contents.php?app_id={$app_id_filter}&module_id={$module_id_filter}&page={$page}&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir));
  } catch (PDOException $e) {
    flash_set("DB error: " . $e->getMessage());
    redirect("contents.php?app_id={$app_id_filter}&module_id={$module_id_filter}&page={$page}&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir));
  } catch (Throwable $e) {
    flash_set("Error: " . $e->getMessage());
    redirect("contents.php?app_id={$app_id_filter}&module_id={$module_id_filter}&page={$page}&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir));
  }
}

if ($action === "delete" && $id > 0) {
  try {
    $st = $pdo->prepare("DELETE FROM contentcode WHERE id=?");
    $st->execute([$id]);
    flash_set("Content dihapus");
  } catch (PDOException $e) {
    flash_set("DB error: " . $e->getMessage());
  }
  redirect("contents.php?app_id={$app_id_filter}&module_id={$module_id_filter}&page={$page}&q=" . urlencode($q) . "&sort=" . urlencode($sort) . "&dir=" . urlencode($dir));
}

$editRow = null;
if ($action === "edit" && $id > 0) {
  $st = $pdo->prepare("SELECT * FROM contentcode WHERE id=?");
  $st->execute([$id]);
  $editRow = $st->fetch() ?: null;
}

$where = [];
$params = [];

if ($app_id_filter > 0) {
  $where[] = "c.app_id = ?";
  $params[] = $app_id_filter;
}
if ($module_id_filter > 0) {
  $where[] = "c.module_id = ?";
  $params[] = $module_id_filter;
}
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

$st = $pdo->prepare("
  SELECT
    SUM(CASE WHEN c.summarycode IS NULL OR TRIM(c.summarycode) = '' THEN 1 ELSE 0 END) AS empty_cnt,
    SUM(CASE WHEN c.summarycode IS NOT NULL AND TRIM(c.summarycode) <> '' THEN 1 ELSE 0 END) AS filled_cnt
  {$baseSql}
  {$whereSql}
");
$st->execute($params);
$sumStat = $st->fetch() ?: [];
$summaryEmptyCount = (int)($sumStat["empty_cnt"] ?? 0);
$summaryFilledCount = (int)($sumStat["filled_cnt"] ?? 0);

if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

$listSql = "
  SELECT
    c.*,
    a.app_name,
    a.root_path,
    m.module_name
  {$baseSql}
  {$whereSql}
  ORDER BY {$orderBy} {$orderDir}, c.id DESC
  LIMIT {$perPage} OFFSET {$offset}
";

$st = $pdo->prepare($listSql);
$st->execute($params);
$rows = $st->fetchAll();

$flash = flash_get();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Contents</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root{--bg:#0b1220;--card:#111b2e;--card2:#0f1a2c;--text:#e6eefc;--muted:#a6b3cc;--line:#22314f;--primary:#6ea8ff;--danger:#ff6b6b;--warn:#ffd166;--shadow:0 12px 30px rgba(0,0,0,.35);--radius:14px}
    *{box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;margin:0;background:linear-gradient(180deg,#070c16,var(--bg));color:var(--text)}
    a{color:var(--primary);text-decoration:none}
    a:hover{text-decoration:underline}
    .container{max-width:1200px;margin:0 auto;padding:18px}
    .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:10px;flex-wrap:wrap}
    .brand{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
    .badge{font-size:12px;color:var(--muted);border:1px solid var(--line);padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.03)}
    .card{background:linear-gradient(180deg,var(--card),var(--card2));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:14px;margin-bottom:14px}
    .card h3{margin:0 0 10px 0;font-size:16px}
    .flash{border-left:6px solid var(--primary);background:rgba(110,168,255,.10)}
    .flash.err{border-left-color:var(--danger);background:rgba(255,107,107,.10)}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .row{display:flex;gap:12px;flex-wrap:wrap}
    label{font-size:12px;color:var(--muted);display:block;margin:8px 0 6px}
    input[type=text],select,textarea{width:100%;padding:10px 10px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--text);outline:none}

    textarea{min-height:140px;resize:vertical}
    .small{font-size:12px;color:var(--muted)}
    .btn{border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--text);padding:10px 12px;border-radius:12px;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
    .btn.primary{border-color:rgba(110,168,255,.5);background:rgba(110,168,255,.12)}
    .btn.danger{border-color:rgba(255,107,107,.5);background:rgba(255,107,107,.12)}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid var(--line);font-size:12px;color:var(--muted);background:rgba(255,255,255,.03)}
    .pill.ok{border-color:rgba(110,168,255,.55)}
    .pill.miss{border-color:rgba(255,107,107,.55)}
    .pill.chg{border-color:rgba(255,209,102,.65)}
    .actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .w-260{width:260px}
    .cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .item{padding:14px}
    .itemhead{display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap}
    .itemhead > div:first-child{flex:1 1 520px;min-width:280px}
    .actions{flex:0 0 auto;margin-left:auto}
    .meta{display:flex;flex-wrap:wrap;gap:8px}
    .kv{font-size:12px;color:var(--muted)}
    .k{color:var(--muted)}
    .v{color:var(--text)}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
    .kv .v.mono{word-break:break-all;overflow-wrap:anywhere}
    .clip{display:-webkit-box;-webkit-line-clamp:6;-webkit-box-orient:vertical;overflow:hidden}
    .hr{height:1px;background:var(--line);margin:10px 0}
    .pager{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between}
    .pager .left,.pager .right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .btn[disabled]{opacity:.5;cursor:not-allowed}
    @media (max-width:900px){.grid{grid-template-columns:1fr}.cards{grid-template-columns:1fr}}
    dialog{border:none;border-radius:18px;padding:0;background:linear-gradient(180deg,var(--card),var(--card2));color:var(--text);box-shadow:var(--shadow);width:min(980px,calc(100vw - 30px))}
    dialog::backdrop{background:rgba(0,0,0,.6)}
    .modalHead{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:14px 14px;border-bottom:1px solid var(--line);flex-wrap:wrap}
    .modalBody{padding:14px;max-height:75vh;overflow:auto}
    .modalTitle{font-weight:700}
    pre{white-space:pre-wrap;margin:0;color:var(--text)}

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
        <div style="font-size:18px;font-weight:700">Contents</div>
        <div class="small">Cards view, modal detail, paging 50</div>
      </div>
      <span class="badge">appdocs</span>
    </div>
    <div class="row">
      <a class="btn" href="index.php">Back</a>
      <a class="btn" href="export_pdf.php?<?= h(qp(["page" => $page])) ?>">Print to PDF</a>
    </div>
  </div>

  <?php if ($flash !== ""): ?>
    <?php $isErr = stripos($flash, "error") !== false || stripos($flash, "gagal") !== false || stripos($flash, "DB") !== false || stripos($flash, "gagal") !== false; ?>
    <div class="card flash <?= $isErr ? "err" : "" ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="card">
    <h3>Filter</h3>
    <form method="get" action="contents.php">
      <input type="hidden" name="page" value="1">
      <div class="row">
        <div>
          <label>App</label>
          <select class="w-260" name="app_id">
            <option value="0">All Apps</option>
            <?php foreach ($apps as $a): ?>
              <option value="<?= (int)$a["id"] ?>" <?= $app_id_filter === (int)$a["id"] ? "selected" : "" ?>>
                <?= h($a["app_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Module</label>
          <select class="w-260" name="module_id">
            <option value="0">All Modules</option>
            <?php foreach ($modules as $m): ?>
              <option value="<?= (int)$m["id"] ?>" <?= $module_id_filter === (int)$m["id"] ? "selected" : "" ?>>
                <?= h($m["module_name"]) ?>
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
            <option value="fs_checked_at" <?= $sort === "fs_checked_at" ? "selected" : "" ?>>FS checked</option>
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
          <a class="btn" href="contents.php">Reset</a>

          <?php if ($app_id_filter > 0): ?>
            <a class="btn" href="apps.php?action=syncfs&id=<?= (int)$app_id_filter ?>" onclick="return confirm('Sync filesystem for this app')">Sync FS</a>
          <?php endif; ?>

          <span class="pill"><?= (int)$total ?> rows</span>
          <span class="pill ok">summary terisi <?= (int)$summaryFilledCount ?></span>
          <span class="pill miss">summary kosong <?= (int)$summaryEmptyCount ?></span>
          <span class="pill">page <?= (int)$page ?>/<?= (int)$totalPages ?></span>
        </div>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Automation</h3>
    <div class="small" style="margin-bottom:10px">Summary 5 file terbaru dengan summary kosong di table contentcode.</div>
    <form method="post" onsubmit="return confirm('Jalankan summary untuk 5 file terbaru yang summarycode masih kosong?')">
      <input type="hidden" name="mode" value="bulk_summary_5">
      <input type="hidden" name="bulk_app_id" value="<?= (int)$app_id_filter ?>">
      <input type="hidden" name="bulk_module_id" value="<?= (int)$module_id_filter ?>">
      <button class="btn primary" type="submit">Eksekusi Summary 5 File</button>
    </form>
  </div>

  <div class="card">
    <h3><?= $editRow ? "Edit Content" : "Create Content" ?></h3>

    <div class="small" style="margin-bottom:10px">
      Important: Saving edits updates the database only. It does not modify the physical codebase files on disk.
    </div>

    <form method="post">
      <input type="hidden" name="mode" value="<?= $editRow ? "update" : "create" ?>">
      <?php if ($editRow): ?>
        <input type="hidden" name="id" value="<?= (int)$editRow["id"] ?>">
      <?php endif; ?>

      <div class="grid">
        <div>
          <label>App</label>
          <select name="app_id" id="form_app_id">
            <option value="0">Select App</option>
            <?php foreach ($apps as $a): ?>
              <?php
                $sel = "";
                $val = (int)$a["id"];
                if ($editRow && (int)$editRow["app_id"] === $val) $sel = "selected";
              ?>
              <option value="<?= $val ?>" <?= $sel ?>><?= h($a["app_name"]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Module</label>
          <select name="module_id" id="form_module_id" data-selected="<?= (int)($editRow["module_id"] ?? 0) ?>">
            <option value="0">Select Module</option>
          </select>
        </div>
      </div>

      <label style="margin-top:10px">File path</label>
      <input type="text" name="file_path" value="<?= h($editRow["file_path"] ?? "") ?>">

      <label style="margin-top:10px">Title</label>
      <input type="text" name="title" value="<?= h($editRow["title"] ?? "") ?>">

      <label style="margin-top:10px">Summary</label>
      <textarea name="summarycode" style="min-height:110px"><?= h((string)($editRow["summarycode"] ?? "")) ?></textarea>

      <div class="row" style="align-items:center;margin-top:8px">
        <label style="display:flex;gap:8px;align-items:center;margin:0">
          <input type="checkbox" name="ai_summary" value="1">
          <span class="small">Generate summary via ChatGPT</span>
        </label>
        <span class="small">Requires OPENAI_API_KEY</span>
      </div>

      <label style="margin-top:10px">Content</label>
      <textarea name="content"><?= h((string)($editRow["content"] ?? "")) ?></textarea>

      <div class="row" style="margin-top:10px">
        <button class="btn primary" type="submit">Save</button>
        <?php if ($editRow): ?>
          <a class="btn" href="contents.php?<?= h(qp(["action" => "list", "id" => 0])) ?>">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
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
          <a class="btn" href="contents.php?<?= h(qp(["page" => $page - 1])) ?>">Prev</a>
        <?php endif; ?>

        <?php if ($nextDisabled): ?>
          <button class="btn" disabled>Next</button>
        <?php else: ?>
          <a class="btn" href="contents.php?<?= h(qp(["page" => $page + 1])) ?>">Next</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h3>List</h3>

    <div class="cards">
      <?php foreach ($rows as $r): ?>
        <?php
          $sum = (string)($r["summarycode"] ?? "");
          $cnt = (string)($r["content"] ?? "");
          $file = (string)($r["file_path"] ?? "");
          $titleTxt = (string)($r["title"] ?? "");
          $appName = (string)($r["app_name"] ?? "");
          $modName = (string)($r["module_name"] ?? "");
          $cid = (int)($r["id"] ?? 0);
          $updatedAt = (string)($r["updated_at"] ?? "");

          $rootPath = (string)($r["root_path"] ?? "");
          $fullPath = "";
          if ($rootPath !== "" && $file !== "") {
            $fullPath = safe_join($rootPath, $file);
          }

          $fsExists = (int)($r["fs_exists"] ?? 0);
          $fsChecked = (string)($r["fs_checked_at"] ?? "");
          $fsMtime = (string)($r["fs_mtime"] ?? "");
          $fsSize = $r["fs_size"] ?? null;

          $maybeChanged = false;
          if ($fsExists === 1 && $fsMtime !== "" && $updatedAt !== "") {
            $maybeChanged = strtotime($fsMtime) > strtotime($updatedAt);
          }
        ?>
        <div class="card item">
          <div class="itemhead">
            <div>
              <div style="font-weight:700;font-size:14px">
                <span class="pill">ID <?= $cid ?></span>
                <?= $titleTxt !== "" ? h($titleTxt) : "<span class='small'>(no title)</span>" ?>
              </div>

              <div class="meta" style="margin-top:8px">
                <span class="pill"><?= h($appName) ?></span>
                <span class="pill"><?= h($modName) ?></span>

                <?php if ($fsExists === 0): ?>
                  <span class="pill miss">missing</span>
                <?php else: ?>
                  <span class="pill ok">exists</span>
                <?php endif; ?>

                <?php if ($maybeChanged): ?>
                  <span class="pill chg">changed</span>
                <?php endif; ?>
              </div>

              <div class="kv" style="margin-top:8px">
                <span class="k">File</span>
                <span class="v mono"><?= h($file) ?></span>
              </div>

              <?php if ($fullPath !== ""): ?>
                <div class="kv" style="margin-top:6px">
                  <span class="k">Full</span>
                  <span class="v mono"><?= h($fullPath) ?></span>
                </div>
              <?php endif; ?>

              <div class="kv" style="margin-top:6px">
                <span class="k">Updated</span>
                <span class="v mono"><?= h($updatedAt !== "" ? $updatedAt : "-") ?></span>
              </div>

              <div class="kv" style="margin-top:6px">
                <span class="k">FS checked</span>
                <span class="v mono"><?= h($fsChecked !== "" ? $fsChecked : "-") ?></span>
              </div>

              <div class="kv" style="margin-top:6px">
                <span class="k">FS mtime</span>
                <span class="v mono"><?= h($fsMtime !== "" ? $fsMtime : "-") ?></span>
              </div>

              <div class="kv" style="margin-top:6px">
                <span class="k">FS size</span>
                <span class="v mono"><?= h($fsSize !== null ? (string)$fsSize : "-") ?></span>
              </div>
            </div>

            <div class="actions">
              <button
                class="btn"
                type="button"
                data-modal="modalSummary"
                data-title="<?= h($titleTxt !== "" ? $titleTxt : "Summary") ?>"
                data-body="<?= h($sum !== "" ? $sum : "(empty)") ?>"
              >View Summary</button>

              <button
                class="btn"
                type="button"
                data-modal="modalContent"
                data-title="<?= h($titleTxt !== "" ? $titleTxt : "Content") ?>"
                data-body="<?= h($cnt !== "" ? $cnt : "(empty)") ?>"
              >View Content</button>

              <a class="btn" href="contents.php?<?= h(qp(["action" => "edit", "id" => $cid])) ?>">Edit</a>
              <a class="btn danger" href="contents.php?<?= h(qp(["action" => "delete", "id" => $cid])) ?>" onclick="return confirm('Delete this content')">Delete</a>
            </div>
          </div>

          <div class="hr"></div>

          <div class="kv"><span class="k">Summary preview</span></div>
          <div class="mono clip" style="margin-top:8px"><?= h($sum !== "" ? $sum : "(empty)") ?></div>

          <div class="hr"></div>

          <div class="kv"><span class="k">Content preview</span></div>
          <div class="mono clip" style="margin-top:8px"><?= h($cnt !== "" ? $cnt : "(empty)") ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<dialog id="modalSummary">
  <div class="modalHead">
    <div class="modalTitle" data-title>Summary</div>
    <div class="row">
      <button class="btn" type="button" data-copy>Copy</button>
      <button class="btn primary" type="button" data-close>Close</button>
    </div>
  </div>
  <div class="modalBody">
    <pre class="mono" data-body></pre>
  </div>
</dialog>

<dialog id="modalContent">
  <div class="modalHead">
    <div class="modalTitle" data-title>Content</div>
    <div class="row">
      <button class="btn" type="button" data-copy>Copy</button>
      <button class="btn primary" type="button" data-close>Close</button>
    </div>
  </div>
  <div class="modalBody">
    <pre class="mono" data-body></pre>
  </div>
</dialog>

<script>
(function(){
  function openDialog(dlg, title, body){
    if (!dlg) return;
    var t = dlg.querySelector("[data-title]");
    var b = dlg.querySelector("[data-body]");
    if (t) t.textContent = title || "Detail";
    if (b) b.textContent = body || "";
    if (typeof dlg.showModal === "function") dlg.showModal();
  }

  document.addEventListener("click", function(e){
    var btn = e.target.closest("button[data-modal]");
    if (btn) {
      var id = btn.getAttribute("data-modal");
      var dlg = document.getElementById(id);
      openDialog(dlg, btn.getAttribute("data-title") || "Detail", btn.getAttribute("data-body") || "");
      return;
    }

    var closeBtn = e.target.closest("button[data-close]");
    if (closeBtn) {
      var dlg = closeBtn.closest("dialog");
      if (dlg) dlg.close();
      return;
    }

    var copyBtn = e.target.closest("button[data-copy]");
    if (copyBtn) {
      var dlg = copyBtn.closest("dialog");
      var b = dlg ? dlg.querySelector("[data-body]") : null;
      var txt = b ? b.textContent : "";
      if (!txt) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).catch(function(){});
      }
      return;
    }
  });

  document.addEventListener("keydown", function(e){
    if (e.key === "Escape") {
      document.querySelectorAll("dialog[open]").forEach(function(d){ d.close(); });
    }
  });
})();
</script>

<script>
(function(){
  function fetchJson(url){
    return fetch(url, { credentials: "same-origin" }).then(function(r){ return r.json(); });
  }

  function fillModules(modSel, items, selectedId){
    modSel.innerHTML = '<option value="0">Select Module</option>';
    (items || []).forEach(function(it){
      var opt = document.createElement("option");
      opt.value = String(it.id);
      opt.textContent = it.module_name;
      if (selectedId && Number(it.id) === Number(selectedId)) opt.selected = true;
      modSel.appendChild(opt);
    });
  }

  function loadModulesByApp(appId, selectedId){
    var modSel = document.getElementById("form_module_id");
    if (!modSel) return;
    if (!appId || Number(appId) <= 0) {
      fillModules(modSel, [], 0);
      return;
    }
    var url = "contents.php?action=modules&app_id=" + encodeURIComponent(appId);
    fetchJson(url).then(function(j){
      if (!j || !j.ok) { fillModules(modSel, [], 0); return; }
      fillModules(modSel, j.items || [], selectedId);
    }).catch(function(){
      fillModules(modSel, [], 0);
    });
  }

  document.addEventListener("DOMContentLoaded", function(){
    var appSel = document.getElementById("form_app_id");
    var modSel = document.getElementById("form_module_id");
    if (!appSel || !modSel) return;

    var selectedModule = Number(modSel.getAttribute("data-selected") || "0");
    loadModulesByApp(appSel.value, selectedModule);

    appSel.addEventListener("change", function(){
      loadModulesByApp(appSel.value, 0);
    });
  });
})();
</script>
</body>
</html>
