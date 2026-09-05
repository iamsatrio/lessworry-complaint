@extends('layouts.app')
@section('title','Verifikasi Email')
@section('content')
<div style="max-width:520px;margin:0 auto">
  <div class="eyebrow">Keamanan akun</div>
  <h1>Verifikasi email dulu</h1>
  <p class="lede">
    Password sementara diberikan lewat orang, dan bisa terbaca siapa saja di perjalanannya.
    Sebelum kamu memasang password baru, kami perlu memastikan akun ini benar milikmu.
  </p>

  @if(session('kirim_gagal'))
    <div class="err">
      <b>Surat gagal dikirim</b>
      <p style="margin:6px 0 0">
        Ini masalah di sisi sistem, bukan di akunmu. Coba tombol kirim ulang di bawah;
        kalau tetap gagal, hubungi Admin.
      </p>
    </div>
  @endif

  <div class="card">
    <p style="margin:0 0 14px">
      Tautan verifikasi dikirim ke
      <b style="font-family:var(--mono)">{{ $user->emailTersamar() }}</b>.
      Buka tautan di dalamnya, lalu kamu akan diantar ke halaman ganti password.
    </p>
    <p class="hint" style="margin:0 0 18px">
      Tautannya berlaku {{ \App\Services\PengirimVerifikasiEmail::UMUR_MENIT }} menit dan hanya bisa dipakai sekali.
      Kalau sudah lewat, minta yang baru di bawah.
    </p>

    <form method="POST" action="{{ route('verification.send') }}">
      @csrf
      <button style="width:100%">Kirim Ulang Tautan</button>
    </form>
    <p class="hint" style="margin:12px 0 0">
      Bisa diminta paling banyak {{ \App\Services\PengirimVerifikasiEmail::BATAS }} kali dalam 10 menit.
    </p>
  </div>

  <div class="panel">
    <b>Alamat di atas bukan alamatmu, atau kotak suratnya tidak bisa dibuka?</b>
    Hubungi Admin. Admin bisa memperbaiki alamat emailmu, atau menandai akunmu terverifikasi
    secara manual — itu dipakai untuk akun bersama seperti Kasir, Produksi, dan Kurir.
  </div>

  <form method="POST" action="{{ route('logout') }}" style="text-align:center;margin-top:18px">
    @csrf
    <button class="ghost" style="padding:8px 18px;min-height:38px;font-size:13.5px">Keluar</button>
  </form>
</div>
@endsection
