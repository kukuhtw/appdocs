<?php
// public/apps.php
declare(strict_types=1);
session_start();

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../lib/helpers.php";

$action = (string)($_GET["action"] ?? "list");
$id = get_int("id");

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

ALTER TABLE contentcode
  ADD UNIQUE KEY uq_app_file (app_id, file_path);

-- optional
ALTER TABLE moduleapps
  ADD UNIQUE KEY uq_app_module (app_id, module_name);

-- IMPORTANT
-- updated_at should NOT be ON UPDATE CURRENT_TIMESTAMP
-- make it DATETIME and update it only when content changed
ALTER TABLE contentcode
  ADD COLUMN updated_at DATETIME NULL DEFAULT NULL;
*/

function norm_sep(string $p): string {
  $p = str_replace("\\", "/", $p);
  $p = preg_replace("~/{2,}~", "/", $p);
  return rtrim((string)$p, "/");
}

function safe_join(string $root, string $rel): string {
  $root = norm_sep($root);
  $rel = ltrim(norm_sep($rel), "/");
  return $root . "/" . $rel;
}

function rel_from_full(string $root, string $full): string {
  $rootN = norm_sep($root);
  $fullN = norm_sep($full);
  if (stripos($fullN, $rootN . "/") === 0) return substr($fullN, strlen($rootN) + 1);
  return $fullN;
}

function sha256_file_safe(string $fullPath): ?string {
  if (!is_file($fullPath)) return null;
  $h = @hash_file("sha256", $fullPath);
  return $h !== false ? (string)$h : null;
}

function dt_from_ts(int $ts): string {
  return date("Y-m-d H:i:s", $ts);
}

function is_allowed_ext(string $path): bool {
  $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

  $allow = [
    // backend
    "php","phtml","inc","py","sql","rs","","java",

    // frontend
    "html","htm","js","ts","tsx","jsx",

    // docs config
    "md","txt","json","yml","yaml",
  ];


  return in_array($ext, $allow, true);
}

function read_file_limited(string $full, int $maxBytes = 400_000): string {
  $sz = @filesize($full);
  if ($sz !== false && $sz > $maxBytes) {
    $fh = @fopen($full, "rb");
    if (!$fh) return "";
    $data = fread($fh, $maxBytes);
    fclose($fh);
    return is_string($data) ? $data : "";
  }
  $data = @file_get_contents($full);
  return $data !== false ? (string)$data : "";
}

function ensure_fs_module(PDO $pdo, int $appId, string $moduleName = "_fs"): int {
  $st = $pdo->prepare("SELECT id FROM moduleapps WHERE app_id=? AND module_name=? LIMIT 1");
  $st->execute([$appId, $moduleName]);
  $row = $st->fetch();
  if ($row && isset($row["id"])) return (int)$row["id"];

  $st = $pdo->prepare("INSERT INTO moduleapps(app_id, module_name) VALUES (?, ?)");
  $st->execute([$appId, $moduleName]);
  return (int)$pdo->lastInsertId();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $app_name = post_str("app_name");
  $root_path = trim(post_str("root_path"));

  if ($app_name === "") {
    flash_set("App name is required");
    redirect("apps.php");
  }

  if ($root_path !== "" && !is_dir($root_path)) {
    flash_set("Root path not found on server");
    $mode = post_str("mode");
    if ($mode === "update") {
      $app_id = (int)post_str("id");
      redirect("apps.php?action=edit&id=" . $app_id);
    }
    redirect("apps.php");
  }

  $safeRoot = $root_path !== "" ? $root_path : null;

  if (post_str("mode") === "create") {
    $st = $pdo->prepare("INSERT INTO appsname(app_name, root_path) VALUES (?, ?)");
    $st->execute([$app_name, $safeRoot]);
    flash_set("App created");
    redirect("apps.php");
  }

  if (post_str("mode") === "update") {
    $app_id = (int)post_str("id");
    $st = $pdo->prepare("UPDATE appsname SET app_name=?, root_path=? WHERE id=?");
    $st->execute([$app_name, $safeRoot, $app_id]);
    flash_set("App updated");
    redirect("apps.php");
  }
}

if ($action === "delete" && $id > 0) {
  $st = $pdo->prepare("DELETE FROM appsname WHERE id=?");
  $st->execute([$id]);
  flash_set("App deleted");
  redirect("apps.php");
}

