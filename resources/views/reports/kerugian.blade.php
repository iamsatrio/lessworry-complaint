@extends('layouts.app')
@section('title','Kerugian')
@section('content')
@php use App\Services\NilaiBiaya; @endphp
<div class="eyebrow">{{ $rentangTerbaca }}@if($outlet) · {{ $outlet->name }}@endif</div>
<h1>Kerugian akibat complaint</h1>
<p class="lede">
  {{-- Judulnya menyebut cakupannya, bukan hanya totalnya. Halaman ini
       melaporkan biaya YANG TERCATAT dan mengatakan seberapa lengkap
       catatannya — menaksir yang tidak tercatat adalah pekerjaan orang,
       bukan sistem. (API-43) --}}
  @if($ringkasan['total'] === 0)
    Tidak ada complaint pada periode ini.
  @else
    {{ NilaiBiaya::rupiah($ringkasan['biaya']) }} tercatat
    {{ NilaiBiaya::cakupanTeks($ringkasan['terisi'], $ringkasan['total']) }}
    pada periode ini.
    @if($belumPasti['nilai'] > 0)
      {{-- Totalnya tetap menjumlahkan semuanya; yang belum pasti disebut,
           bukan dibuang. Membuangnya menyembunyikan paparan yang sedang
           berjalan. (API-106) --}}
      Angka itu {{ NilaiBiaya::belumPastiTeks($belumPasti['nilai'], $belumPasti['tiket']) }}.
    @endif
  @endif
</p>

<div class="card">
  <div class="pintasan" role="group" aria-label="Pintasan rentang tanggal">
    @foreach($pintasan as $p)
      <a class="chip {{ $p['aktif'] ? 'aktif' : '' }}"
         href="{{ route('reports.kerugian', array_merge(request()->query(), ['from' => $p['dari'], 'to' => $p['sampai']])) }}"
         @if($p['aktif']) aria-current="true" @endif>{{ $p['nama'] }}</a>
    @endforeach
  </div>

  <p class="rentang-terpilih">
    <span>Rentang terpilih</span><b>{{ $rentangTerbaca }}</b>
    <span class="muted small">grafik {{ mb_strtolower($satuan->label()) }}@unless($satuanDipilih) (otomatis)@endunless</span>
  </p>

  <form method="GET" class="row">
    <div><label for="from">Dari tanggal</label><input id="from" type="date" name="from" value="{{ $from->format('Y-m-d') }}"></div>
    <div><label for="to">Sampai tanggal</label><input id="to" type="date" name="to" value="{{ $to->format('Y-m-d') }}"></div>
    {{-- Isinya hanya outlet yang boleh dilihat pengguna ini. Yang
         menegakkannya tetap sisi server: LaporanFilterRequest::authorize()
         menolak permintaan langsung dengan outlet lain, jadi kasir tidak bisa
         melihat biaya outlet lain dengan mengubah URL-nya. --}}
    @if($pilihanOutlet->isNotEmpty())
    <div>
      <label for="outlet">Outlet</label>
      <select id="outlet" name="outlet">
        <option value="">Semua outlet</option>
        @foreach($pilihanOutlet as $pilihan)
          <option value="{{ $pilihan->id }}" @selected($outlet?->id === $pilihan->id)>{{ $pilihan->name }}</option>
        @endforeach
      </select>
    </div>
    @endif
    <div>
      <label for="satuan">Satuan waktu grafik</label>
      <select id="satuan" name="satuan">
        <option value="">Otomatis</option>
        @foreach(\App\Services\SatuanWaktu::cases() as $pilihan)
          <option value="{{ $pilihan->value }}" @selected($satuanDipilih && $satuan === $pilihan)>{{ $pilihan->label() }}</option>
        @endforeach
      </select>
    </div>
    <div class="shrink"><button>Terapkan</button></div>
    {{-- Unduhan berisi baris per complaint BERBIAYA — supaya totalnya bisa
         ditelusuri di luar sistem, bukan dipercaya begitu saja. --}}
    <div class="shrink"><a class="btn ghost" href="{{ route('reports.kerugian.export', request()->query()) }}">Unduh CSV</a></div>
  </form>
</div>

@if($ringkasan['total'] === 0)
  <div class="card">
    <div class="empty">
      <div class="mark">📄</div>
      <h3>Tidak ada complaint pada periode ini</h3>
      <p>Pilih rentang tanggal lain untuk melihat kerugiannya.</p>
    </div>
  </div>
@else

