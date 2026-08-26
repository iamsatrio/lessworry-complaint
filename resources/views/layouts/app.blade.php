<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Complaint') — Less Worry</title>
<style>
:root{
  --bg:#0f1115; --panel:#171a21; --panel2:#1e222b; --line:#2a2f3a;
  --text:#e8eaed; --muted:#9aa3b2; --accent:#3b82f6;
  --ok:#10b981; --warn:#f59e0b; --danger:#ef4444;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
a{color:var(--accent);text-decoration:none}
header.top{background:var(--panel);border-bottom:1px solid var(--line);padding:0 20px;display:flex;align-items:center;gap:22px;height:58px;position:sticky;top:0;z-index:10}
.brand{font-weight:700;letter-spacing:-.3px}
.brand small{display:block;font-weight:400;font-size:11px;color:var(--muted)}
nav{display:flex;gap:18px;flex:1}
nav a{color:var(--muted);font-size:14px;padding:6px 0;border-bottom:2px solid transparent}
nav a.active,nav a:hover{color:var(--text);border-color:var(--accent)}
.who{font-size:13px;color:var(--muted);text-align:right}
.who b{color:var(--text);display:block}
main{max-width:1180px;margin:0 auto;padding:26px 20px 60px}
h1{font-size:22px;margin:0 0 4px}
.sub{color:var(--muted);font-size:13px;margin-bottom:22px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:18px;margin-bottom:18px}
.grid{display:grid;gap:14px}
.g4{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
.g2{grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}
.stat{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:16px}
.stat .n{font-size:30px;font-weight:700;letter-spacing:-1px}
.stat .l{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.6px;margin-top:2px}
.stat.danger .n{color:var(--danger)} .stat.ok .n{color:var(--ok)} .stat.warn .n{color:var(--warn)}
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.6px;padding:8px 10px;border-bottom:1px solid var(--line)}
td{padding:10px;border-bottom:1px solid var(--line);vertical-align:top}
tr:last-child td{border-bottom:0}
tr:hover td{background:var(--panel2)}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;border:1px solid}
.b-baru{color:#93c5fd;border-color:#1e40af;background:#1e3a8a33}
.b-ditangani{color:#fcd34d;border-color:#92400e;background:#78350f33}
.b-menunggu_pelanggan{color:#c4b5fd;border-color:#5b21b6;background:#4c1d9533}
.b-selesai{color:#6ee7b7;border-color:#065f46;background:#064e3b33}
.b-ditolak{color:#9aa3b2;border-color:#374151;background:#1f293733}
.p-urgent{color:#fca5a5;border-color:#991b1b;background:#7f1d1d33}
.p-high{color:#fdba74;border-color:#9a3412;background:#7c2d1233}
.p-medium{color:#93c5fd;border-color:#1e40af;background:#1e3a8a33}
.p-low{color:#9aa3b2;border-color:#374151;background:#1f293733}
.overdue{color:var(--danger);font-weight:600}
label{display:block;font-size:12px;color:var(--muted);margin:14px 0 5px;text-transform:uppercase;letter-spacing:.5px}
input,select,textarea{width:100%;background:var(--panel2);border:1px solid var(--line);color:var(--text);border-radius:7px;padding:10px 12px;font:inherit}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent)}
textarea{min-height:96px;resize:vertical}
button,.btn{background:var(--accent);color:#fff;border:0;border-radius:7px;padding:10px 16px;font:inherit;font-weight:600;cursor:pointer;display:inline-block}
button:hover,.btn:hover{filter:brightness(1.1)}
.btn.ghost{background:transparent;border:1px solid var(--line);color:var(--text)}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
.row>*{flex:1;min-width:150px}
.flash{background:#064e3b55;border:1px solid #065f46;color:#6ee7b7;padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
.err{background:#7f1d1d55;border:1px solid #991b1b;color:#fca5a5;padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
.err ul{margin:4px 0 0 18px;padding:0}
.muted{color:var(--muted)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}
.tl{border-left:2px solid var(--line);margin-left:6px;padding-left:16px}
.tl .item{position:relative;padding-bottom:16px}
.tl .item::before{content:"";position:absolute;left:-22px;top:5px;width:9px;height:9px;border-radius:50%;background:var(--accent)}
.kv{display:grid;grid-template-columns:150px 1fr;gap:7px 14px;font-size:14px}
.kv dt{color:var(--muted)}
.kv dd{margin:0}
.bar{height:7px;background:var(--panel2);border-radius:4px;overflow:hidden;margin-top:5px}
.bar i{display:block;height:100%;background:var(--accent)}
.nevira{background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:13px;margin-top:9px;font-size:13px}
.nevira.err-box{border-color:#991b1b}
@media(max-width:640px){nav{gap:12px;font-size:13px}.kv{grid-template-columns:1fr}}
</style>
</head>
<body>
@auth
<header class="top">
  <div class="brand">Less Worry <small>Complaint Management</small></div>
  <nav>
    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
    <a href="{{ route('complaints.index') }}" class="{{ request()->routeIs('complaints.index') ? 'active' : '' }}">Papan Kerja</a>
    @if(auth()->user()->canCreateComplaint())
      <a href="{{ route('complaints.create') }}" class="{{ request()->routeIs('complaints.create') ? 'active' : '' }}">+ Complaint Baru</a>
    @endif
    <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">Laporan</a>
  </nav>
  <div class="who">
    <b>{{ auth()->user()->name }}</b>
    {{ auth()->user()->roleLabel() }}@if(auth()->user()->outlet) · {{ auth()->user()->outlet->name }}@endif
  </div>
  <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn ghost" style="padding:7px 12px">Keluar</button></form>
</header>
@endauth

<main>
  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if($errors->any())
    <div class="err"><b>Ada yang perlu diperbaiki:</b><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif
  @yield('content')
</main>
</body>
</html>
