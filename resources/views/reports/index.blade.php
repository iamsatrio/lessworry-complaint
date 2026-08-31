@extends('layouts.app')
@section('title','Laporan')
@section('content')
<div class="eyebrow">{{ $from->translatedFormat('d M Y') }} — {{ $to->translatedFormat('d M Y') }}</div>
<h1>Laporan complaint</h1>
<p class="lede">
  @if($total === 0) Tidak ada complaint pada periode ini.
  @else {{ $total }} complaint masuk, {{ $resolved }} sudah selesai.
  @endif
</p>

<div class="card">
  <form method="GET" class="row">
    <div><label for="from">Dari tanggal</label><input id="from" type="date" name="from" value="{{ $from->format('Y-m-d') }}"></div>
    <div><label for="to">Sampai tanggal</label><input id="to" type="date" name="to" value="{{ $to->format('Y-m-d') }}"></div>
    <div class="shrink"><button>Terapkan</button></div>
    <div class="shrink"><a class="btn ghost" href="{{ route('reports.export', request()->query()) }}">Unduh CSV</a></div>
  </form>
</div>

@if($total === 0)
  <div class="card">
    <div class="empty">
      <div class="mark">📄</div>
      <h3>Tidak ada data pada periode ini</h3>
      <p>Pilih rentang tanggal lain untuk melihat laporannya.</p>
    </div>
  </div>
@else

<div class="grid g4" style="margin-bottom:18px">
  <div class="stat"><div class="n">{{ $total }}</div><div class="l">Total Complaint</div></div>
  <div class="stat ok"><div class="n">{{ $closedDone }}</div><div class="l">Ditutup Selesai</div></div>
  <div class="stat {{ $overdue > 0 ? 'danger' : '' }}"><div class="n">{{ $overdue }}</div><div class="l">Lewat Tenggat</div></div>
  <div class="stat accent"><div class="n">Rp {{ number_format($compensation,0,',','.') }}</div><div class="l">Kompensasi Dibayar</div></div>
</div>

{{-- "Ditolak" bukan lagi status tersendiri, tapi kemampuan memisahkannya
     tidak boleh hilang — hanya pindah ke alasan penutupan. (API-18 #6) --}}
<div class="grid g4" style="margin-bottom:18px">
  <div class="stat"><div class="n">{{ $closedReject }}</div><div class="l">Ditutup Ditolak</div></div>
  <div class="stat"><div class="n">{{ $total - $closedDone - $closedReject }}</div><div class="l">Masih Terbuka</div></div>
</div>

@if($avgMinutes !== null)
<div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
  <span class="muted">Rata-rata waktu penyelesaian</span>
  <b class="display" style="font-size:20px;color:var(--teal-deep)">{{ \App\Models\Complaint::humanMinutes($avgMinutes) }}</b>
</div>
@endif

<div class="grid g2">
  @foreach([
    ['Kategori keluhan',$byCategory,'complaint.categories'],
    ['Bobot',$byBobot,'complaint.bobot'],
    ['Layanan yang dikeluhkan',$byLayanan,'complaint.layanan'],
    ['Tindak lanjut',$byTindakLanjut,'complaint.tindak_lanjut'],
    ['Kanal masuk',$byChannel,'complaint.channels'],
    ['Per outlet',$byOutlet,null],
  ] as [$title,$data,$cfg])
  <div class="card">
    <div class="eyebrow">{{ $title }}</div>
    @forelse($data as $key => $count)
      @php
        $label = $key === 'tidak_dicatat'
          ? 'Tidak dicatat'
          : ($cfg
              ? ($cfg === 'complaint.categories' ? config($cfg.'.'.$key.'.label', $key) : config($cfg.'.'.$key, $key))
              : $key);
      @endphp
      <div class="meter-row">
        <div class="lab">
          <span>{{ $label }}</span>
          <b>{{ $count }}</b>
        </div>
        <div class="bar"><i style="width:{{ $data->max() ? ($count/$data->max()*100) : 0 }}%"></i></div>
      </div>
    @empty<p class="muted">Tidak ada data.</p>@endforelse
  </div>
  @endforeach

  @if(auth()->user()->canSeeStaffAttribution())
  <div class="card">
    <div class="eyebrow">Complaint per karyawan</div>
    @forelse($byStaff as $name => $info)
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:9px 0;border-bottom:1px solid var(--line)">
        <div>
          <b class="display">{{ $name }}</b>
          @if($info['nip'])<span class="muted small" style="font-family:var(--mono)"> · {{ $info['nip'] }}</span>@endif
          @if($info['stages'])<div class="muted small">{{ implode(', ', $info['stages']) }}</div>@endif
        </div>
        <b>{{ $info['total'] }}</b>
      </div>
    @empty
      <p class="muted" style="margin:0">Belum ada complaint yang ditetapkan pelakunya pada periode ini.</p>
    @endforelse

    @if($unattributed > 0)
      <p class="hint">{{ $unattributed }} complaint belum ditetapkan pelakunya, jadi tidak masuk hitungan di atas.</p>
    @endif

    <div class="panel" style="margin-top:14px">
      <b>Angka ini belum bisa dipakai menilai orang.</b>
      <div style="margin-top:6px">
        Ini jumlah complaint, bukan tingkat kesalahan. Satu complaint bisa melibatkan beberapa orang,
        jadi angka-angka di sini boleh berjumlah lebih besar dari total complaint. Karyawan yang menangani tiga kali lebih banyak
        order wajar muncul lebih sering tanpa bekerja lebih buruk. Pakai untuk memilih apa yang
        ditelusuri berikutnya, bukan sebagai dasar sanksi.
      </div>
    </div>
  </div>
  @endif

  <div class="card">
    <div class="eyebrow">Pelanggan yang komplain berulang</div>
    @forelse($repeat as $phone => $info)
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--line)">
        <span>{{ $info['name'] }} <span class="muted small" style="font-family:var(--mono)">{{ $phone }}</span></span>
        <b class="badge w-sedang">{{ $info['count'] }} kali</b>
      </div>
    @empty
      <p class="muted" style="margin:0">Tidak ada pelanggan yang komplain lebih dari sekali pada periode ini.</p>
    @endforelse
  </div>
</div>
@endif
@endsection
