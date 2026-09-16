@extends('layouts.app')
@section('title','Ubah Pengguna')
{{-- Halaman ini memuat DUA form: ubah data akun (users/_form) dan tandai
     terverifikasi. Keduanya memulangkan galatnya ke halaman yang sama, jadi
     petanya memuat kolom dari keduanya. (API-88 Bagian B) --}}
@section('galat-anchor'){!! json_encode([
  'name'      => 'name',
  'email'     => 'email',
  'role'      => 'role',
  'outlet_id' => 'outlet_id',
  'division'  => 'division',
  'is_active' => 'is_active',
  'reason'    => 'reason',
]) !!}@endsection
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
      <select id="is_active" name="is_active"
        @error('is_active') aria-invalid="true" aria-describedby="is_active-error" @enderror>
        <option value="1" @selected(old('is_active', $user->is_active))>Aktif</option>
        <option value="0" @selected(!old('is_active', $user->is_active))>Nonaktif</option>
      </select>
      @error('is_active')<p class="err-field" id="is_active-error">{{ $message }}</p>@enderror
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

  {{-- Verifikasi email (API-35). Jalan keluar saat kotak suratnya tidak ada
       atau tidak bisa dibuka — tanpa ini alamat yang salah = akun mati. --}}
  <div class="card">
    <h2>Verifikasi email</h2>
    @if($user->hasVerifiedEmail())
      <p style="margin:10px 0 0">
        <span class="badge b-close">Terverifikasi</span>
        <span class="muted small" style="margin-left:8px">{{ $user->email_verified_at->format('d M Y H:i') }}</span>
      </p>
    @else
      <p style="margin:10px 0 14px">
        <span class="badge w-ringan">Belum terverifikasi</span>
      </p>
      <p class="hint" style="margin:0 0 14px">
        Selama belum terverifikasi, {{ $user->name }} hanya bisa membuka halaman verifikasi —
        belum bisa mengganti password, belum bisa memakai sistem.
        Tandai manual hanya kalau kotak suratnya memang tidak ada: akun bersama
        (Kasir, Produksi, Kurir), atau alamat yang ternyata salah dan tidak bisa diperbaiki.
        <b>Ini melemahkan pengaman, jadi alasannya wajib dan tercatat.</b>
      </p>
      <form method="POST" action="{{ route('users.verify-email',$user) }}"
            data-konfirmasi="Tandai {{ $user->name }} terverifikasi tanpa lewat email? Tindakan ini tercatat atas namamu."
            onsubmit="return confirm(this.dataset.konfirmasi)">
        @csrf
        <label for="reason">Alasan <span class="req">*</span></label>
        <textarea id="reason" name="reason" rows="3" required
                  placeholder="Contoh: akun bersama kasir outlet, tidak punya kotak surat sendiri."
                  @error('reason') aria-invalid="true" aria-describedby="reason-error" @enderror>{{ old('reason') }}</textarea>
        @error('reason')<p class="err-field" id="reason-error">{{ $message }}</p>@enderror
        <div style="margin-top:16px"><button>Tandai Terverifikasi</button></div>
      </form>
    @endif
  </div>

  <div class="card">
    <h2>Jejak audit akun</h2>
    @forelse($jejak as $baris)
      <div style="padding:12px 0;border-bottom:1px solid var(--line)">
        <div><b class="display">{{ $baris->actionLabel() }}</b></div>
        <div class="muted small">
          {{ $baris->actorLabel() }} · {{ $baris->created_at?->format('d M Y H:i') }}
        </div>
        @if($baris->detail)<div class="small" style="margin-top:6px">{{ $baris->detail }}</div>@endif
        @if($baris->reason)<div class="small" style="margin-top:6px">Alasan: {{ $baris->reason }}</div>@endif
      </div>
    @empty
      <p class="muted small" style="margin:10px 0 0">Belum ada tindakan admin yang tercatat untuk akun ini.</p>
    @endforelse
  </div>
</div>
@endsection
