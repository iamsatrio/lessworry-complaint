<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Complaint') — Less Worry</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  /* Diambil dari lessworry.id */
  --mint:#f0f8f7;
  --mint-deep:#e3f2f1;
  --surface:#ffffff;
  --teal:#259d91;
  --teal-deep:#147c72;
  --yellow:#ffc928;
  --line:#ddebea;
  --ink:#1b2b28;
  --muted:#6b8481;

  --danger:#d64545;
  --danger-soft:#fdeaea;
  --ok:#2f9e6b;
  --ok-soft:#e8f6ef;
  --warn-soft:#fff6da;

  --r:14px;
  --r-pill:999px;
  --shadow:0 1px 2px rgba(20,124,114,.05), 0 8px 24px -12px rgba(20,124,114,.18);

  --display:"Montserrat",system-ui,sans-serif;
  --body:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  --mono:ui-monospace,SFMono-Regular,Menlo,monospace;
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--mint);color:var(--ink);font:16px/1.6 var(--body)}
a{color:var(--teal-deep);text-decoration:none}
a:hover{color:var(--teal)}
:focus-visible{outline:3px solid var(--teal);outline-offset:2px;border-radius:6px}

h1,h2,h3,.display{font-family:var(--display);letter-spacing:-.02em;color:var(--ink)}
h1{font-size:30px;font-weight:800;margin:0 0 4px;line-height:1.15}
h2{font-size:20px;font-weight:700;margin:0}
h3{font-size:16px;font-weight:700;margin:0}

/* Eyebrow: menandai konteks bagian, meniru penanda di lessworry.id */
.eyebrow{font-family:var(--display);font-weight:700;font-size:12px;letter-spacing:.12em;
  text-transform:uppercase;color:var(--teal);display:flex;align-items:center;gap:8px;margin-bottom:12px}
.eyebrow::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--yellow);flex:none}

/* ---------- Shell ---------- */
header.top{background:var(--surface);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20}
.topin{max-width:1240px;margin:0 auto;padding:0 22px;display:flex;align-items:center;gap:26px;min-height:66px}
.brand{font-family:var(--display);font-weight:800;font-size:19px;line-height:1;color:var(--teal-deep);white-space:nowrap}
.brand span{display:block;font-size:10.5px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-top:3px}
nav{display:flex;gap:4px;flex:1;overflow-x:auto;scrollbar-width:none}
nav::-webkit-scrollbar{display:none}
nav a{font-family:var(--display);font-weight:600;font-size:14px;color:var(--muted);
  padding:9px 14px;border-radius:var(--r-pill);white-space:nowrap;min-height:40px;display:flex;align-items:center}
