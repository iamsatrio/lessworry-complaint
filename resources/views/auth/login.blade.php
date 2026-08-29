@extends('layouts.app')
@section('title','Masuk')
@section('content')
<div style="max-width:420px;margin:9vh auto 0">
  <div style="text-align:center;margin-bottom:26px">
    <div class="display" style="font-size:32px;font-weight:800;color:var(--teal-deep);letter-spacing:-.03em">Less Worry</div>
    <div class="muted" style="font-size:13px;letter-spacing:.1em;text-transform:uppercase;font-weight:600;margin-top:6px">
      Complaint Management
    </div>
  </div>
  <div class="card">
    <form method="POST" action="{{ route('login') }}">
      @csrf
      <label for="email">Email</label>
      <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
      <label for="password">Password</label>
      <input id="password" type="password" name="password" required autocomplete="current-password">
      <div style="margin-top:22px"><button style="width:100%">Masuk</button></div>
    </form>
  </div>
  <p class="muted small" style="text-align:center">Lupa password? Hubungi supervisor outletmu.</p>
</div>
@if(session('bersihkan_semua_draft'))
<script>
/* Petugas keluar dengan sengaja: draft complaint miliknya tidak boleh
   tertinggal di perangkat outlet untuk petugas berikutnya.

   Hanya dijalankan sesudah keluar, bukan setiap kali halaman masuk terbuka:
   sesi yang habis di tengah pengisian juga mendarat di sini, dan draft itu
   justru harus selamat. */
try{
  for (let i = localStorage.length - 1; i >= 0; i--) {
    const k = localStorage.key(i);
    if (k && k.indexOf('lw_complaint_draft') === 0) localStorage.removeItem(k);
  }
}catch(e){}
</script>
@endif
@endsection
