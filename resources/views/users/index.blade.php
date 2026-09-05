@extends('layouts.app')
@section('title','Pengguna')
@section('content')
<div class="eyebrow">Pengelolaan tim</div>
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
  <div>
    <h1>Pengguna sistem</h1>
    <p class="lede">{{ $users->where('is_active',true)->count() }} aktif dari {{ $users->count() }} akun.</p>
  </div>
  <a href="{{ route('users.create') }}" class="btn">Tambah Pengguna</a>
</div>

@if(session('temporary_password'))
  @php $tp = session('temporary_password'); @endphp
  <div class="card" style="border-color:var(--yellow);background:var(--warn-soft)">
    <div class="eyebrow" style="color:#9a7400">Password sementara — tampil sekali saja</div>
    <p style="margin:0 0 12px">Serahkan ke <b>{{ $tp['name'] }}</b>. Dia wajib menggantinya saat pertama masuk.</p>
    <dl class="kv" style="background:var(--surface);padding:14px;border-radius:10px">
      <dt>Email</dt><dd style="font-family:var(--mono)">{{ $tp['email'] }}</dd>
      <dt>Password</dt><dd style="font-family:var(--mono);font-size:16px;font-weight:700">{{ $tp['password'] }}</dd>
    </dl>
    <p class="hint">Catat sekarang. Muat ulang halaman ini dan password itu hilang — sistem tidak menyimpannya dalam bentuk terbaca.</p>
  </div>
@endif

<div class="card flush hide-sm">
  <table>
    <thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th>Outlet / Divisi</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @foreach($users as $u)
    <tr @if(!$u->is_active) style="opacity:.55" @endif>
      <td><b class="display">{{ $u->name }}</b>
        @if($u->must_change_password)<div class="muted small">belum ganti password</div>@endif
      </td>
      <td class="muted small" style="font-family:var(--mono)">{{ $u->email }}</td>
      <td>{{ $u->roleLabel() }}</td>
      <td class="muted small">{{ $u->outlet?->name ?? ($u->division ? config('complaint.divisions.'.$u->division) : '—') }}</td>
      <td>
        <span class="badge {{ $u->is_active ? "b-close" : "w-ringan" }}">{{ $u->is_active ? 'Aktif' : 'Nonaktif' }}</span>
      </td>
      <td style="text-align:right;white-space:nowrap">
        <a href="{{ route('users.edit',$u) }}" class="small">Ubah</a>
        <form method="POST" action="{{ route('users.reset-password',$u) }}" style="display:inline;margin-left:10px"
              onsubmit="return confirm('Setel ulang password {{ $u->name }}? Password lamanya langsung tidak berlaku.')">
          @csrf<button class="ghost" style="padding:6px 12px;min-height:34px;font-size:12.5px">Reset Password</button>
        </form>
      </td>
    </tr>
    @endforeach
    </tbody>
  </table>
</div>

<div class="cards">
  @foreach($users as $u)
  <div class="ccard" @if(!$u->is_active) style="opacity:.55" @endif>
    <div class="hd">
      <span class="nm">{{ $u->name }}</span>
      <span class="badge {{ $u->is_active ? "b-close" : "w-ringan" }}">{{ $u->is_active ? 'Aktif' : 'Nonaktif' }}</span>
    </div>
    <div class="meta">{{ $u->roleLabel() }} · {{ $u->email }}</div>
    <a href="{{ route('users.edit',$u) }}" class="btn ghost" style="padding:8px 16px;min-height:38px">Ubah</a>
  </div>
  @endforeach
</div>
@endsection
