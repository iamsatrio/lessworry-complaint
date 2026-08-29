@extends('layouts.app')
@section('title','Belum tersimpan')
@section('content')
<div style="max-width:560px;margin:7vh auto 0">

  <div class="err" role="alert">
    @if($kembali)
      <b style="font-size:16px">Complaint ini BELUM tersimpan.</b>
      <p style="margin:8px 0 0">Tidak ada satu baris pun yang masuk ke sistem. {{ $pesan }}</p>
    @else
      <b style="font-size:16px">Perubahan tadi BELUM tersimpan.</b>
      <p style="margin:8px 0 0">{{ $pesan }}</p>
    @endif
  </div>

  <div class="card">
    <b>Yang harus kamu lakukan</b>
    <ol style="margin:10px 0 0;padding-left:20px;line-height:1.9">
      @guest<li>Masuk lagi dengan akunmu.</li>@endguest
      @if($kembali)
        <li>Buka Catat Complaint — isian yang tadi kamu ketik masih tersimpan di perangkat ini dan akan ditawarkan kembali.</li>
        <li>Periksa isinya, lalu simpan.</li>
      @else
        <li>Buka lagi halamannya, lalu ulangi langkah yang tadi.</li>
      @endif
    </ol>
    @if($kembali)
      <p class="hint">Isian itu hanya muncul untuk akunmu sendiri, bukan untuk petugas berikutnya yang memakai perangkat ini.</p>
    @endif
    <div style="margin-top:18px">
      @auth
        <a href="{{ $kembali ? route('complaints.create') : route('dashboard') }}" class="btn">
          {{ $kembali ? 'Kembali ke form' : 'Kembali ke Dashboard' }}
        </a>
      @else
        <a href="{{ route('login') }}" class="btn">Masuk lagi</a>
      @endauth
    </div>
  </div>

</div>
@endsection
