@extends('layouts.app')
@section('title','Papan Kerja')
@section('content')
<div class="eyebrow">Papan Kerja</div>
{{-- $mencari datang dari controller: yang memutuskan "ini pencarian" adalah
     query yang dibangun di sana. Judul harus menyebut apa yang benar-benar
     ditampilkan — kata "terbuka" hanya berlaku saat papan kerja ditampilkan
     apa adanya. (API-38 #1) --}}
<h1>{{ $complaints->total() }} complaint{{ isset($saringan['status']) || $mencari ? '' : ' terbuka' }}</h1>
<p class="lede">
  @if(isset($saringan['status'])) Berstatus "{{ config('complaint.statuses.'.$saringan['status'], $saringan['status']) }}".
  @elseif($mencari) Hasil pencarian "{{ $q }}" — mencakup complaint yang sudah ditutup.
  @else Yang paling dekat tenggat tampil paling atas. @endif
  @if(auth()->user()->isKasir()) Dibatasi outlet {{ auth()->user()->outlet?->name }}. @endif
</p>

{{-- Cari adalah tindakan utama supervisor di halaman ini, bukan tindakan
     lanjutan: kotaknya berdiri sendiri di atas, tidak di balik panel yang
     tertutup. Saringan sisanya tetap boleh terlipat. (API-38 #13) --}}
{{-- Saringan yang ikut terbawa datang dari $saringan — sudah dipastikan
     skalar dan terisi oleh controller. Sebelumnya blok ini memanggil
     request() sendiri, dan `?category[]=x` membuat htmlspecialchars()
     melempar: papan kerja jadi HTTP 500 pada tautan yang disunting tangan.
     (Tinjauan PR #14 nomor 1) --}}
<form method="GET" class="card searchbar">
  @foreach($saringan as $kunci => $nilai)
    <input type="hidden" name="{{ $kunci }}" value="{{ $nilai }}">
  @endforeach
  <label for="q" class="sr-only">Cari complaint</label>
  <input id="q" name="q" value="{{ $q }}" placeholder="Cari nomor tiket, nama, telepon, atau nomor nota">
  <button class="shrink">Cari</button>
  @if($mencari)
    <a class="btn ghost shrink" href="{{ route('complaints.index', $saringan) }}">Hapus</a>
  @endif
</form>

<details class="filters" @if(filled($saringan)) open @endif>
  <summary>Saringan lain</summary>
  <form method="GET" class="row body">
    @if($mencari)<input type="hidden" name="q" value="{{ $q }}">@endif
    {{-- Kanal disaring controller tapi belum punya kolomnya sendiri di sini;
         dibawa sebagai hidden supaya tidak hilang saat saringan lain
         diterapkan. (Tinjauan PR #14 nomor 4) --}}
    @isset($saringan['channel'])<input type="hidden" name="channel" value="{{ $saringan['channel'] }}">@endisset
    <div><label for="fs">Status</label>
      <select id="fs" name="status"><option value="">Semua yang terbuka</option>
        @foreach(config('complaint.statuses') as $k=>$v)
          <option value="{{ $k }}" @selected(($saringan['status'] ?? null)===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
    <div><label for="fc">Kategori</label>
      <select id="fc" name="category"><option value="">Semua</option>
        @foreach(config('complaint.categories') as $k=>$v)
          <option value="{{ $k }}" @selected(($saringan['category'] ?? null)===$k)>{{ $v['label'] }}</option>
        @endforeach
      </select>
    </div>
    <div><label for="fb">Bobot</label>
      <select id="fb" name="bobot"><option value="">Semua</option>
        @foreach(config('complaint.bobot') as $k=>$v)
          <option value="{{ $k }}" @selected(($saringan['bobot'] ?? null)===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
    <div><label for="fl">Layanan</label>
      <select id="fl" name="layanan"><option value="">Semua</option>
        @foreach(config('complaint.layanan') as $k=>$v)
          <option value="{{ $k }}" @selected(($saringan['layanan'] ?? null)===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
    @if(auth()->user()->seesAllOutlets())
    <div><label for="fo">Outlet</label>
      <select id="fo" name="outlet_id"><option value="">Semua</option>
        @foreach($outlets as $o)<option value="{{ $o->id }}" @selected(($saringan['outlet_id'] ?? null)==$o->id)>{{ $o->name }}</option>@endforeach
      </select>
    </div>
    @endif
    <div class="shrink"><button>Terapkan</button></div>
  </form>
</details>

@if($complaints->isEmpty())
  <div class="card">
    <div class="empty">
      <div class="mark">🧺</div>
      <h3>Tidak ada complaint yang cocok</h3>
      @if($mencari)
        <p>Tidak ada complaint dengan "{{ $q }}" — pencarian ini sudah mencakup tiket yang
          sudah ditutup. Periksa lagi ejaan nomor tiket atau nomor notanya.</p>
        <a class="btn ghost" href="{{ route('complaints.index') }}">Kembali ke papan kerja</a>
      @else
        <p>Ubah saringan di atas, atau catat complaint baru kalau ada keluhan yang masuk.</p>
        @if(auth()->user()->canCreateComplaint())
          <a class="btn" href="{{ route('complaints.create') }}">Catat Complaint</a>
        @endif
      @endif
    </div>
  </div>
@else
  <div class="card flush hide-sm">
    <table>
      <thead><tr>
        <th>Tiket</th><th>Pelapor</th><th>Kategori</th><th>Outlet</th>
        <th>Bobot</th><th>Status</th><th>Penanggung Jawab</th><th>Sisa Waktu</th>
      </tr></thead>
      <tbody>
      @foreach($complaints as $complaint)
      <tr>
        <td>
          <a href="{{ route('complaints.show',$complaint) }}" class="tix">{{ $complaint->ticket_number }}</a>
          <div class="muted small">{{ $complaint->channelLabel() }}</div>
        </td>
        <td>{{ $complaint->reporter_name }}
          <div class="muted small">{{ $complaint->reporter_phone ?: '—' }}</div>
        </td>
        <td>{{ $complaint->categoryLabel() }}
          @if($complaint->sub_category)<div class="muted small">{{ $complaint->sub_category }}</div>@endif
        </td>
        <td class="muted small">{{ $complaint->outlet?->name ?? '—' }}</td>
        <td><span class="badge w-{{ $complaint->bobot }}">{{ $complaint->bobotLabel() }}</span></td>
        <td><span class="badge b-{{ $complaint->status }}">{{ $complaint->statusDisplay() }}</span></td>
        <td class="muted small">{{ $complaint->assignee?->name ?? 'Belum ada' }}</td>
        <td>@include('partials.sla')</td>
      </tr>
      @endforeach
      </tbody>
    </table>
  </div>

  <div class="cards">
    @foreach($complaints as $complaint)
    <a class="ccard" href="{{ route('complaints.show',$complaint) }}">
      <div class="hd">
        <span class="tix">{{ $complaint->ticket_number }}</span>
        <span class="badge b-{{ $complaint->status }}">{{ $complaint->statusDisplay() }}</span>
      </div>
      <div class="nm">{{ $complaint->reporter_name }}</div>
      <div class="meta">
        {{ $complaint->categoryLabel() }} · {{ $complaint->channelLabel() }}
        @if($complaint->outlet) · {{ $complaint->outlet->name }} @endif
      </div>
      <div style="display:flex;gap:12px;align-items:flex-end;justify-content:space-between">
        <span class="badge w-{{ $complaint->bobot }}">{{ $complaint->bobotLabel() }}</span>
        @include('partials.sla')
      </div>
    </a>
    @endforeach
  </div>

  {{ $complaints->links() }}
@endif
@endsection