{{-- Peringatan cakupan berdiri PALING ATAS, sebelum satu pun angka besar.
     Kolom biaya terisi 96% pada 2025 dan 38% pada 2026; pembaca yang melihat
     totalnya lebih dulu sudah menarik kesimpulan sebelum sampai ke catatan
     kakinya. (API-43) --}}
@if($ringkasan['rendah'])
  <div class="card">
    <div class="panel bad">
      <b>Cakupan biaya periode ini hanya {{ $ringkasan['persen'] }}%.</b>
      Baru {{ $ringkasan['terisi'] }} dari {{ $ringkasan['total'] }} complaint yang punya nilai biaya tercatat.
      Seluruh angka di halaman ini adalah biaya YANG TERCATAT — bukan biaya yang terjadi. Periode dengan
      cakupan berbeda tidak bisa dibandingkan begitu saja: yang turun bisa jadi pengisian kolomnya,
      bukan kerugiannya.
    </div>
  </div>
@endif

<div class="grid g4" style="margin-bottom:18px">
  {{-- Setiap total membawa cakupannya. Tidak ada satu pun angka total di
       halaman ini yang berdiri tanpa keterangan itu. --}}
  <div class="stat accent">
    <div class="n">{{ NilaiBiaya::rupiah($ringkasan['biaya']) }}</div>
    <div class="l">Total Biaya Tercatat</div>
    <div class="c">{{ NilaiBiaya::cakupanTeks($ringkasan['terisi'], $ringkasan['total']) }}</div>
  </div>
  <div class="stat {{ $ringkasan['rendah'] ? 'danger' : '' }}">
    <div class="n">{{ $ringkasan['persen'] === null ? '—' : $ringkasan['persen'].'%' }}</div>
    <div class="l">Cakupan Pencatatan</div>
    <div class="c">{{ $ringkasan['terisi'] }} dari {{ $ringkasan['total'] }} complaint punya nilai biaya</div>
  </div>
  {{-- Median berdampingan dengan rata-rata, bukan menggantikannya. Satu kasus
       Rp 3.330.000 menarik rata-rata; selisih keduanya itulah yang
       memperlihatkan sebaran yang panjang ekornya. --}}
  <div class="stat">
    <div class="n">{{ $ringkasan['median'] === null ? '—' : NilaiBiaya::rupiah($ringkasan['median']) }}</div>
    <div class="l">Median per Complaint Berbiaya</div>
    <div class="c">rata-rata {{ $ringkasan['rata'] === null ? '—' : NilaiBiaya::rupiah($ringkasan['rata']) }}@if($ringkasan['tertinggi'] !== null) · tertinggi {{ NilaiBiaya::rupiah($ringkasan['tertinggi']) }}@endif</div>
  </div>
  <div class="stat">
    <div class="n">{{ $ringkasan['total'] - $ringkasan['terisi'] }}</div>
    <div class="l">Complaint Tanpa Nilai Tercatat</div>
    {{-- Kosong BUKAN Rp 0: complaint ini tidak ikut dijumlah, tidak ikut jadi
         pembagi rata-rata maupun median. --}}
    <div class="c">tidak dihitung sebagai Rp 0 — tidak masuk total, rata-rata, maupun median</div>
  </div>
</div>

{{-- ---------------------------------------------------------------
     Uang keluar vs kerja ulang. Dua jenis kerugian yang tidak boleh
     dijumlahkan tanpa dibedakan — dan jumlah ketiganya SELALU sama
     dengan total di atas. (API-43)
     --------------------------------------------------------------- --}}
<div class="card">
  <h2>Uang keluar dan kerja ulang</h2>
  <p class="muted small" style="margin:6px 0 14px">
    Kas yang benar-benar berkurang berbeda artinya dari bahan dan tenaga yang terpakai dua kali, walau
    jumlahnya bisa mirip. Ketiganya dipisah di sini dan dijumlahkan di baris terakhir.
  </p>
  <table>
    <thead><tr>
      <th>Golongan</th><th class="num">Kasus</th><th class="num">Punya nilai</th>
      <th class="num">Biaya tercatat</th><th class="num">Porsi biaya</th>
    </tr></thead>
    <tbody>
      @foreach($golongan as $baris)
        <tr>
          <td>
            <b>{{ $baris['label'] }}</b>
            <div class="muted small">{{ $baris['keterangan'] }}</div>
          </td>
          <td class="num">{{ $baris['kasus'] }}</td>
          <td class="num">
            {{ $baris['terisi'] }}
            @if($baris['rendah'])<div class="muted small">cakupan {{ $baris['persen'] }}%</div>@endif
          </td>
          <td class="num">{{ NilaiBiaya::rupiah($baris['biaya']) }}</td>
          <td class="num">{{ $ringkasan['biaya'] > 0 ? round(100 * $baris['biaya'] / $ringkasan['biaya']).'%' : '—' }}</td>
        </tr>
      @endforeach
      <tr>
        <td><b>Total</b></td>
        <td class="num">{{ $ringkasan['total'] }}</td>
        <td class="num">{{ $ringkasan['terisi'] }}</td>
        <td class="num"><b>{{ NilaiBiaya::rupiah($ringkasan['biaya']) }}</b></td>
        <td class="num">100%</td>
      </tr>
    </tbody>
  </table>
