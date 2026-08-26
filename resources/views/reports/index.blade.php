@extends('layouts.app')
@section('title','Laporan')
@section('content')
<h1>Laporan Complaint</h1>
<div class="sub">Periode {{ $from->translatedFormat('d M Y') }} — {{ $to->translatedFormat('d M Y') }}</div>

<div class="card">
  <form method="GET" class="row">
    <div><label>Dari</label><input type="date" name="from" value="{{ $from->format('Y-m-d') }}"></div>
    <div><label>Sampai</label><input type="date" name="to" value="{{ $to->format('Y-m-d') }}"></div>
    <div style="flex:0"><button>Terapkan</button></div>
    <div style="flex:0"><a class="btn ghost" href="{{ route('reports.export', request()->query()) }}">Ekspor CSV</a></div>
  </form>
</div>

<div class="grid g4" style="margin-bottom:18px">
  <div class="stat"><div class="n">{{ $total }}</div><div class="l">Total Complaint</div></div>
  <div class="stat ok"><div class="n">{{ $resolved }}</div><div class="l">Selesai</div></div>
  <div class="stat danger"><div class="n">{{ $overdue }}</div><div class="l">Lewat SLA</div></div>
  <div class="stat warn"><div class="n" style="font-size:22px">Rp {{ number_format($compensation,0,',','.') }}</div><div class="l">Kompensasi</div></div>
</div>

@if($avgMinutes !== null)
<div class="card" style="padding:14px 18px">
  <span class="muted">Rata-rata waktu penyelesaian:</span> <b>{{ intdiv($avgMinutes,60) }} jam {{ $avgMinutes%60 }} menit</b>
</div>
@endif

<div class="grid g2">
  @foreach([['Kategori',$byCategory,'complaint.categories'],['Kanal Masuk',$byChannel,'complaint.channels'],['Outlet',$byOutlet,null]] as [$title,$data,$cfg])
  <div class="card">
    <h3 style="margin:0 0 12px">{{ $title }}</h3>
    @forelse($data as $key => $count)
      <div style="margin-bottom:9px">
        <div style="display:flex;justify-content:space-between;font-size:14px">
          <span>{{ $cfg ? ($cfg==='complaint.categories' ? config($cfg.'.'.$key.'.label',$key) : config($cfg.'.'.$key,$key)) : $key }}</span>
          <b>{{ $count }}</b>
        </div>
        <div class="bar"><i style="width:{{ $data->max() ? ($count/$data->max()*100) : 0 }}%"></i></div>
      </div>
    @empty<p class="muted">Tidak ada data.</p>@endforelse
  </div>
  @endforeach

  <div class="card">
    <h3 style="margin:0 0 12px">Pelanggan Complaint Berulang</h3>
    @forelse($repeat as $phone => $info)
      <div style="display:flex;justify-content:space-between;font-size:14px;padding:6px 0;border-bottom:1px solid var(--line)">
        <span>{{ $info['name'] }} <span class="muted mono">{{ $phone }}</span></span>
        <b style="color:var(--warn)">{{ $info['count'] }}×</b>
      </div>
    @empty<p class="muted">Tidak ada pelanggan yang komplain lebih dari sekali.</p>@endforelse
  </div>
</div>
@endsection