if (($action === "scanfs" || $action === "syncfs") && $id > 0) {
  $st = $pdo->prepare("SELECT * FROM appsname WHERE id=?");
  $st->execute([$id]);
  $app = $st->fetch();

  if (!$app) {
    flash_set("App not found");
    redirect("apps.php");
  }

  $rootRaw = trim((string)($app["root_path"] ?? ""));
  if ($rootRaw === "" || !is_dir($rootRaw)) {
    flash_set("Root path missing or invalid");
    redirect("apps.php");
  }

  $appId = (int)$app["id"];
  $rootNorm = norm_sep($rootRaw);

  try {
    $pdo->beginTransaction();

    $moduleId = ensure_fs_module($pdo, $appId, "_fs");

    if ($action === "scanfs") {
      $existsMap = [];
      $st2 = $pdo->prepare("SELECT file_path FROM contentcode WHERE app_id=?");
      $st2->execute([$appId]);
      foreach ($st2->fetchAll() as $r) {
        $fp = trim((string)($r["file_path"] ?? ""));
        if ($fp !== "") $existsMap[$fp] = true;
      }

      $inserted = 0;
      $skipped = 0;
      $errors = 0;

      $ins = $pdo->prepare("
        INSERT INTO contentcode
          (app_id, module_id, file_path, title, summarycode, content, fs_exists, fs_mtime, fs_size, fs_hash, fs_checked_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
      ");

      $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootRaw, FilesystemIterator::SKIP_DOTS)
      );

      foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) continue;

        $fullRaw = (string)$fileInfo->getPathname();
        $fullNorm = norm_sep($fullRaw);

        if (!is_allowed_ext($fullNorm)) continue;

        $rel = rel_from_full($rootNorm, $fullNorm);
        $rel = norm_sep($rel);

        if ($rel === "" || isset($existsMap[$rel])) {
          $skipped++;
          continue;
        }

        $mtime = (int)$fileInfo->getMTime();
        $size = (int)$fileInfo->getSize();
        $hash = sha256_file_safe($fullRaw);

        $content = read_file_limited($fullRaw, 400_000);
        if ($content === "") {
          $skipped++;
          continue;
        }

        $title = basename($rel);

        try {
          $ins->execute([
            $appId,
            $moduleId,
            $rel,
            $title,
            null,
            $content,
            1,
            dt_from_ts($mtime),
            $size,
            $hash,
          ]);
          $existsMap[$rel] = true;
          $inserted++;
        } catch (Throwable $e) {
          $errors++;
        }
      }

      $pdo->commit();
      flash_set("Scan FS done. inserted {$inserted}. skipped {$skipped}. errors {$errors}. module _fs id {$moduleId}");
      redirect("apps.php");
    }

    if ($action === "syncfs") {
      // REMARK: Include fs_mtime + fs_size for fallback change detection when fs_hash is null.
      $st2 = $pdo->prepare("SELECT id, file_path, fs_hash, fs_mtime, fs_size FROM contentcode WHERE app_id=?");
      $st2->execute([$appId]);
      $rows = $st2->fetchAll();

      $scanned = 0;
      $ok = 0;
      $missing = 0;
      $changed = 0;
      $updatedContent = 0;

      $updNoChange = $pdo->prepare("
        UPDATE contentcode
        SET
          fs_exists=?,
          fs_mtime=?,
          fs_size=?,
          fs_hash=?,
          fs_checked_at=NOW()
        WHERE id=?
      ");

      $updChange = $pdo->prepare("
        UPDATE contentcode
        SET
          content=?,
          fs_exists=?,
          fs_mtime=?,
          fs_size=?,
          fs_hash=?,
          fs_checked_at=NOW(),
          updated_at=NOW()
        WHERE id=?
      ");

      foreach ($rows as $r) {
        $cid = (int)$r["id"];
        $rel = trim((string)($r["file_path"] ?? ""));
        if ($rel === "") continue;

        $scanned++;

        $full = safe_join($rootNorm, $rel);

        if (!is_file($full)) {
          $missing++;
          $updNoChange->execute([0, null, null, null, $cid]);
          continue;
        }

        $mtime = @filemtime($full);
        $size = @filesize($full);
        $hash = sha256_file_safe($full);

        // REMARK: Normalize DB values for reliable comparison.
        $prevHash = trim((string)($r["fs_hash"] ?? ""));
        $prevMtime = (string)($r["fs_mtime"] ?? "");
        $prevSizeRaw = $r["fs_size"] ?? null;

        $newMtimeStr = $mtime ? dt_from_ts((int)$mtime) : null;
        $newSizeInt = ($size !== false) ? (int)$size : null;

        $isChanged = false;

        // REMARK: Primary detection by hash when available.
        if ($hash !== null) {
          if ($prevHash === "") $isChanged = true;
          else if (hash_equals($prevHash, $hash) === false) $isChanged = true;
        } else {
          // REMARK: Fallback detection when hashing fails.
          $prevSizeInt = ($prevSizeRaw === null) ? null : (int)$prevSizeRaw;

          if ($prevSizeInt !== null && $newSizeInt !== null && $prevSizeInt !== $newSizeInt) {
            $isChanged = true;
          } else if ($prevMtime !== "" && $newMtimeStr !== null && $prevMtime !== $newMtimeStr) {
            $isChanged = true;
          } else if ($prevHash === "") {
            // REMARK: No prior hash, hash now missing, still treat as changed once.
            $isChanged = true;
          }
        }

        if ($isChanged) {
          $content = read_file_limited($full, 400_000);
          if ($content === "") {
            // REMARK: Update FS metadata only, keep existing content.
            $updNoChange->execute([
              1,
              $newMtimeStr,
              $newSizeInt,
              $hash,
              $cid
            ]);
            $changed++;
            continue;
          }

          $updChange->execute([
            $content,
            1,
            $newMtimeStr,
            $newSizeInt,
            $hash,
            $cid
          ]);

          $changed++;
          $updatedContent++;
          continue;
        }

        $updNoChange->execute([
          1,
          $newMtimeStr,
          $newSizeInt,
          $hash,
          $cid
        ]);
        $ok++;
      }

      $pdo->commit();
      flash_set("Sync FS done. scanned {$scanned}. ok {$ok}. changed {$changed}. content_updated {$updatedContent}. missing {$missing}");
      redirect("apps.php");
    }

    $pdo->rollBack();
    flash_set("Invalid action");
    redirect("apps.php");
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set("Process failed: " . $e->getMessage());
    redirect("apps.php");
  }
}

