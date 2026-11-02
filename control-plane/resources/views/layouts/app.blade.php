<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CloudTunes') · CloudTunes</title>
    <style>
        :root { color-scheme: dark; --bg:#0b1020; --panel:#121a2d; --panel2:#18233a; --line:#283653; --text:#edf2ff; --muted:#9eacc7; --accent:#8bd3ff; --good:#7fe0b0; --warn:#ffd27d; --bad:#ff9a9a; }
        * { box-sizing:border-box; }
        body { margin:0; background:radial-gradient(ellipse at top left,#172847 0,transparent 45%),var(--bg); color:var(--text); font:16px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        a { color:var(--accent); text-decoration:none; } a:hover { text-decoration:underline; }
        .shell { min-height:100vh; }
        .topbar { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem clamp(1rem,4vw,3rem); border-bottom:1px solid var(--line); background:rgba(11,16,32,.86); }
        .brand { color:var(--text); font-size:1.15rem; font-weight:800; letter-spacing:.04em; }
        .nav { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
        .nav form { margin:0; }
        .container { width:min(1100px,calc(100% - 2rem)); margin:2.5rem auto 4rem; }
        h1 { margin:0 0 .4rem; font-size:clamp(1.8rem,4vw,2.5rem); line-height:1.15; }
        h2 { margin:0 0 1rem; font-size:1.15rem; }
        p { color:var(--muted); }
        .lede { margin:.25rem 0 1.5rem; }
        .grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:1rem; margin:1.5rem 0; }
        .panel { background:linear-gradient(145deg,rgba(24,35,58,.95),rgba(18,26,45,.95)); border:1px solid var(--line); border-radius:16px; padding:1.25rem; box-shadow:0 16px 40px rgba(0,0,0,.12); }
        .stat { font-size:2rem; font-weight:750; letter-spacing:-.04em; }
        .muted { color:var(--muted); }
        .row { display:flex; justify-content:space-between; align-items:center; gap:1rem; }
        .stack { display:grid; gap:1rem; }
        .station-list { display:grid; gap:.75rem; }
        .station { display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:1rem; background:rgba(11,16,32,.4); border:1px solid var(--line); border-radius:12px; }
        .station h3 { margin:0 0 .2rem; font-size:1rem; }
        .station p { margin:0; font-size:.9rem; overflow-wrap:anywhere; }
        .badge { display:inline-flex; border:1px solid var(--line); border-radius:999px; padding:.2rem .65rem; color:var(--muted); font-size:.8rem; white-space:nowrap; }
        .badge.good { color:var(--good); border-color:#2d775c; } .badge.warn { color:var(--warn); border-color:#856a32; } .badge.bad { color:var(--bad); border-color:#854242; }
        .notice { padding:.9rem 1rem; border:1px solid var(--line); border-radius:12px; background:rgba(24,35,58,.65); margin:1rem 0; }
        .notice.success { border-color:#2d775c; } .notice.warning { border-color:#856a32; }
        label { display:block; font-weight:650; margin-bottom:.4rem; }
        input { width:100%; background:#0b1020; color:var(--text); border:1px solid #405071; border-radius:9px; padding:.8rem .9rem; font:inherit; }
        input:focus { outline:2px solid var(--accent); outline-offset:2px; }
        .field { margin:1rem 0; }
        .button { display:inline-flex; justify-content:center; align-items:center; border:1px solid #77c8f3; border-radius:9px; padding:.72rem 1rem; background:#8bd3ff; color:#07111e; font:inherit; font-weight:750; cursor:pointer; }
        .button:hover { background:#b4e4ff; text-decoration:none; } .button.secondary { background:transparent; color:var(--text); border-color:var(--line); }
        .actions { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; }
        .empty { text-align:center; padding:2rem 1rem; border:1px dashed #405071; border-radius:12px; }
        .auth-wrap { width:min(460px,calc(100% - 2rem)); margin:8vh auto; }
        .error { color:var(--bad); font-size:.9rem; margin:.35rem 0 0; }
        .footer { color:var(--muted); font-size:.85rem; padding:1.5rem; text-align:center; }
        @media(max-width:760px) { .grid { grid-template-columns:1fr; } .container { margin-top:1.5rem; } .topbar { align-items:flex-start; } .station { align-items:flex-start; flex-direction:column; } .row { align-items:flex-start; flex-direction:column; } }
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="{{ auth()->check() ? route('dashboard') : route('login') }}">◉ CLOUDTUNES</a>
        @auth
            <nav class="nav" aria-label="Main navigation">
                <a href="{{ route('dashboard') }}">Dashboard</a>
                <a href="{{ route('stations.index') }}">Stations</a>
                <span class="muted">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="button secondary" type="submit">Sign out</button>
                </form>
            </nav>
        @endauth
    </header>
    @yield('content')
    <footer class="footer">CloudTunes · Personal radio, on your terms</footer>
</div>
</body>
</html>
