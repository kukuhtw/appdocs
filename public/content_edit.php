<?php
// public/content_edit.php
declare(strict_types=1);
session_start();

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../lib/helpers.php";

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
}

function qp_from(array $base, array $over = []): string {
  foreach ($over as $k => $v) $base[$k] = $v;
  return http_build_query($base);
}

function render_bold(string $text): string {
  if ($text === "") return "";
  $safe = h($text);
  return (string)preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);
}

function now_dt(): string {
  return date("Y-m-d H:i:s");
}

$action = (string)($_GET["action"] ?? "create");
$id = get_int("id");

$return = (string)($_GET["return"] ?? "contents.php");
$return_qs = (string)($_GET["return_qs"] ?? "");

$app_id_prefill = get_int("app_id");
$module_id_prefill = get_int("module_id");

$apps = $pdo->query("SELECT id, app_name FROM appsname ORDER BY app_name ASC")->fetchAll();

$modules = [];
$moduleAppId = 0;
if ($action === "edit" && $id > 0) {
  $moduleAppId = 0;
} else if ($app_id_prefill > 0) {
  $moduleAppId = $app_id_prefill;
}

if ($moduleAppId > 0) {
  $st = $pdo->prepare("SELECT id, module_name FROM moduleapps WHERE app_id=? ORDER BY module_name ASC");
  $st->execute([$moduleAppId]);
  $modules = $st->fetchAll();
}

