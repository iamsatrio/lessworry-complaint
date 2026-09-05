@extends('layouts.app')
@section('title','Papan Kerja')
@section('content')
<div class="eyebrow">Papan Kerja</div>
<h1>{{ $complaints->total() }} complaint{{ request('status') ? '' : ' terbuka' }}</h1>
<p class="lede">
  @if(request('status')) Berstatus "{{ config('complaint.statuses.'.request('status')) }}".
  @else Yang paling dekat tenggat tampil paling atas. @endif
  @if(auth()->user()->isKasir()) Dibatasi outlet {{ auth()->user()->outlet?->name }}. @endif
</p>

<details class="filters" @if(request()->hasAny(['q','status','category','bobot','layanan','outlet_id'])) open @endif>
  <summary>Saring &amp; cari</summary>
  <form method="GET" class="row body">
    <div style="flex:2.4"><label for="q">Cari</label>
      <input id="q" name="q" value="{{ request('q') }}" placeholder="Nomor tiket, nama, telepon, atau ID transaksi">
    </div>
    <div><label for="fs">Status</label>
      <select id="fs" name="status"><option value="">Semua yang terbuka</option>
        @foreach(config('complaint.statuses') as $k=>$v)
          <option value="{{ $k }}" @selected(request('status')===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
    <div><label for="fc">Kategori</label>
      <select id="fc" name="category"><option value="">Semua</option>
        @foreach(config('complaint.categories') as $k=>$v)
          <option value="{{ $k }}" @selected(request('category')===$k)>{{ $v['label'] }}</option>
        @endforeach
      </select>
    </div>
    <div><label for="fb">Bobot</label>
      <select id="fb" name="bobot"><option value="">Semua</option>
        @foreach(config('complaint.bobot') as $k=>$v)
          <option value="{{ $k }}" @selected(request('bobot')===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
    <div><label for="fl">Layanan</label>
      <select id="fl" name="layanan"><option value="">Semua</option>
        @foreach(config('complaint.layanan') as $k=>$v)
          <option value="{{ $k }}" @selected(request('layanan')===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
    @if(auth()->user()->seesAllOutlets())
    <div><label for="fo">Outlet</label>
      <select id="fo" name="outlet_id"><option value="">Semua</option>
        @foreach($outlets as $o)<option value="{{ $o->id }}" @selected(request('outlet_id')==$o->id)>{{ $o->name }}</option>@endforeach
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
      <p>Ubah saringan di atas, atau catat complaint baru kalau ada keluhan yang masuk.</p>
      @if(auth()->user()->canCreateComplaint())
        <a class="btn" href="{{ route('complaints.create') }}">Catat Complaint</a>
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
