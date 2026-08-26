@extends('layouts.app')
@section('title','Tambah Pengguna')
@section('content')
<div style="max-width:560px;margin:0 auto">
  <div class="eyebrow">Pengelolaan tim</div>
  <h1>Tambah pengguna</h1>
  <p class="lede">Sistem membuatkan password sementara. Serahkan ke orangnya, dan dia wajib menggantinya saat pertama masuk.</p>
  <div class="card">
    <form method="POST" action="{{ route('users.store') }}">
      @csrf
      @include('users._form')
      <div style="margin-top:24px;display:flex;gap:12px">
        <button>Buat Akun</button>
        <a href="{{ route('users.index') }}" class="btn ghost">Batal</a>
      </div>
    </form>
  </div>
</div>
@endsection
