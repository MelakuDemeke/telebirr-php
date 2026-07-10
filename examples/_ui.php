<?php

/**
 * Shared UI chrome for the example web pages (self-contained inline CSS).
 */

declare(strict_types=1);

function ui_header(string $title): string
{
    $t = htmlspecialchars($title);
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t}</title>
<style>
  :root {
    --bg: #f4f5f7; --card: #ffffff; --text: #1b1f24; --muted: #6b7280;
    --border: #e5e7eb; --brand: #0aa06e; --brand-d: #078a5e;
    --ok-bg: #e7f7ef; --ok-fg: #0a7a4f; --err-bg: #fdecec; --err-fg: #b42318;
    --shadow: 0 6px 24px rgba(0,0,0,.08);
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #0f1216; --card: #171b21; --text: #e8eaed; --muted: #9aa4b2;
      --border: #262c34; --brand: #12b57e; --brand-d: #0aa06e;
      --ok-bg: #10281f; --ok-fg: #4ade80; --err-bg: #2a1414; --err-fg: #f87171;
      --shadow: 0 6px 24px rgba(0,0,0,.4);
    }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--text);
    font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 24px;
  }
  .card {
    background: var(--card); border: 1px solid var(--border); border-radius: 16px;
    box-shadow: var(--shadow); width: 100%; max-width: 460px; padding: 28px;
  }
  .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
  .brand .dot { width: 30px; height: 30px; border-radius: 8px; background: var(--brand);
    display: grid; place-items: center; color: #fff; font-weight: 700; }
  h1 { font-size: 20px; margin: 0; }
  .sub { color: var(--muted); margin: 4px 0 22px; font-size: 13px; }
  label { display: block; font-weight: 600; font-size: 13px; margin: 14px 0 6px; }
  input {
    width: 100%; padding: 11px 12px; border: 1px solid var(--border); border-radius: 10px;
    background: transparent; color: var(--text); font-size: 15px;
  }
  input:focus { outline: 2px solid var(--brand); border-color: var(--brand); }
  .btn {
    display: block; width: 100%; margin-top: 20px; padding: 12px 16px; border: 0;
    border-radius: 10px; background: var(--brand); color: #fff; font-size: 15px; font-weight: 600;
    cursor: pointer; text-align: center; text-decoration: none;
  }
  .btn:hover { background: var(--brand-d); }
  .btn.secondary { background: transparent; color: var(--brand); border: 1px solid var(--brand); }
  .badge { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px;
    font-weight: 600; font-size: 13px; }
  .badge.ok { background: var(--ok-bg); color: var(--ok-fg); }
  .badge.err { background: var(--err-bg); color: var(--err-fg); }
  dl { margin: 18px 0 0; display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; }
  dt { color: var(--muted); font-size: 13px; }
  dd { margin: 0; font-size: 13px; word-break: break-all; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  .note { margin-top: 18px; padding: 12px; border-radius: 10px; background: var(--bg);
    color: var(--muted); font-size: 12px; }
  details { margin-top: 14px; } summary { cursor: pointer; color: var(--muted); font-size: 13px; }
  pre { overflow-x: auto; background: var(--bg); padding: 12px; border-radius: 10px; font-size: 12px; }
  a.link { color: var(--brand); }
</style>
</head>
<body>
<div class="card">
  <div class="brand"><div class="dot">T</div><h1>{$t}</h1></div>
HTML;
}

function ui_footer(): string
{
    return "</div></body></html>";
}
