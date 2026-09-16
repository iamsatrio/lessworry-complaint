@extends('layouts.app')
@section('title','Ganti Password')
{{-- Butir ringkasan galat di layout menautkan ke kolomnya lewat peta ini.
     `password_confirmation` tidak punya aturan sendiri — ketidakcocokannya
     dilaporkan atas nama `password`, jadi tautannya mendarat di kolom
     password baru, bukan di kolom ulangannya. (API-86 #1, #2) --}}
@section('galat-anchor'){!! json_encode([
  'current_password' => 'cp',
  'password'         => 'np',
]) !!}@endsection
@section('content')
<div style="max-width:460px;margin:0 auto">
  <div class="eyebrow">Keamanan akun</div>
  <h1>Ganti password</h1>
  @if(auth()->user()->must_change_password)
    <p class="lede">Akunmu masih memakai password sementara dari admin. Ganti dulu sebelum memakai sistem.</p>
  @else
    <p class="lede">Pakai password yang tidak kamu pakai di tempat lain.</p>
  @endif

  <div class="card">
    <form method="POST" action="{{ route('password.update') }}">
      @csrf @method('PUT')
      <label for="cp">Password sekarang</label>
      <input id="cp" type="password" name="current_password" required autocomplete="current-password" autofocus
        @error('current_password') aria-invalid="true" aria-describedby="cp-error" @enderror>
      @error('current_password')<p class="err-field" id="cp-error">{{ $message }}</p>@enderror
      <label for="np">Password baru</label>
      <input id="np" type="password" name="password" required autocomplete="new-password"
        @error('password') aria-invalid="true" aria-describedby="np-error" @enderror>
      @error('password')<p class="err-field" id="np-error">{{ $message }}</p>@enderror
      <p class="hint">Minimal 8 karakter, mengandung huruf dan angka.</p>
      <label for="npc">Ulangi password baru</label>
      <input id="npc" type="password" name="password_confirmation" required autocomplete="new-password"
        @error('password_confirmation') aria-invalid="true" aria-describedby="npc-error" @enderror>
      @error('password_confirmation')<p class="err-field" id="npc-error">{{ $message }}</p>@enderror
      <div style="margin-top:22px"><button style="width:100%">Simpan Password Baru</button></div>
    </form>
  </div>

  <div class="panel">
    Perangkat outlet dipakai bergantian. Setelah password diganti, sesi di perangkat lain ikut diputus.
  </div>
</div>
@endsection
