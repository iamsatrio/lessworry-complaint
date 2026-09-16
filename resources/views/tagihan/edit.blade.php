@extends('layouts.app')
@section('title','Ubah Tagihan')
{{-- Butir ringkasan galat di layout menautkan ke kolomnya lewat peta ini.
     Nama kolom datang dari TagihanRequest; id-nya dari markup di _form. --}}
@section('galat-anchor'){!! json_encode([
  'nama'              => 'nama',
  'jumlah'            => 'jumlah',
  'pengulangan'       => 'pengulangan',
  'jatuh_tempo_bulan' => 'jatuh_tempo_bulan',
  'jatuh_tempo_hari'  => 'jatuh_tempo_hari',
  'outlet_id'         => 'outlet_id',
]) !!}@endsection
@section('content')
<div style="max-width:560px;margin:0 auto">
  <div class="eyebrow">Tagihan bulanan</div>
  <h1>Ubah tagihan</h1>
  <p class="lede">Riwayat pembayaran {{ $tagihan->nama }} tidak ikut berubah.</p>
  <div class="card">
    <form method="POST" action="{{ route('tagihan.update', $tagihan) }}">
      @csrf @method('PUT')
      @include('tagihan._form')
      <div style="margin-top:24px;display:flex;gap:12px">
        <button>Simpan Perubahan</button>
        <a href="{{ route('tagihan.index') }}" class="btn ghost">Batal</a>
      </div>
    </form>
  </div>
</div>
@endsection
