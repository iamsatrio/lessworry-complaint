@extends('layouts.app')
@section('title','Ubah Pengguna')
@section('content')
<div style="max-width:560px;margin:0 auto">
  <div class="eyebrow">Pengelolaan tim</div>
  <h1>{{ $user->name }}</h1>
  <p class="lede" style="font-family:var(--mono);font-size:13px">{{ $user->email }}</p>
  <div class="card">
    <form method="POST" action="{{ route('users.update',$user) }}">
      @csrf @method('PUT')
      @include('users._form')

      <label for="is_active">Status akun</label>
      <select id="is_active" name="is_active">
        <option value="1" @selected(old('is_active', $user->is_active))>Aktif</option>
        <option value="0" @selected(!old('is_active', $user->is_active))>Nonaktif</option>
      </select>
      <p class="hint">
        Akun tidak pernah dihapus, hanya dinonaktifkan — complaint menyimpan siapa yang mencatat dan menutupnya,
        dan jejak itu harus tetap utuh. Akun nonaktif langsung kehilangan akses, termasuk yang sesinya sedang berjalan.
      </p>

      <div style="margin-top:24px;display:flex;gap:12px">
        <button>Simpan Perubahan</button>
        <a href="{{ route('users.index') }}" class="btn ghost">Batal</a>
      </div>
    </form>
  </div>
</div>
@endsection
