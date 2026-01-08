<?php
// public/modules.php
declare(strict_types=1);
session_start();
require __DIR__ . "/../config/db.php";
require __DIR__ . "/../lib/helpers.php";

$action = $_GET["action"] ?? "list";
$id = get_int("id");

$apps = $pdo->query("SELECT id, app_name FROM appsname ORDER BY app_name ASC")->fetchAll();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $app_id = (int)post_str("app_id");
  $module_name = post_str("module_name");

  if ($app_id <= 0 || $module_name === "") {
    flash_set("App and module are required");
    redirect("modules.php");
  }

  if (post_str("mode") === "create") {
    $st = $pdo->prepare("INSERT INTO moduleapps(app_id, module_name) VALUES (?, ?)");
    $st->execute([$app_id, $module_name]);
    flash_set("Module created");
    redirect("modules.php");
  }

  if (post_str("mode") === "update") {
    $module_id = (int)post_str("id");
    $st = $pdo->prepare("UPDATE moduleapps SET app_id=?, module_name=? WHERE id=?");
    $st->execute([$app_id, $module_name, $module_id]);
    flash_set("Module updated");
    redirect("modules.php");
  }
}

if ($action === "delete" && $id > 0) {
  $st = $pdo->prepare("DELETE FROM moduleapps WHERE id=?");
  $st->execute([$id]);
  flash_set("Module deleted");
  redirect("modules.php");
}

$rows = $pdo->query("
  SELECT m.*, a.app_name
  FROM moduleapps m
  JOIN appsname a ON a.id = m.app_id
  ORDER BY a.app_name ASC, m.module_name ASC
")->fetchAll();

$editRow = null;
if ($action === "edit" && $id > 0) {
  $st = $pdo->prepare("SELECT * FROM moduleapps WHERE id=?");
  $st->execute([$id]);
  $editRow = $st->fetch() ?: null;
}

$flash = flash_get();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Modules</title>
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
    .sub{
      margin-top:6px;
      color:var(--muted);
      font-size:13px;
      line-height:1.6;
      max-width:860px
    }
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
    label{font-size:12px;color:var(--muted);display:block;margin:8px 0 6px}
    select,input[type=text]{
      width:100%;
      padding:10px;
      border-radius:12px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.03);
      color:var(--text);
      outline:none
    }
    .grid{display:grid;grid-template-columns:340px 1fr;gap:12px;align-items:end}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid var(--line);padding:10px;vertical-align:top;font-size:13px}
    th{color:var(--muted);font-weight:600}
    .pill{
      display:inline-block;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.03);
      color:var(--muted);
      font-size:12px
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
    @media (max-width:900px){
      .grid{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
<div class="container">

  <div class="topbar">
    <div class="brand">
      <h1>Modules</h1>
      <div class="sub">
        Purpose: map modules to an app.
        Modules are used for organizing content and for filtering in the Contents page.
        The special module name <span class="pill">_fs</span> is used by filesystem import.
      </div>
      <div class="row" style="margin-top:10px">
        <span class="pill">Create</span>
        <span class="pill">Edit</span>
        <span class="pill">Delete</span>
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
        stripos($flash, "required") !== false ||
        stripos($flash, "DB") !== false ||
        stripos($flash, "gagal") !== false;
    ?>
    <div class="card flash <?= $isErr ? "err" : "" ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="card">
    <h3 style="margin:0 0 12px 0"><?= $editRow ? "Edit Module" : "Create Module" ?></h3>

    <form method="post">
      <input type="hidden" name="mode" value="<?= $editRow ? "update" : "create" ?>">
      <?php if ($editRow): ?>
        <input type="hidden" name="id" value="<?= (int)$editRow["id"] ?>">
      <?php endif; ?>

      <div class="grid">
        <div>
          <label>App</label>
          <select name="app_id">
            <option value="0">Select an app</option>
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
          <label>Module name</label>
          <input type="text" name="module_name" value="<?= h($editRow["module_name"] ?? "") ?>" placeholder="Example: auth, billing, dashboard">
        </div>
      </div>

      <div class="row" style="margin-top:12px">
        <button class="btn primary" type="submit">Save</button>
        <?php if ($editRow): ?>
          <a class="btn" href="modules.php">Cancel</a>
        <?php endif; ?>
      </div>

      <div class="help note">
        This page updates database records only.
        It does not modify any physical codebase files.
      </div>
    </form>
  </div>

  <div class="card">
    <h3 style="margin:0 0 12px 0">All Modules</h3>

    <table>
      <thead>
        <tr>
          <th style="width:90px">ID</th>
          <th style="width:260px">App</th>
          <th>Module</th>
          <th style="width:220px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r["id"] ?></td>
            <td><?= h($r["app_name"]) ?></td>
            <td><?= h($r["module_name"]) ?></td>
            <td class="row" style="gap:12px">
              <a href="modules.php?action=edit&id=<?= (int)$r["id"] ?>">Edit</a>
              <a href="modules.php?action=delete&id=<?= (int)$r["id"] ?>" onclick="return confirm('Delete this module')">Delete</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="help note">
      If you see many modules called <span class="pill">_fs</span>, that is expected.
      It is created automatically when you scan a filesystem.
    </div>
  </div>

</div>
</body>
</html>