$row = null;
if ($action === "edit" && $id > 0) {
  $st = $pdo->prepare("
    SELECT c.*, a.app_name, m.module_name
    FROM contentcode c
    JOIN appsname a ON a.id = c.app_id
    JOIN moduleapps m ON m.id = c.module_id
    WHERE c.id=?
    LIMIT 1
  ");
  $st->execute([$id]);
  $row = $st->fetch() ?: null;

  if ($row) {
    $moduleAppId = (int)$row["app_id"];
    $st = $pdo->prepare("SELECT id, module_name FROM moduleapps WHERE app_id=? ORDER BY module_name ASC");
    $st->execute([$moduleAppId]);
    $modules = $st->fetchAll();
  }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $mode = post_str("mode");

  $app_id = (int)post_str("app_id");
  $module_id = (int)post_str("module_id");
  $file_path = trim(post_str("file_path"));
  $title = trim(post_str("title"));
  $summarycode = trim(post_str("summarycode"));
  $content = (string)($_POST["content"] ?? "");

  if ($app_id <= 0) {
    flash_set("App is required");
    redirect("content_edit.php?action=" . h($action) . "&id=" . (int)$id);
  }

  if ($module_id <= 0) {
    flash_set("Module is required");
    redirect("content_edit.php?action=" . h($action) . "&id=" . (int)$id . "&app_id=" . (int)$app_id);
  }

  if ($file_path === "") {
    flash_set("File path is required");
    redirect("content_edit.php?action=" . h($action) . "&id=" . (int)$id . "&app_id=" . (int)$app_id . "&module_id=" . (int)$module_id);
  }

  if ($title === "") $title = basename(str_replace("\\", "/", $file_path));

  try {
    if ($mode === "create") {
      $pdo->beginTransaction();

      $st = $pdo->prepare("
        INSERT INTO contentcode
          (app_id, module_id, file_path, title, summarycode, content, fs_exists, fs_checked_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, 0, NOW(), ?)
      ");
      $st->execute([
        $app_id,
        $module_id,
        $file_path,
        $title,
        ($summarycode === "" ? null : $summarycode),
        $content,
        ($content !== "" ? now_dt() : null),
      ]);

      $newId = (int)$pdo->lastInsertId();

      $pdo->commit();

      flash_set("Content created. ID " . $newId);

      $to = $return;
      if ($return_qs !== "") $to .= "?" . $return_qs;
      redirect($to);
    }

    if ($mode === "update") {
      $content_id = (int)post_str("id");

      $st0 = $pdo->prepare("SELECT content, updated_at FROM contentcode WHERE id=? LIMIT 1");
      $st0->execute([$content_id]);
      $prev = $st0->fetch();
      if (!$prev) {
        flash_set("Content not found");
        redirect($return . ($return_qs !== "" ? "?" . $return_qs : ""));
      }

      $prevContent = (string)($prev["content"] ?? "");
      $contentChanged = ($content !== $prevContent);

      $pdo->beginTransaction();

      if ($contentChanged) {
        $st = $pdo->prepare("
          UPDATE contentcode
          SET
            app_id=?,
            module_id=?,
            file_path=?,
            title=?,
            summarycode=?,
            content=?,
            updated_at=NOW()
          WHERE id=?
        ");
        $st->execute([
          $app_id,
          $module_id,
          $file_path,
          $title,
          ($summarycode === "" ? null : $summarycode),
          $content,
          $content_id
        ]);
      } else {
        $st = $pdo->prepare("
          UPDATE contentcode
          SET
            app_id=?,
            module_id=?,
            file_path=?,
            title=?,
            summarycode=?
          WHERE id=?
        ");
        $st->execute([
          $app_id,
          $module_id,
          $file_path,
          $title,
          ($summarycode === "" ? null : $summarycode),
          $content_id
        ]);
      }

      $pdo->commit();

      flash_set($contentChanged ? "Content updated. updated_at refreshed" : "Metadata updated. content unchanged");

      $to = $return;
      if ($return_qs !== "") $to .= "?" . $return_qs;
      redirect($to);
    }

    flash_set("Invalid mode");
    redirect($return . ($return_qs !== "" ? "?" . $return_qs : ""));
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    $msg = $e->getMessage();
    if (stripos($msg, "uq_app_file") !== false) {
      flash_set("Duplicate file_path for this app. Please use a unique file_path");
    } else {
      flash_set("DB error: " . $msg);
    }

    redirect("content_edit.php?action=" . h($action) . "&id=" . (int)$id . "&app_id=" . (int)$app_id . "&module_id=" . (int)$module_id);
  }
}

$flash = flash_get();

$val_app_id = 0;
$val_module_id = 0;
$val_file_path = "";
$val_title = "";
$val_summarycode = "";
$val_content = "";

if ($row) {
  $val_app_id = (int)$row["app_id"];
  $val_module_id = (int)$row["module_id"];
  $val_file_path = (string)($row["file_path"] ?? "");
  $val_title = (string)($row["title"] ?? "");
  $val_summarycode = (string)($row["summarycode"] ?? "");
  $val_content = (string)($row["content"] ?? "");
} else {
  $val_app_id = $app_id_prefill > 0 ? $app_id_prefill : 0;
  $val_module_id = $module_id_prefill > 0 ? $module_id_prefill : 0;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= $row ? "Edit Content" : "Add Content" ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{--bg:#0b1220;--card:#111b2e;--card2:#0f1a2c;--text:#e6eefc;--muted:#a6b3cc;--line:#22314f;--primary:#6ea8ff;--danger:#ff6b6b;--shadow:0 12px 30px rgba(0,0,0,.35);--radius:14px}
    *{box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;margin:0;background:linear-gradient(180deg,#070c16,var(--bg));color:var(--text)}
    a{color:var(--primary);text-decoration:none}
    a:hover{text-decoration:underline}
    .container{max-width:1200px;margin:0 auto;padding:18px}
    .topbar{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px;gap:10px;flex-wrap:wrap}
    .title{font-size:18px;font-weight:700}
    .small{font-size:12px;color:var(--muted);line-height:1.6}
    .card{background:linear-gradient(180deg,var(--card),var(--card2));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:14px;margin-bottom:14px}
    .flash{border-left:6px solid var(--primary);background:rgba(110,168,255,.10)}
    .flash.err{border-left-color:var(--danger);background:rgba(255,107,107,.10)}
    label{font-size:12px;color:var(--muted);display:block;margin:8px 0 6px}
    input[type=text],select,textarea{width:100%;padding:10px 10px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--text);outline:none}
    textarea{min-height:260px;resize:vertical;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:12px;line-height:1.55}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
    .w-260{width:260px}
    .btn{border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--text);padding:10px 12px;border-radius:12px;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
    .btn.primary{border-color:rgba(110,168,255,.5);background:rgba(110,168,255,.12)}
    .btn.danger{border-color:rgba(255,107,107,.5);background:rgba(255,107,107,.12)}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
    .preview{border:1px solid var(--line);border-radius:12px;padding:12px;background:rgba(255,255,255,.02);font-size:13px;line-height:1.7}
    @media (max-width:900px){
      .grid,.grid3{grid-template-columns:1fr}
      .w-260{width:100%}
    }
  </style>
</head>
<body>
<div class="container">
  <div class="topbar">
    <div>
      <div class="title"><?= $row ? "Edit Content" : "Add Content" ?></div>
      <div class="small">
        This screen edits rows in contentcode.
        Exports use the selected rows from Export Selector.
        Print view uses export_print.php.
      </div>
    </div>
    <div class="row">
      <a class="btn" href="<?= h($return . ($return_qs !== "" ? "?" . $return_qs : "")) ?>">Back</a>
    </div>
  </div>

  <?php if ($flash !== ""): ?>
    <?php $isErr = stripos($flash, "error") !== false || stripos($flash, "invalid") !== false || stripos($flash, "required") !== false || stripos($flash, "DB") !== false; ?>
    <div class="card flash <?= $isErr ? "err" : "" ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="post">
      <input type="hidden" name="mode" value="<?= $row ? "update" : "create" ?>">
      <?php if ($row): ?>
        <input type="hidden" name="id" value="<?= (int)$row["id"] ?>">
      <?php endif; ?>
      <input type="hidden" name="return" value="<?= h($return) ?>">
      <input type="hidden" name="return_qs" value="<?= h($return_qs) ?>">

      <div class="grid3">
        <div>
          <label>App</label>
          <select name="app_id" class="w-260" onchange="location.href='content_edit.php?<?= h(qp_from($_GET, ["app_id" => ""])) ?>'+this.value">
            <option value="0">Select</option>
            <?php foreach ($apps as $a): ?>
              <?php $aid = (int)$a["id"]; ?>
              <option value="<?= $aid ?>" <?= $val_app_id === $aid ? "selected" : "" ?>><?= h((string)$a["app_name"]) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="small">Changing app reloads module list</div>
        </div>

        <div>
          <label>Module</label>
          <select name="module_id" class="w-260">
            <option value="0">Select</option>
            <?php foreach ($modules as $m): ?>
              <?php $mid = (int)$m["id"]; ?>
              <option value="<?= $mid ?>" <?= $val_module_id === $mid ? "selected" : "" ?>><?= h((string)$m["module_name"]) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="small">Modules filtered by selected app</div>
        </div>

        <div>
          <label>Title</label>
          <input type="text" name="title" value="<?= h($val_title) ?>" placeholder="Displayed title for print view">
          <div class="small">Fallback uses basename(file_path)</div>
        </div>
      </div>

      <div class="grid" style="margin-top:10px">
        <div>
          <label>File path</label>
          <input type="text" name="file_path" value="<?= h($val_file_path) ?>" placeholder="Example: src/routes/contentPlanningRoutes.tsx">
          <div class="small">Unique per app. uq_app_file(app_id, file_path)</div>
        </div>

        <div>
          <label>Summary</label>
          <input type="text" name="summarycode" value="<?= h($val_summarycode) ?>" placeholder="Short summary. Used in summary view">
          <div class="small">Supports **bold** markers</div>
        </div>
      </div>

      <div class="grid" style="margin-top:10px">
        <div>
          <label>Content</label>
          <textarea name="content" placeholder="Paste code or documentation here"><?= h($val_content) ?></textarea>
          <div class="small">updated_at changes only when this field changes</div>
        </div>

        <div>
          <label>Summary preview</label>
          <div class="preview"><?= render_bold($val_summarycode) ?></div>

          <label style="margin-top:12px">Tips for export</label>
          <div class="small">
            Use Export Selector.
            Pick the row.
            Save selection.
            Create export.
            Print view.
            Save as PDF.
          </div>
        </div>
      </div>

      <div class="row" style="margin-top:12px">
        <button class="btn primary" type="submit">Save</button>
        <a class="btn" href="<?= h($return . ($return_qs !== "" ? "?" . $return_qs : "")) ?>">Cancel</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
