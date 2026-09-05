@extends('layouts.app')
@section('title','Dashboard')
@section('content')
<div class="eyebrow">{{ now()->translatedFormat('l, d F Y') }}</div>
<h1>Selamat datang, {{ Str::before(auth()->user()->name,' ') }}</h1>
<p class="lede">
  @if($overdueCount > 0)
    {{ $overdueCount }} complaint sudah lewat tenggat. Tangani itu dulu.
  @elseif($openCount > 0)
    {{ $openCount }} complaint terbuka, semuanya masih dalam tenggat.
  @else
    Tidak ada complaint terbuka. Papan bersih.
  @endif
</p>

<div class="grid g4" style="margin-bottom:20px">
  <div class="stat"><div class="n">{{ $openCount }}</div><div class="l">Complaint Terbuka</div></div>
  <div class="stat {{ $overdueCount > 0 ? 'danger' : '' }}"><div class="n">{{ $overdueCount }}</div><div class="l">Lewat Tenggat</div></div>
  <div class="stat"><div class="n">{{ $todayCount }}</div><div class="l">Masuk Hari Ini</div></div>
  <div class="stat ok"><div class="n">{{ $resolvedToday }}</div><div class="l">Selesai Hari Ini</div></div>
</div>

@if($avgMinutes !== null)
<div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
  <span class="muted">Rata-rata waktu penyelesaian, 30 hari terakhir</span>
  <b class="display" style="font-size:20px;color:var(--teal-deep)">{{ \App\Models\Complaint::humanMinutes($avgMinutes) }}</b>
</div>
@endif

@if($overdue->isNotEmpty())
<div class="card flush" style="border-color:#f3c4c4">
  <div class="card-h" style="background:var(--danger-soft);border-color:#f3c4c4">
    <h2 style="color:var(--danger)">Lewat tenggat — tangani lebih dulu</h2>
  </div>
  <table>
    <thead><tr><th>Tiket</th><th>Pelapor</th><th>Kategori</th><th>Bobot</th><th>Keterlambatan</th></tr></thead>
    <tbody>
    @foreach($overdue as $complaint)
    <tr>
      <td><a href="{{ route('complaints.show',$complaint) }}" class="tix">{{ $complaint->ticket_number }}</a></td>
      <td>{{ $complaint->reporter_name }}</td>
      <td>{{ $complaint->categoryLabel() }}</td>
      <td><span class="badge w-{{ $complaint->bobot }}">{{ $complaint->bobotLabel() }}</span></td>
      <td>@include('partials.sla')</td>
    </tr>
    @endforeach
    </tbody>
  </table>
</div>
@endif

<div class="grid g2">
  <div class="card">
    <div class="eyebrow">Status saat ini</div>
    @forelse($byStatus as $status => $total)
      <div class="meter-row">
        <div class="lab"><span>{{ config('complaint.statuses.'.$status, $status) }}</span><b>{{ $total }}</b></div>
        <div class="bar"><i style="width:{{ $byStatus->max() ? ($total/$byStatus->max()*100) : 0 }}%"></i></div>
      </div>
    @empty<p class="muted">Belum ada complaint yang tercatat.</p>@endforelse
  </div>

  <div class="card">
    <div class="eyebrow">Kategori · 30 hari</div>
    @forelse($byCategory as $cat => $total)
      <div class="meter-row">
        <div class="lab"><span>{{ config('complaint.categories.'.$cat.'.label', $cat) }}</span><b>{{ $total }}</b></div>
        <div class="bar"><i style="width:{{ $byCategory->max() ? ($total/$byCategory->max()*100) : 0 }}%"></i></div>
      </div>
    @empty<p class="muted">Belum ada complaint 30 hari terakhir.</p>@endforelse
  </div>
</div>

<div class="card flush">
  <div class="card-h" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h2>Complaint terbaru</h2>
    <a href="{{ route('complaints.index') }}" class="small">Lihat papan kerja →</a>
  </div>
  @if($latest->isEmpty())
    <div class="empty">
      <div class="mark">🧺</div>
      <h3>Belum ada complaint</h3>
      <p>Begitu keluhan pertama masuk, tiketnya muncul di sini.</p>
      @if(auth()->user()->canCreateComplaint())
        <a class="btn" href="{{ route('complaints.create') }}">Catat Complaint</a>
      @endif
    </div>
  @else
  <table>
    <thead><tr><th>Tiket</th><th>Pelapor</th><th>Kanal</th><th>Outlet</th><th>Status</th><th>Masuk</th></tr></thead>
    <tbody>
    @foreach($latest as $c)
    <tr>
      <td><a href="{{ route('complaints.show',$c) }}" class="tix">{{ $c->ticket_number }}</a></td>
      <td>{{ $c->reporter_name }}</td>
      <td class="muted small">{{ $c->channelLabel() }}</td>
      <td class="muted small">{{ $c->outlet?->name ?? '—' }}</td>
      <td><span class="badge b-{{ $c->status }}">{{ $c->statusDisplay() }}</span></td>
      <td class="muted small">{{ $c->created_at->diffForHumans() }}</td>
    </tr>
    @endforeach
    </tbody>
  </table>
  @endif
</div>
@endsection
