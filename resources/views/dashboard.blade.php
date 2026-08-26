@extends('layouts.app')
@section('title','Dashboard')
@section('content')
<h1>Dashboard</h1>
<div class="sub">Ringkasan complaint — {{ now()->translatedFormat('l, d F Y') }}</div>

<div class="grid g4" style="margin-bottom:20px">
  <div class="stat"><div class="n">{{ $openCount }}</div><div class="l">Complaint Terbuka</div></div>
  <div class="stat danger"><div class="n">{{ $overdueCount }}</div><div class="l">Lewat SLA</div></div>
  <div class="stat"><div class="n">{{ $todayCount }}</div><div class="l">Masuk Hari Ini</div></div>
  <div class="stat ok"><div class="n">{{ $resolvedToday }}</div><div class="l">Selesai Hari Ini</div></div>
</div>

@if($avgMinutes !== null)
<div class="card" style="padding:14px 18px">
  <span class="muted">Rata-rata waktu penyelesaian 30 hari terakhir:</span>
  <b>{{ intdiv($avgMinutes,60) }} jam {{ $avgMinutes%60 }} menit</b>
</div>
@endif

@if($overdue->isNotEmpty())
<div class="card">
  <h3 style="margin:0 0 12px;color:var(--danger)">Lewat SLA — tangani lebih dulu</h3>
  <table>
    <tr><th>Tiket</th><th>Pelapor</th><th>Kategori</th><th>Prioritas</th><th>Tenggat</th></tr>
    @foreach($overdue as $c)
    <tr>
      <td><a href="{{ route('complaints.show',$c) }}" class="mono">{{ $c->ticket_number }}</a></td>
      <td>{{ $c->reporter_name }}</td>
      <td>{{ $c->categoryLabel() }}</td>
      <td><span class="badge p-{{ $c->priority }}">{{ config('complaint.priorities.'.$c->priority) }}</span></td>
      <td class="overdue">telat {{ $c->due_resolution_at->diffForHumans(null,true) }}</td>
    </tr>
    @endforeach
  </table>
</div>
@endif

<div class="grid g2">
  <div class="card">
    <h3 style="margin:0 0 12px">Status</h3>
    @forelse($byStatus as $status => $total)
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;font-size:14px">
          <span>{{ config('complaint.statuses.'.$status, $status) }}</span><b>{{ $total }}</b>
        </div>
        <div class="bar"><i style="width:{{ $byStatus->max() ? ($total/$byStatus->max()*100) : 0 }}%"></i></div>
      </div>
    @empty<p class="muted">Belum ada data.</p>@endforelse
  </div>

  <div class="card">
    <h3 style="margin:0 0 12px">Kategori — 30 hari</h3>
    @forelse($byCategory as $cat => $total)
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;font-size:14px">
          <span>{{ config('complaint.categories.'.$cat.'.label', $cat) }}</span><b>{{ $total }}</b>
        </div>
        <div class="bar"><i style="width:{{ $byCategory->max() ? ($total/$byCategory->max()*100) : 0 }}%"></i></div>
      </div>
    @empty<p class="muted">Belum ada data.</p>@endforelse
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 12px">Complaint Terbaru</h3>
  <table>
    <tr><th>Tiket</th><th>Pelapor</th><th>Kanal</th><th>Outlet</th><th>Status</th><th>Masuk</th></tr>
    @forelse($latest as $c)
    <tr>
      <td><a href="{{ route('complaints.show',$c) }}" class="mono">{{ $c->ticket_number }}</a></td>
      <td>{{ $c->reporter_name }}</td>
      <td class="muted">{{ $c->channelLabel() }}</td>
      <td class="muted">{{ $c->outlet?->name ?? '—' }}</td>
      <td><span class="badge b-{{ $c->status }}">{{ $c->statusLabel() }}</span></td>
      <td class="muted">{{ $c->created_at->diffForHumans() }}</td>
    </tr>
    @empty<tr><td colspan="6" class="muted">Belum ada complaint.</td></tr>@endforelse
  </table>
</div>
@endsection
