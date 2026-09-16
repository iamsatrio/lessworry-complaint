@extends('layouts.app')
@section('title','Tagihan')
{{-- Ringkasan galat menautkan ke kolom ambang pengingat di kartu bawah. --}}
@section('galat-anchor'){!! json_encode(['ambang_hari' => 'ambang_hari']) !!}@endsection
@section('content')
@php use App\Services\PeriodeTagihan; @endphp

<div class="eyebrow">Dashboard Operations</div>
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
  <div>
    <h1>Tagihan bulanan</h1>
    <p class="lede">
      {{ collect($baris)->filter(fn ($b) => $b['tagihan']->is_active)->count() }} aktif dari {{ count($baris) }} tagihan.
      Alarmnya menyala {{ $ambang }} hari sebelum jatuh tempo, dan tetap menyala sampai ditandai dibayar.
    </p>
  </div>
  @if($bolehKelola)
    <a href="{{ route('tagihan.create') }}" class="btn">Tambah Tagihan</a>
  @endif
</div>

@if(count($baris) === 0)
  <div class="card">
    <p style="margin:0">
      Belum ada tagihan yang dicatat.
      @if($bolehKelola)
        <a href="{{ route('tagihan.create') }}">Tambahkan yang pertama</a> — sewa, listrik, internet, langganan perangkat lunak.
      @else
        Yang bisa menambahkannya pemegang wewenang pengelolaan tagihan.
      @endif
    </p>
  </div>
@endif

@foreach($baris as $b)
  @php
    $t = $b['tagihan'];
    $selisih = $b['selisih'];
    $mendesak = $t->is_active && $selisih !== null && $selisih <= $ambang;
  @endphp
  <div class="card" id="tagihan-{{ $t->id }}" @if(! $t->is_active) style="opacity:.6" @endif>
    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start">
      <div>
        <h2>{{ $t->nama }}</h2>
        <p class="muted small" style="margin:6px 0 0">
          {{ $t->outlet->name ?? 'Tagihan jaringan' }} · {{ $t->jadwalTerbaca() }} · {{ $t->jumlahTerbaca() }}
        </p>
      </div>
      <div style="text-align:right">
        @if(! $t->is_active)
          <span class="badge w-ringan">Nonaktif</span>
        @elseif($mendesak)
          <span class="badge {{ $selisih < 0 ? 'w-berat' : 'b-handling' }}">{{ PeriodeTagihan::keterangan($selisih) }}</span>
        @else
          <span class="badge b-close">{{ PeriodeTagihan::keterangan($selisih) }}</span>
        @endif
        @if($b['periode'])
          <div class="muted small" style="margin-top:6px">
            Periode berjalan {{ PeriodeTagihan::periodeTerbaca($b['periode']) }}
          </div>
        @endif
      </div>
    </div>

    {{-- Riwayat terakhir, bukan seluruhnya: yang dijawab halaman ini "terakhir
         diurus kapan, oleh siapa". Yang nonaktif pun tetap menjawabnya — itu
         sebabnya tagihan dinonaktifkan dan tidak pernah dihapus. --}}
    <p class="muted small" style="margin:14px 0 0">
      @if(isset($riwayat[$t->id]))
        Terakhir ditandai dibayar untuk {{ PeriodeTagihan::periodeTerbaca($riwayat[$t->id]->periode) }}
        oleh <b>{{ $riwayat[$t->id]->user->name ?? 'pengguna yang sudah dihapus' }}</b>,
        {{ $riwayat[$t->id]->ditandai_pada->timezone(config('complaint.alarms.zona_waktu'))->format('j M Y H:i') }}.
      @else
        Belum pernah ditandai dibayar.
      @endif
    </p>

    @if($bolehKelola)
      <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        {{-- Periode yang ditawarkan selalu yang SEDANG berjalan — jatuh tempo
             terdekat yang belum ditandai. Sesudah Oktober ditandai, tombolnya
             berpindah ke November sendiri; tidak ada yang perlu memilih
             periode, dan tidak ada yang bisa salah memilihnya. --}}
        @if($t->is_active)
          <form method="POST" action="{{ route('tagihan.bayar', $t) }}">
            @csrf
            <input type="hidden" name="periode" value="{{ $b['periode'] }}">
            <button>Tandai dibayar — {{ PeriodeTagihan::periodeTerbaca($b['periode']) }}</button>
          </form>
        @endif

        {{-- Jalan pulang untuk penandaan yang salah periode. Tanpa ini, satu
             kekeliruan menyembunyikan tagihan yang benar-benar jatuh tempo —
             persis kegagalan yang bikin halaman ini ada. --}}
        @if(isset($riwayat[$t->id]))
          <form method="POST" action="{{ route('tagihan.bayar.batal', [$t, $riwayat[$t->id]->periode]) }}"
                data-konfirmasi="Batalkan penandaan {{ $t->nama }} untuk {{ PeriodeTagihan::periodeTerbaca($riwayat[$t->id]->periode) }}? Alarmnya akan menyala lagi."
                onsubmit="return confirm(this.dataset.konfirmasi)">
            @csrf @method('DELETE')
            <button class="ghost">Batalkan penandaan terakhir</button>
          </form>
        @endif

        <a href="{{ route('tagihan.edit', $t) }}" class="btn ghost">Ubah</a>

        {{-- Tidak ada tombol Hapus, dan itu keputusan: tagihan yang dihapus
             membawa riwayat pembayarannya ikut hilang. (API-73 kriteria 1) --}}
        <form method="POST" action="{{ route('tagihan.status', $t) }}"
              data-konfirmasi="{{ $t->is_active ? 'Nonaktifkan' : 'Aktifkan lagi' }} {{ $t->nama }}?"
              onsubmit="return confirm(this.dataset.konfirmasi)">
          @csrf
          <input type="hidden" name="aktif" value="{{ $t->is_active ? 0 : 1 }}">
          <button class="ghost">{{ $t->is_active ? 'Nonaktifkan' : 'Aktifkan lagi' }}</button>
        </form>
      </div>
    @endif
  </div>
@endforeach

@if($bolehKelola)
  <div class="card">
    <h3>Ambang pengingat</h3>
    <p class="hint" style="margin-top:8px">
      Berapa hari sebelum jatuh tempo alarmnya mulai menyala. Bawaannya 3 hari — itu usulan, bukan hasil pengukuran.
      Ubah di sini kalau ternyata terlalu cepat atau terlalu mepet; tidak perlu menyentuh server.
    </p>
    <form method="POST" action="{{ route('tagihan.ambang') }}"
          style="margin-top:14px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      @csrf
      <div>
        <label for="ambang_hari">Hari</label>
        <input id="ambang_hari" name="ambang_hari" type="number" min="1" max="{{ $ambangMaks }}"
               inputmode="numeric" value="{{ old('ambang_hari', $ambang) }}" style="max-width:120px" required
               @error('ambang_hari') aria-invalid="true" aria-describedby="ambang_hari-error" @enderror>
        @error('ambang_hari')<p class="err-field" id="ambang_hari-error">{{ $message }}</p>@enderror
      </div>
      <button class="ghost">Simpan</button>
    </form>
  </div>
@endif
@endsection