</div>

{{-- ---------------------------------------------------------------
     Tren per periode, dengan cakupannya sebagai grafik kedua di
     sebelahnya — bukan sumbu kedua pada gambar yang sama.
     --------------------------------------------------------------- --}}
@php
  $catatanTren = 'Biaya yang TERCATAT per '.mb_strtolower($rekap->satuan()->satuan()).'. Baca berdampingan dengan grafik cakupan di bawahnya: penurunan biaya pada periode yang cakupannya ikut turun adalah lubang data, bukan penghematan.';
  $catatanTren .= $periodeRendah > 0
    ? ' '.$periodeRendah.' periode pada rentang ini bercakupan di bawah '.$ambangRendah.'% dan ditandai di tabelnya.'
    : '';
@endphp

<x-grafik.garis
  :judul="'Biaya tercatat '.$rekap->satuan()->perSatuan()"
  :catatan="$catatanTren"
  :titik="$titikBiaya"
  warna="var(--danger)">
  <x-slot:tabel>
    <table>
      <thead><tr>
        <th>{{ $rekap->satuan()->satuan() }}</th><th class="num">Complaint</th>
        <th class="num">Punya nilai</th><th class="num">Cakupan</th><th class="num">Biaya tercatat</th>
      </tr></thead>
      <tbody>
        @foreach($perPeriode as $baris)
          <tr>
            <td>{{ $baris['judul'] }}</td>
            <td class="num">{{ $baris['kasus'] }}</td>
            <td class="num">{{ $baris['terisi'] }}</td>
            {{-- Periode bercakupan rendah ditandai secara terlihat, di baris
                 angkanya sendiri — bukan hanya di catatan kaki yang bisa
                 terlewat. --}}
            <td class="num">
              {{ $baris['persen'] === null ? '—' : $baris['persen'].'%' }}
              @if($baris['rendah'])<span class="muted small"> · cakupan rendah</span>@endif
            </td>
            <td class="num">{{ NilaiBiaya::rupiah($baris['biaya']) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </x-slot:tabel>
</x-grafik.garis>

<x-grafik.garis
  :judul="'Cakupan pencatatan biaya '.$rekap->satuan()->perSatuan()"
  catatan="Berapa persen complaint pada periode itu yang punya nilai biaya tercatat. Grafik ini ADA supaya grafik di atasnya tidak dibaca sendirian — keduanya berskala beda, jadi digambar terpisah."
  :titik="$titikCakupan"
  :pita="['min' => 0.0, 'max' => (float) $ambangRendah, 'label' => 'cakupan di bawah '.$ambangRendah.'%']"
  kosong-teks="Belum ada periode yang punya complaint untuk dihitung cakupannya.">
  <x-slot:tabel>
    <table>
      <thead><tr><th>{{ $rekap->satuan()->satuan() }}</th><th class="num">Cakupan</th><th class="num">Punya nilai</th><th class="num">Complaint</th></tr></thead>
      <tbody>
        @foreach($perPeriode as $baris)
          <tr>
            <td>{{ $baris['judul'] }}</td>
            <td class="num">{{ $baris['persen'] === null ? '—' : $baris['persen'].'%' }}@if($baris['rendah'])<span class="muted small"> · rendah</span>@endif</td>
            <td class="num">{{ $baris['terisi'] }}</td>
            <td class="num">{{ $baris['kasus'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </x-slot:tabel>
</x-grafik.garis>

{{-- ---------------------------------------------------------------
     Tiga pengelompokan: kategori, outlet, tindak lanjut. Masing-masing
     dengan jumlah kasus, jumlah berbiaya, total, dan cakupannya.
     --------------------------------------------------------------- --}}
@php
  $catatanKategori = 'Di sinilah selisih antara "paling sering" dan "paling mahal" terlihat: kategori dengan kasus terbanyak belum tentu yang menanggung biaya terbesar. Total '.NilaiBiaya::rupiah($ringkasan['biaya']).' '.NilaiBiaya::cakupanTeks($ringkasan['terisi'], $ringkasan['total']).'.';
@endphp

<x-grafik.batang
  judul="Biaya tercatat per kategori"
  :catatan="$catatanKategori"
  :baris="$batangKategori"
  warna="var(--danger)">
  <x-slot:tabel>
    @include('reports.partials.kerugian-kelompok', ['judulKolom' => 'Kategori', 'baris' => $kategori, 'totalBiaya' => $ringkasan['biaya'], 'totalKasus' => $ringkasan['total']])
  </x-slot:tabel>
</x-grafik.batang>

@php
  $catatanOutlet = $outlet
    ? 'Saringan sedang pada satu outlet, jadi yang tergambar hanya '.$outlet->name.'. Lepas saringannya untuk membandingkan antar outlet yang boleh kamu lihat.'
    : 'Di mana biayanya terjadi. Jumlah mentah, tidak dibagi apa pun: satu barang rusak tetap harus dibayar berapa pun outlet yang buka. Outlet yang tidak boleh kamu lihat tidak ada di sini.';
@endphp

<x-grafik.batang
  judul="Biaya tercatat per outlet"
  :catatan="$catatanOutlet"
  :baris="$batangOutlet">
  <x-slot:tabel>
    @include('reports.partials.kerugian-kelompok', ['judulKolom' => 'Outlet', 'baris' => $outletBaris, 'totalBiaya' => $ringkasan['biaya'], 'totalKasus' => $ringkasan['total']])
  </x-slot:tabel>
</x-grafik.batang>

<x-grafik.batang
  judul="Biaya tercatat per tindak lanjut"
  catatan="Tindak lanjut yang memindahkan uang ke pelanggan dan yang memakai bahan dan tenaga berdiri berdampingan di sini; pemisahannya ada di tabel Uang keluar dan kerja ulang di atas."
  :baris="$batangTindakLanjut"
  warna="var(--teal-deep)">
  <x-slot:tabel>
    @include('reports.partials.kerugian-kelompok', ['judulKolom' => 'Tindak lanjut', 'baris' => $tindakLanjut, 'totalBiaya' => $ringkasan['biaya'], 'totalKasus' => $ringkasan['total']])
  </x-slot:tabel>
</x-grafik.batang>

{{-- Kasus termahal. Angka besar yang tidak bisa dibuka satu per satu tetap
     angka yang harus dipercaya begitu saja. --}}
@if($termahal->isNotEmpty())
<div class="card">
  <h2>Kasus dengan biaya tertinggi</h2>
  <p class="muted small" style="margin:6px 0 14px">
    Sepuluh teratas pada rentang ini. Angka besar harus bisa dibuka — daftar lengkapnya ada di unduhan CSV.
  </p>
  <table>
    <thead><tr>
      <th>Tiket</th><th>Tanggal</th><th>Outlet</th><th>Kategori</th><th>Tindak lanjut</th><th class="num">Biaya</th>
    </tr></thead>
    <tbody>
      @foreach($termahal as $c)
        <tr>
          <td><a href="{{ route('complaints.show', $c) }}">{{ $c->ticket_number }}</a></td>
          <td>{{ $c->created_at?->translatedFormat('j M Y') }}</td>
          <td>{{ $c->outlet->name ?? '—' }}</td>
          <td>{{ $c->categoryLabel() }}</td>
          <td>{{ $c->tindak_lanjut === null ? '—' : $c->tindakLanjutLabel() }}</td>
          <td class="num">{{ NilaiBiaya::rupiah((int) $c->compensation_amount) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

{{-- Yang sengaja TIDAK ada di halaman ini, ditulis terang-terangan: taksiran
     yang tampil sebagai angka pasti lebih berbahaya daripada tidak ada angka.
     (API-43) --}}
<div class="card">
  <div class="panel">
    <b>Yang tidak dihitung halaman ini.</b>
    Biaya yang tidak pernah dicatat tidak ditaksir — halaman ini melaporkan yang tercatat dan mengatakan
    seberapa lengkap. Nilai order yang tersentuh complaint, biaya waktu kerja karyawan, dan nilai pelanggan
    yang hilang tidak terukur dari data ini. Nilai kompensasi juga tidak bisa diubah dari sini; halaman ini
    membaca.
  </div>
</div>

@endif
@endsection
