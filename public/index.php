<?php
// public/index.php
/* 
=============================================================================
Project   : appDocs – App Content Manager
Tagline   : Codebase Documentation Built for AI Code Generation
Author    : Kukuh Tripamungkas Wicaksono (Kukuh TW)
Role      : Software Architect & AI Systems Engineer
Email     : kukuhtw@gmail.com
WhatsApp  : https://wa.me/628129893706
LinkedIn  : https://id.linkedin.com/in/kukuhtw
=============================================================================
*/
declare(strict_types=1);
session_start();
require __DIR__ . "/../lib/helpers.php";

$flash = function_exists("flash_get") ? flash_get() : "";
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>AppDocs Manager</title>
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
      color:var(--text);
      background:linear-gradient(180deg,#070c16,var(--bg));
    }
    a{color:inherit;text-decoration:none}
    .container{max-width:1040px;margin:0 auto;padding:22px}
    .topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap}
    .brand h1{margin:0;font-size:22px}
    .brand .sub{margin-top:6px;color:var(--muted);font-size:13px;line-height:1.5}
    .badge{
      display:inline-block;
      margin-top:10px;
      font-size:12px;
      color:var(--muted);
      border:1px solid var(--line);
      padding:6px 10px;
      border-radius:999px;
      background:rgba(255,255,255,.03);
    }
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px}
    .card{
      border:1px solid var(--line);
      border-radius:var(--radius);
      background:linear-gradient(180deg,var(--card),var(--card2));
      box-shadow:var(--shadow);
      padding:16px;
    }
    .flash{
      border-left:6px solid var(--primary);
      background:rgba(110,168,255,.10);
      color:var(--text);
    }
    .flash.err{
      border-left-color:var(--danger);
      background:rgba(255,107,107,.10);
    }
    .sectionTitle{margin:0 0 10px 0;font-size:15px}
    .muted{color:var(--muted);font-size:13px;line-height:1.6;margin:0}
    .links{display:grid;gap:10px;margin-top:12px}
    .link{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.03);
      border-radius:14px;
      padding:12px 12px;
    }
    .link:hover{border-color:rgba(110,168,255,.55);background:rgba(110,168,255,.08)}
    .link .left{display:flex;flex-direction:column;gap:4px}
    .link .title{font-weight:700;font-size:14px}
    .link .desc{color:var(--muted);font-size:12px}
    .link .arrow{
      border:1px solid var(--line);
      border-radius:999px;
      padding:8px 10px;
      color:var(--muted);
      background:rgba(255,255,255,.02);
      font-size:12px;
    }
    .note{
      margin-top:14px;
      border:1px dashed rgba(110,168,255,.45);
      background:rgba(110,168,255,.08);
      border-radius:14px;
      padding:12px 12px;
      color:var(--text);
    }
    .note strong{color:var(--text)}
    .split{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
    .pill{
      display:inline-block;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.03);
      color:var(--muted);
      font-size:12px;
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
        <h1>AppDocs Manager</h1>
        <div class="sub">
          A lightweight internal tool to register app modules, store source code snapshots in a database,
          generate AI summaries, validate filesystem existence, and export structured documentation as PDF.
          <br><br>
          The exported PDF is designed to be consumed by AI code generators,
          so the AI understands your real codebase structure, file hierarchy,
          module boundaries, and implementation patterns.
        </div>
        <span class="badge">appdocs</span>
      </div>

      <div class="split">
        <span class="pill">Database first</span>
        <span class="pill">AI ready PDF</span>
        <span class="pill">Tree aware docs</span>
        <span class="pill">Filesystem sync metadata</span>
      </div>
    </div>

    <?php if ($flash !== ""): ?>
      <?php
        $isErr =
          stripos($flash, "error") !== false ||
          stripos($flash, "failed") !== false ||
          stripos($flash, "gagal") !== false ||
          stripos($flash, "DB") !== false;
      ?>
      <div class="card flash <?= $isErr ? "err" : "" ?>" style="margin-top:16px">
        <?= h($flash) ?>
      </div>
    <?php endif; ?>

    <div class="grid">
      <div class="card">
        <h2 class="sectionTitle">What this tool does</h2>
        <p class="muted">
          AppDocs is a documentation and code catalog tool.
          It stores file content in the database so your codebase can be analyzed,
          summarized, and exported in a consistent structure.
          <br><br>
          The generated PDF acts as a context package for AI code generators,
          helping them produce code that matches your existing folder tree,
          naming conventions, architecture layers, and legacy patterns.
        </p>

        <div class="note">
          <strong>Important</strong><br>
          Saving edits in Content Manager updates the database only<br>
          It does not modify physical files in your repository
        </div>

        <div class="split" style="margin-top:12px">
          <span class="pill">Apps = root paths</span>
          <span class="pill">Modules = logical grouping</span>
          <span class="pill">Contents = DB snapshots</span>
          <span class="pill">PDF = AI context</span>
        </div>
      </div>

      <div class="card">
        <h2 class="sectionTitle">Quick actions</h2>

        <div class="links">
          <a class="link" href="apps.php">
            <div class="left">
              <div class="title">Manage Apps</div>
              <div class="desc">Define root_path and sync filesystem metadata</div>
            </div>
            <div class="arrow">Open</div>
          </a>

          <a class="link" href="modules.php">
            <div class="left">
              <div class="title">Manage Modules</div>
              <div class="desc">Group files by responsibility and architecture layer</div>
            </div>
            <div class="arrow">Open</div>
          </a>

          <a class="link" href="contents.php">
            <div class="left">
              <div class="title">Manage Contents</div>
              <div class="desc">Store code snapshots and AI generated summaries</div>
            </div>
            <div class="arrow">Open</div>
          </a>

          <a class="link" href="export_pdf.php">
            <div class="left">
              <div class="title">Export Print to PDF</div>
              <div class="desc">Generate AI ready documentation for the full code tree</div>
            </div>
            <div class="arrow">Open</div>
          </a>

          <a class="link" href="exports.php">
            <div class="left">
              <div class="title">Export Selected Print to PDF</div>
              <div class="desc">Create curated AI context for specific modules or files</div>
            </div>
            <div class="arrow">Open</div>
          </a>
        </div>
      </div>
    </div>

    <div class="card" style="margin-top:14px">
      <h2 class="sectionTitle">How PDF export helps AI code generation</h2>
      <p class="muted">
        The PDF export is not just for human reading.
        It is structured so AI code generators can infer
        directory layout, module responsibility, dependencies,
        and historical implementation style.
        <br><br>
        This reduces hallucination and helps AI generate patches,
        refactors, or new features that fit naturally into your codebase.
      </p>
      <div class="split" style="margin-top:10px">
        <span class="pill">Preserve tree structure</span>
        <span class="pill">Expose module boundaries</span>
        <span class="pill">Guide AI code output</span>
      </div>
    </div>
  </div>
</body>
</html>