$apps = $pdo->query("SELECT * FROM appsname ORDER BY app_name ASC")->fetchAll();

$editRow = null;
if ($action === "edit" && $id > 0) {
  $st = $pdo->prepare("SELECT * FROM appsname WHERE id=?");
  $st->execute([$id]);
  $editRow = $st->fetch() ?: null;
}

$flash = flash_get();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Apps</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root{
      --bg:#0b1220;
      --card:#111b2e;
      --card2:#0f1a2c;
      --text:#e6eefc;
      --muted:#a6b3cc;
      --line:#22314f;
      --primary:#6ea8ff;
      --danger:#ff6b6b;
      --warn:#ffd166;
      --shadow:0 12px 30px rgba(0,0,0,.35);
      --radius:16px
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:Arial,Helvetica,sans-serif;
      background:linear-gradient(180deg,#070c16,var(--bg));
      color:var(--text)
    }
    a{color:var(--primary);text-decoration:none}
    a:hover{text-decoration:underline}
    .container{max-width:1200px;margin:0 auto;padding:22px}
    .topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:16px}
    .brand h1{margin:0;font-size:22px}
    .sub{margin-top:6px;color:var(--muted);font-size:13px;line-height:1.6;max-width:860px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn{
      border:1px solid var(--line);
      background:rgba(255,255,255,.04);
      color:var(--text);
      padding:10px 14px;
      border-radius:12px;
      cursor:pointer;
      display:inline-flex;
      align-items:center;
      gap:8px
    }
    .btn.primary{border-color:rgba(110,168,255,.55);background:rgba(110,168,255,.14)}
    .btn.danger{border-color:rgba(255,107,107,.55);background:rgba(255,107,107,.12)}
    .card{
      background:linear-gradient(180deg,var(--card),var(--card2));
      border:1px solid var(--line);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:16px;
      margin-bottom:14px
    }
    .flash{border-left:6px solid var(--primary);background:rgba(110,168,255,.10)}
    .flash.err{border-left-color:var(--danger);background:rgba(255,107,107,.10)}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    label{font-size:12px;color:var(--muted);display:block;margin:8px 0 6px}
    input[type=text]{
      width:100%;
      padding:10px;
      border-radius:12px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.03);
      color:var(--text);
      outline:none
    }
    .help{
      margin-top:10px;
      color:var(--muted);
      font-size:12px;
      line-height:1.6
    }
    .note{
      border-left:4px solid rgba(110,168,255,.55);
      padding-left:10px;
      margin-top:10px
    }
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid var(--line);padding:10px;vertical-align:top;font-size:13px}
    th{color:var(--muted);font-weight:600}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
    .actions a{margin-right:10px;font-size:12px}
    .pill{
      display:inline-block;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.03);
      color:var(--muted);
      font-size:12px
    }
    @media (max-width:900px){
      .grid{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
<div class="container">

  <div class="topbar">
    <div class="brand">
      <h1>Apps</h1>
      <div class="sub">
        Purpose: register apps, configure root paths, run filesystem import, run filesystem sync.
        Filesystem actions read from disk. Database stores snapshots. Disk files stay unchanged.
      </div>
      <div class="row" style="margin-top:10px">
        <span class="pill">App</span>
        <span class="pill">Root path</span>
        <span class="pill">Scan FS</span>
        <span class="pill">Sync FS</span>
      </div>
    </div>
    <div class="row">
      <a class="btn" href="index.php">Back</a>
    </div>
  </div>

  <?php if ($flash !== ""): ?>
    <?php
      $isErr =
        stripos($flash, "error") !== false ||
        stripos($flash, "failed") !== false ||
        stripos($flash, "not found") !== false ||
        stripos($flash, "invalid") !== false ||
        stripos($flash, "DB") !== false ||
        stripos($flash, "gagal") !== false;
    ?>
    <div class="card flash <?= $isErr ? "err" : "" ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="card">
    <h3 style="margin:0 0 12px 0"><?= $editRow ? "Edit App" : "Create App" ?></h3>

    <form method="post">
      <input type="hidden" name="mode" value="<?= $editRow ? "update" : "create" ?>">
      <?php if ($editRow): ?>
        <input type="hidden" name="id" value="<?= (int)$editRow["id"] ?>">
      <?php endif; ?>

      <div class="grid">
        <div>
          <label>App name</label>
          <input type="text" name="app_name" value="<?= h($editRow["app_name"] ?? "") ?>" placeholder="Example: appdocs">
        </div>

        <div>
          <label>Root path on server</label>
          <input
            type="text"
            name="root_path"
            value="<?= h($editRow["root_path"] ?? "") ?>"
            placeholder="Example: C:\xampp\htdocs\your_repo   or   /var/www/your_repo"
          >
        </div>
      </div>

      <div class="row" style="margin-top:12px">
        <button class="btn primary" type="submit">Save</button>
        <?php if ($editRow): ?>
          <a class="btn" href="apps.php">Cancel</a>
        <?php endif; ?>
      </div>

      <div class="help note">
        Root path is used by Scan FS and Sync FS.
        Scan FS inserts new files into database.
        Sync FS refreshes fs metadata. Sync FS updates content when file changed.
      </div>

      <div class="help note">
        Content edits in Content Manager update database only.
        Physical codebase stays unchanged.
      </div>
    </form>
  </div>

  <div class="card">
    <h3 style="margin:0 0 12px 0">Registered Apps</h3>

    <table>
      <thead>
        <tr>
          <th style="width:80px">ID</th>
          <th style="width:260px">App name</th>
          <th>Root path</th>
          <th style="width:360px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($apps as $a): ?>
          <?php
            $aid = (int)$a["id"];
            $root = (string)($a["root_path"] ?? "");
            $rootOk = ($root !== "" && is_dir($root));
          ?>
          <tr>
            <td><?= $aid ?></td>
            <td><?= h((string)$a["app_name"]) ?></td>
            <td class="mono">
              <?= h($root) ?>
              <?php if ($root === ""): ?>
                <div class="help">Root path empty</div>
              <?php else: ?>
                <div class="help"><?= $rootOk ? "Path exists" : "Path missing" ?></div>
              <?php endif; ?>
            </td>
            <td class="actions">
              <a href="apps.php?action=edit&id=<?= $aid ?>">Edit</a>
              <a href="apps.php?action=scanfs&id=<?= $aid ?>" onclick="return confirm('Scan root path. Insert new content rows')">Scan FS</a>
              <a href="apps.php?action=syncfs&id=<?= $aid ?>" onclick="return confirm('Sync metadata. Update content on change')">Sync FS</a>
              <a href="apps.php?action=delete&id=<?= $aid ?>" onclick="return confirm('Delete this app')">Delete</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="help note">
      Scan FS reads allowed extensions only.
      Large files are truncated to 400 KB for storage.
      Module "_fs" is used for imported files.
    </div>
  </div>

</div>
</body>
</html>
