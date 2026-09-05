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
            onsubmit="return confirm('Tandai {{ $user->name }} terverifikasi tanpa lewat email? Tindakan ini tercatat atas namamu.')">
        @csrf
        <label for="reason">Alasan <span class="req">*</span></label>
        <textarea id="reason" name="reason" rows="3" required
                  placeholder="Contoh: akun bersama kasir outlet, tidak punya kotak surat sendiri.">{{ old('reason') }}</textarea>
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
