{{--
  Penolakan yang tetap terlihat seperti bagian dari sistem.

  Pembatasannya sendiri benar dan dipertahankan apa adanya — kasir memang tidak
  boleh melihat data pengguna. Yang salah hanya cara menolaknya: halaman putih
  bertuliskan "403 Forbidden", tanpa header, tanpa navigasi, tanpa bahasa
  Indonesia, dan tanpa jalan kembali selain tombol back peramban. (API-38 #9)
--}}
@extends('layouts.app')
@section('title','Tidak terbuka untuk peranmu')
@section('content')
<div style="max-width:560px;margin:7vh auto 0">
  <div class="card">
    <div class="empty">
      <div class="mark">🔒</div>
      <h3>Halaman ini tidak terbuka untuk peranmu</h3>
      {{-- Pesan bawaan exception sengaja TIDAK dicetak: policy Laravel
           membalas "This action is unauthorized." dan pesan abort() lain bisa
           menyebut nama kolom atau rute. Yang perlu diketahui pembacanya hanya
           bahwa ini pembatasan, bukan kerusakan. --}}
      <p>Bukan karena ada yang rusak — akunmu memang tidak diberi akses ke bagian ini.</p>
      @auth
        <p class="muted small" style="margin:0 0 18px">
          Kamu masuk sebagai {{ auth()->user()->name }} ({{ auth()->user()->roleLabel() }}).
          Kalau kamu memang perlu membukanya, minta admin mengubah peranmu.
        </p>
        <a class="btn" href="{{ route('complaints.index') }}">Kembali ke Papan Kerja</a>
      @else
        <a class="btn" href="{{ route('login') }}">Masuk dulu</a>
      @endauth
    </div>
  </div>
</div>
@endsection
