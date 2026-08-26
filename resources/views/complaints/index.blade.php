@extends('layouts.app')
@section('title','Papan Kerja')
@section('content')
<h1>Papan Kerja</h1>
<div class="sub">
  {{ $complaints->total() }} complaint
  @if(!request('status')) terbuka @else berstatus "{{ config('complaint.statuses.'.request('status')) }}" @endif
  @if(auth()->user()->isKasir()) · dibatasi outlet {{ auth()->user()->outlet?->name }} @endif
</div>

<div class="card">
  <form method="GET" class="row">
    <div style="flex:2"><label>Cari</label>
      <input name="q" value="{{ request('q') }}" placeholder="Nomor tiket, nama, telepon, ID transaksi">
    </div>
    <div><label>Status</label>
      <select name="status"><option value="">Terbuka</option>
        @foreach(config('complaint.statuses') as $k=>$v)
          <option value="{{ $k }}" @selected(request('status')===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
    <div><label>Kategori</label>
      <select name="category"><option value="">Semua</option>
        @foreach(config('complaint.categories') as $k=>$v)
          <option value="{{ $k }}" @selected(request('category')===$k)>{{ $v['label'] }}</option>
        @endforeach
      </select>
    </div>
    <div><label>Prioritas</label>
      <select name="priority"><option value="">Semua</option>
        @foreach(config('complaint.priorities') as $k=>$v)
          <option value="{{ $k }}" @selected(request('priority')===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
    @if(auth()->user()->seesAllOutlets())
    <div><label>Outlet</label>
      <select name="outlet_id"><option value="">Semua</option>
        @foreach($outlets as $o)<option value="{{ $o->id }}" @selected(request('outlet_id')==$o->id)>{{ $o->name }}</option>@endforeach
      </select>
    </div>
    @endif
    <div style="flex:0"><button>Saring</button></div>
  </form>
</div>

<div class="card">
<table>
  <tr><th>Tiket</th><th>Pelapor</th><th>Kategori</th><th>Kanal</th><th>Outlet</th><th>Prioritas</th><th>Status</th><th>PJ</th><th>SLA</th></tr>
  @forelse($complaints as $c)
  <tr>
    <td><a href="{{ route('complaints.show',$c) }}" class="mono">{{ $c->ticket_number }}</a>
      @if($c->nevira_transaction_id)<div class="muted mono" style="font-size:11px">order {{ $c->nevira_transaction_id }}</div>@endif
    </td>
    <td>{{ $c->reporter_name }}<div class="muted" style="font-size:12px">{{ $c->reporter_phone }}</div></td>
    <td>{{ $c->categoryLabel() }}<div class="muted" style="font-size:12px">{{ $c->sub_category }}</div></td>
    <td class="muted">{{ $c->channelLabel() }}</td>
    <td class="muted">{{ $c->outlet?->name ?? '—' }}</td>
    <td><span class="badge p-{{ $c->priority }}">{{ config('complaint.priorities.'.$c->priority) }}</span></td>
    <td><span class="badge b-{{ $c->status }}">{{ $c->statusLabel() }}</span></td>
    <td class="muted">{{ $c->assignee?->name ?? '—' }}</td>
    <td>
      @if($c->isOverdue())<span class="overdue">Lewat {{ $c->due_resolution_at->diffForHumans(null,true) }}</span>
      @elseif($c->isResponseOverdue())<span style="color:var(--warn)">Belum direspon</span>
      @elseif($c->isOpen() && $c->due_resolution_at)<span class="muted">sisa {{ $c->due_resolution_at->diffForHumans(null,true) }}</span>
      @else<span class="muted">—</span>@endif
    </td>
  </tr>
  @empty<tr><td colspan="9" class="muted">Tidak ada complaint yang cocok.</td></tr>@endforelse
</table>
</div>
{{ $complaints->links() }}
@endsection
