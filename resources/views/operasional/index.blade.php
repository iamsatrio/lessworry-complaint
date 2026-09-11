@extends('layouts.app')
@section('title','Dashboard')
@section('content')
{{-- Outlet yang sedang disaring ikut tertulis di sini: papan yang menyaring
     tanpa mengatakannya membuat angka satu outlet dibaca sebagai angka
     jaringan. --}}
<div class="eyebrow">{{ now()->translatedFormat('l, d F Y') }}@if($outlet) · {{ $outlet->name }}@endif</div>
<h1>Ada yang perlu ditangani?</h1>
<p class="lede">
  {{-- Kalimat ini yang dibaca lebih dulu daripada kartu mana pun. Hari yang
       baik harus bisa diselesaikan di sini, tanpa membaca ke bawah. --}}
  @if(count($alarms) === 0)
    Tidak ada. Papan bersih.
  @else
    {{ count($alarms) }} alarm menyala. Yang sudah dipegang rekan ditandai namanya.
  @endif
</p>

{{-- Saringan hanya muncul kalau memang ada yang bisa dipilih: satu outlet
     berarti tidak ada pilihan, dan kotak pilihan berisi satu nama hanya
     menambah satu hal untuk dibaca tiap pagi. --}}
@if($pilihanOutlet->count() > 1)
<div class="card">
  <form method="GET" class="row">
    <div>
      <label for="outlet">Outlet</label>
      <select id="outlet" name="outlet">
        <option value="">Semua outlet</option>
        @foreach($pilihanOutlet as $pilihan)
          <option value="{{ $pilihan->id }}" @selected($outlet?->id === $pilihan->id)>{{ $pilihan->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="shrink"><button>Terapkan</button></div>
    @if($outlet)
      <div class="shrink"><a class="btn ghost" href="{{ route('operasional') }}">Semua outlet</a></div>
    @endif
  </form>
</div>
@endif

@if(count($alarms) === 0)
  {{-- Keadaan NORMAL, bukan halaman kosong karena datanya belum ada. Kalimatnya
       harus menutup kunjungan pagi, bukan membuat pembacanya mencari lagi. --}}
  <div class="card">
    <div class="empty">
      <div class="mark">✓</div>
      <h3>Tidak ada yang perlu ditangani</h3>
      <p>Tidak ada complaint yang menganggur tanpa pemilik, dan tidak ada yang lewat tenggat.</p>
      <a class="btn ghost" href="{{ route('complaints.index') }}">Buka papan kerja</a>
    </div>
  </div>
@else
  @foreach($alarms as $baris)
    @include('operasional._alarm', ['baris' => $baris])
  @endforeach
@endif
@endsection
