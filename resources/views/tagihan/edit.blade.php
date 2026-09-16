@extends('layouts.app')
@section('title','Ubah Tagihan')
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
