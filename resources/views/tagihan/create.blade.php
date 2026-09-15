@extends('layouts.app')
@section('title','Tambah Tagihan')
@section('content')
<div style="max-width:560px;margin:0 auto">
  <div class="eyebrow">Tagihan bulanan</div>
  <h1>Tambah tagihan</h1>
  <p class="lede">Empat isian. Alarmnya menyala sendiri sebelum jatuh tempo — tidak ada yang perlu dijadwalkan.</p>
  <div class="card">
    <form method="POST" action="{{ route('tagihan.store') }}">
      @csrf
      @include('tagihan._form', ['tagihan' => null])
      <div style="margin-top:24px;display:flex;gap:12px">
        <button>Simpan Tagihan</button>
        <a href="{{ route('tagihan.index') }}" class="btn ghost">Batal</a>
      </div>
    </form>
  </div>
</div>
@endsection