nav a:hover{background:var(--mint);color:var(--teal-deep)}
nav a.active{background:var(--mint-deep);color:var(--teal-deep)}
nav a.cta{background:var(--teal);color:#fff}
nav a.cta:hover{background:var(--teal-deep);color:#fff}
.who{text-align:right;font-size:13px;color:var(--muted);line-height:1.35;white-space:nowrap}
.who b{display:block;font-family:var(--display);font-weight:700;font-size:14px;color:var(--ink)}

main{max-width:1240px;margin:0 auto;padding:30px 22px 80px}
.lede{color:var(--muted);font-size:14.5px;margin:0 0 26px}

/* ---------- Kartu ---------- */
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:22px;margin-bottom:18px;box-shadow:var(--shadow)}
.card.flush{padding:0;overflow:hidden}
.card-h{padding:18px 22px;border-bottom:1px solid var(--line)}
.grid{display:grid;gap:16px;align-items:start}
.g4{grid-template-columns:repeat(auto-fit,minmax(190px,1fr))}
.g2{grid-template-columns:repeat(auto-fit,minmax(330px,1fr))}

/* ---------- Statistik ---------- */
.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:20px;box-shadow:var(--shadow)}
.stat .n{font-family:var(--display);font-size:36px;font-weight:800;letter-spacing:-.03em;line-height:1;color:var(--teal-deep)}
.stat .l{font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin-top:8px}
.stat.danger{background:var(--danger-soft);border-color:#f6cfcf}
.stat.danger .n{color:var(--danger)}
.stat.ok .n{color:var(--ok)}
.stat.accent{background:var(--warn-soft);border-color:#f3dfa4}
.stat.accent .n{color:#9a7400;font-size:24px}

/* ---------- Tabel ---------- */
table{width:100%;border-collapse:collapse;font-size:14.5px}
th{font-family:var(--display);text-align:left;font-size:11px;font-weight:700;letter-spacing:.09em;
  text-transform:uppercase;color:var(--muted);padding:12px 16px;border-bottom:1px solid var(--line);background:var(--mint)}
td{padding:14px 16px;border-bottom:1px solid var(--line);vertical-align:middle}
tr:last-child td{border-bottom:0}
tbody tr:hover td{background:var(--mint)}
.tix{font-family:var(--mono);font-size:13px;font-weight:600;white-space:nowrap}

/* ---------- Lencana ---------- */
.badge{display:inline-flex;align-items:center;font-family:var(--display);font-weight:700;font-size:11.5px;
  padding:5px 11px;border-radius:var(--r-pill);letter-spacing:.02em;white-space:nowrap}
.b-baru{background:var(--mint-deep);color:var(--teal-deep)}
.b-ditangani{background:var(--warn-soft);color:#8a6800}
.b-menunggu_pelanggan{background:#efeaf7;color:#5b3fa0}
.b-selesai{background:var(--ok-soft);color:#1e6b48}
.b-ditolak{background:#eef1f0;color:#647471}
/* Prioritas sengaja lebih sunyi daripada status: hanya urgent yang berteriak. */
.p-urgent{background:var(--danger);color:#fff}
.p-high{background:#ffe9d4;color:#9a4a00}
.p-medium{background:#eef1f0;color:#5c6b68}
.p-low{background:#f4f7f6;color:#8b9a97}

/* ---------- Meteran SLA (elemen tanda tangan) ---------- */
.sla{min-width:126px}
.sla .t{font-family:var(--display);font-size:12px;font-weight:700;margin-bottom:5px;color:var(--muted)}
.sla .track{height:6px;border-radius:var(--r-pill);background:var(--line);overflow:hidden}
.sla .track i{display:block;height:100%;border-radius:var(--r-pill);background:var(--teal);transition:width .3s}
.sla.warn .t{color:#9a7400} .sla.warn .track i{background:var(--yellow)}
.sla.late .t{color:var(--danger)} .sla.late .track i{background:var(--danger)}
.sla.done .t{color:var(--ok)} .sla.done .track i{background:var(--ok)}

/* ---------- Form ---------- */
label{display:block;font-family:var(--display);font-size:12px;font-weight:700;letter-spacing:.05em;
  text-transform:uppercase;color:var(--muted);margin:18px 0 7px}
label .req{color:var(--danger)}
input,select,textarea{width:100%;background:var(--surface);border:1.5px solid var(--line);color:var(--ink);
  border-radius:10px;padding:12px 14px;font:16px/1.5 var(--body);min-height:48px}
input:hover,select:hover,textarea:hover{border-color:#c4ded9}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 4px rgba(37,157,145,.14)}
textarea{min-height:112px;resize:vertical}
.hint{font-size:13px;color:var(--muted);margin:7px 0 0}

button,.btn{font-family:var(--display);font-weight:700;font-size:14.5px;background:var(--teal);color:#fff;
  border:0;border-radius:var(--r-pill);padding:13px 24px;min-height:48px;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:background .15s}
button:hover,.btn:hover{background:var(--teal-deep);color:#fff}
.btn.ghost,button.ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--teal-deep)}
.btn.ghost:hover,button.ghost:hover{background:var(--mint);border-color:var(--teal);color:var(--teal-deep)}
.row{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end}
.row>*{flex:1;min-width:172px}
.row .shrink{flex:0 0 auto;min-width:0}

/* ---------- Pesan ---------- */
.flash,.err{border-radius:var(--r);padding:14px 18px;margin-bottom:20px;font-size:14.5px;border:1px solid}
.flash{background:var(--ok-soft);border-color:#bfe5d3;color:#1e6b48}
.flash.warn{background:var(--warn-soft);border-color:#f3dfa4;color:#8a6800}
.err{background:var(--danger-soft);border-color:#f3c4c4;color:#a32e2e}
.err b{font-family:var(--display)}
.err ul{margin:6px 0 0 18px;padding:0}

/* ---------- Kosong ---------- */
.empty{text-align:center;padding:44px 20px}
.empty .mark{width:52px;height:52px;border-radius:50%;background:var(--mint-deep);display:grid;place-items:center;
  margin:0 auto 14px;font-size:24px}
.empty h3{margin-bottom:6px}
.empty p{color:var(--muted);font-size:14px;margin:0 0 18px}

/* ---------- Lain ---------- */
.muted{color:var(--muted)}
.small{font-size:13px}
.bar{height:8px;background:var(--mint-deep);border-radius:var(--r-pill);overflow:hidden;margin-top:6px}
.bar i{display:block;height:100%;background:var(--teal);border-radius:var(--r-pill)}
.meter-row{margin-bottom:14px}
.meter-row .lab{display:flex;justify-content:space-between;align-items:baseline;font-size:14px}
.meter-row .lab b{font-family:var(--display);font-size:15px}
.kv{display:grid;grid-template-columns:132px 1fr;gap:10px 16px;font-size:14.5px;margin:0}
.kv dt{color:var(--muted)}
.kv dd{margin:0;font-weight:500}
.tl{border-left:2px solid var(--line);margin-left:7px;padding-left:20px}
.tl .item{position:relative;padding-bottom:18px}
.tl .item:last-child{padding-bottom:0}
.tl .item::before{content:"";position:absolute;left:-27px;top:4px;width:10px;height:10px;border-radius:50%;
  background:var(--surface);border:2.5px solid var(--teal)}
.panel{background:var(--mint);border:1px solid var(--line);border-radius:10px;padding:15px;margin-top:10px;font-size:14px}
.panel.bad{background:var(--danger-soft);border-color:#f3c4c4}
.panel.good{background:var(--ok-soft);border-color:#bfe5d3}

details.link-editor>summary{list-style:none;cursor:pointer;font-family:var(--display);font-weight:700;
  font-size:13.5px;color:var(--teal-deep);padding:9px 0;display:flex;align-items:center;gap:7px;min-height:40px}
details.link-editor>summary::-webkit-details-marker{display:none}
details.link-editor>summary::before{content:"+";font-size:15px}
details.link-editor[open]>summary::before{content:"–"}

/* Saringan: terbuka di desktop, dilipat di HP supaya daftar tidak terdorong ke bawah */
details.filters{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);
  margin-bottom:18px;box-shadow:var(--shadow)}
details.filters>summary{list-style:none;cursor:pointer;padding:16px 22px;font-family:var(--display);
  font-weight:700;font-size:14px;color:var(--teal-deep);display:flex;align-items:center;
  justify-content:space-between;min-height:52px}
details.filters>summary::-webkit-details-marker{display:none}
details.filters>summary::after{content:"▾";font-size:13px}
details.filters[open]>summary::after{content:"▴"}
details.filters .body{padding:0 22px 20px}
@media(min-width:821px){
  details.filters>summary{display:none}
  details.filters .body{display:block !important;padding:22px}
}

/* Aksi utama selalu terjangkau di HP — kasir mencatat sambil melayani antrean */
.fab{display:none}
@media(max-width:820px){
  .fab{display:flex;position:fixed;left:16px;right:16px;bottom:16px;z-index:30;
    box-shadow:0 10px 30px -8px rgba(20,124,114,.55)}
  main{padding-bottom:104px}
  nav a.cta{display:none}
}

/* Kartu untuk layar kecil — tabel 9 kolom tidak terpakai di HP */
.cards{display:none}
@media(max-width:820px){
  .hide-sm{display:none !important}
  .cards{display:block}
  h1{font-size:25px}
  main{padding:22px 16px 60px}
  /* Nav pindah ke baris sendiri supaya tidak tertimbun tombol Keluar */
  .topin{padding:0 16px;gap:12px;flex-wrap:wrap;min-height:0;padding-top:12px}
  .brand{flex:1}
  .who{display:none}
  nav{order:3;flex:0 0 100%;width:100%;border-top:1px solid var(--line);
      padding:7px 0;margin-top:10px;gap:2px}
  nav a{padding:8px 12px;font-size:13.5px;min-height:38px}
  .kv{grid-template-columns:1fr;gap:3px 0}
  .kv dt{font-size:12px;margin-top:8px}
}
.ccard{display:block;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);
  padding:16px;margin-bottom:12px;color:inherit;box-shadow:var(--shadow)}
.ccard:hover{border-color:var(--teal);color:inherit}
.ccard .hd{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:9px}
.ccard .nm{font-family:var(--display);font-weight:700;font-size:15px}
.ccard .meta{font-size:13px;color:var(--muted);margin-bottom:11px}

@media(prefers-reduced-motion:reduce){*{transition:none !important;animation:none !important}}
</style>
</head>
<body>
@auth
<script>
/* Kunci draft form intake — terikat pengguna, bukan perangkat. Perangkat
   outlet dipakai bergantian; kunci bersama membuat isian petugas sebelumnya
   muncul di form petugas berikutnya. */
window.LW_DRAFT_KEY = @json('lw_complaint_draft:'.auth()->user()->draftKey());
@if(session('bersihkan_draft'))
/* Complaint benar-benar tersimpan — baru di sini draftnya dibuang. Menghapus
   saat form dikirim membuat isian hilang justru ketika simpannya gagal. */
try{ localStorage.removeItem(window.LW_DRAFT_KEY); }catch(e){}
@endif
</script>
<header class="top">
  <div class="topin">
    <div class="brand">Less Worry<span>Complaint</span></div>
    <nav>
      <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
      <a href="{{ route('complaints.index') }}" class="{{ request()->routeIs('complaints.index') ? 'active' : '' }}">Papan Kerja</a>
      <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">Laporan</a>
      @if(auth()->user()->canManageUsers())
        <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">Pengguna</a>
      @endif
      @if(auth()->user()->canCreateComplaint())
        <a href="{{ route('complaints.create') }}" class="cta">Catat Complaint</a>
      @endif
    </nav>
    <div class="who">
      <b>{{ auth()->user()->name }}</b>
      {{ auth()->user()->roleLabel() }}@if(auth()->user()->outlet) · {{ auth()->user()->outlet->name }}@endif
    </div>
    <form method="POST" action="{{ route('logout') }}" class="shrink">@csrf
      <button class="ghost" style="padding:10px 16px;min-height:42px;font-size:13.5px">Keluar</button>
    </form>
  </div>
</header>
@endauth

<main>
  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if(session('warning'))<div class="flash warn">{{ session('warning') }}</div>@endif
  @if($errors->any())
    <div class="err"><b>Periksa lagi sebelum lanjut</b><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif
  @yield('content')
</main>

@auth
  @if(auth()->user()->canCreateComplaint() && ! request()->routeIs('complaints.create'))
    <a href="{{ route('complaints.create') }}" class="btn fab">Catat Complaint</a>
  @endif
@endauth
</body>
</html>
