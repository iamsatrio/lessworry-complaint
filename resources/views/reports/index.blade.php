@extends('layouts.app')
@section('title','Laporan')
@section('content')
{{-- Rentang terpilih ditulis dengan kata, bukan dua kotak yyyy-mm-dd
     (API-62 nomor 5). Outlet yang sedang disaring ikut tertulis: halaman yang
     menyaring tanpa mengatakannya membuat angka satu outlet dibaca sebagai
     angka jaringan. (API-62 nomor 3) --}}
<div class="eyebrow">{{ $rentangTerbaca }}@if($outlet) · {{ $outlet->name }}@endif</div>
<h1>Laporan complaint</h1>
<p class="lede">
  {{-- Dihitung dari yang TIDAK lagi terbuka, bukan dari resolved_at. Seluruh
       541 baris impor data lama ditutup tanpa resolved_at, jadi judul lama
       berbunyi "545 complaint masuk, 0 sudah selesai" pada halaman yang di
       bawahnya sendiri memperlihatkan ratusan tiket Close. Rincian selesai /
       ditolak / tanpa alasan menjelaskan mutunya di kotak-kotak di bawah.
       (API-38 #11) --}}
  @if($total === 0) Tidak ada complaint pada periode ini.
  @else {{ $total }} complaint masuk, {{ $total - $stillOpen }} sudah ditutup.
  @endif
</p>

<div class="card">
  {{-- Pintasan rentang tanggal. Orang membuka laporan untuk menjawab
       "bagaimana bulan ini" — dan sebelum ini itu menuntut mengetik dua
       tanggal. Tautan biasa, bukan kalender: aplikasi ini belum memuat satu
       pun paket JavaScript di antarmuka, dan sederet pintasan bukan alasan
       yang cukup untuk memulainya. Isian tanggal bebas di bawahnya tetap
       ada. (API-62 nomor 5) --}}
  <div class="pintasan" role="group" aria-label="Pintasan rentang tanggal">
    @foreach($pintasan as $p)
      <a class="chip {{ $p['aktif'] ? 'aktif' : '' }}"
         href="{{ route('reports.index', array_merge(request()->query(), ['from' => $p['dari'], 'to' => $p['sampai']])) }}"
         @if($p['aktif']) aria-current="true" @endif>{{ $p['nama'] }}</a>
    @endforeach
  </div>

  {{-- Satuan grafiknya ikut tertulis di sini: kalau tidak, satu-satunya
       tanda bahwa sumbunya mingguan ada di judul grafik yang jauh di bawah,
       dan pilihan "Otomatis" jadi pilihan yang tidak mengatakan apa yang
       dipilihnya. (API-62 nomor 2) --}}
  <p class="rentang-terpilih">
    <span>Rentang terpilih</span><b>{{ $rentangTerbaca }}</b>
    <span class="muted small">grafik {{ mb_strtolower($satuan->label()) }}@unless($satuanDipilih) (otomatis)@endunless</span>
  </p>

  <form method="GET" class="row">
    <div><label for="from">Dari tanggal</label><input id="from" type="date" name="from" value="{{ $from->format('Y-m-d') }}"></div>
    <div><label for="to">Sampai tanggal</label><input id="to" type="date" name="to" value="{{ $to->format('Y-m-d') }}"></div>
    {{-- Daftarnya hanya berisi outlet yang boleh dilihat pengguna ini: kasir
         menemukan satu nama, bukan sebelas dengan sepuluh yang ditolak saat
         dipilih. Yang menegakkannya tetap sisi server —
         LaporanFilterRequest::authorize() menolak permintaan langsung dengan
         outlet lain. (API-62 nomor 3) --}}
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
    {{-- Keempat satuan tersedia, tapi yang dipakai ditentukan rentang
         tanggalnya sendiri kecuali orangnya memilih lain — itulah arti opsi
         "Otomatis", dan itu yang membuat mengubah rentang tanggal tidak
         meninggalkan satuan lama yang sudah tidak cocok. Tahunan bisa
         dipilih, tidak pernah jadi bawaan. (API-62 nomor 2) --}}
    <div>
      <label for="satuan">Satuan waktu grafik</label>
      <select id="satuan" name="satuan">
        {{-- Satuan yang sedang dipakai ditulis di baris "Rentang terpilih"
             di atas, bukan di dalam opsi ini: teks opsi yang panjang dipotong
             sendiri oleh select bawaan peramban, dan "Otomatis — Mingguar"
             lebih buruk daripada tidak menulisnya sama sekali. --}}
        <option value="">Otomatis</option>
        @foreach(\App\Services\SatuanWaktu::cases() as $pilihan)
          <option value="{{ $pilihan->value }}" @selected($satuanDipilih && $satuan === $pilihan)>{{ $pilihan->label() }}</option>
        @endforeach
      </select>
    </div>
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

{{-- Angka grafik dihitung sekali di sini; kartu statistik di bawah ikut
     memakainya supaya total biaya tidak pernah tampil tanpa cakupannya. --}}
@php $cakupan = $grafik->cakupanBiaya(); @endphp
@php $perPeriode = $grafik->perPeriode(); @endphp
@php $biayaKategori = $grafik->biayaPerKategori(); @endphp
@php $median = $grafik->medianPenyelesaian(); @endphp
@php $kolomPeriode = $grafik->satuan()->satuan(); @endphp
@php $ambang = $grafik->ambangSla(); @endphp

<div class="grid g4" style="margin-bottom:18px">
  <div class="stat"><div class="n">{{ $total }}</div><div class="l">Total Complaint</div></div>
  <div class="stat ok"><div class="n">{{ $closedDone }}</div><div class="l">Ditutup Selesai</div></div>
  <div class="stat {{ $overdue > 0 ? 'danger' : '' }}"><div class="n">{{ $overdue }}</div><div class="l">Lewat Tenggat</div></div>
  {{-- Setiap total biaya membawa cakupannya. Kolom biaya terisi 96% pada
       2025 dan 38% pada 2026; angka telanjang di sini akan dibaca sebagai
       penghematan, padahal yang turun pencatatannya. (API-52) --}}
  <div class="stat accent"><div class="n">Rp {{ number_format($compensation,0,',','.') }}</div>
    <div class="l">Kompensasi Dibayar</div>
    <div class="c">{{ $grafik->cakupanTeks($cakupan['terisi'], $cakupan['total']) }}</div></div>
</div>

{{-- "Ditolak" bukan lagi status tersendiri, tapi kemampuan memisahkannya
     tidak boleh hilang — hanya pindah ke alasan penutupan. (API-18 #6) --}}
<div class="grid g4" style="margin-bottom:18px">
  <div class="stat"><div class="n">{{ $closedReject }}</div><div class="l">Ditutup Ditolak</div></div>
  @if($closedNoReason > 0)
    {{-- Data lama tidak pernah mencatat alasan penutupan. Disebut apa adanya
         supaya keempat angka Close bisa dijumlahkan, bukan disembunyikan atau
         diam-diam dihitung sebagai "selesai". (Review PR #7, P2-3) --}}
    <div class="stat"><div class="n">{{ $closedNoReason }}</div><div class="l">Ditutup Tanpa Alasan (data lama)</div></div>
  @endif
  <div class="stat"><div class="n">{{ $stillOpen }}</div><div class="l">Masih Terbuka</div></div>
</div>

@if($avgMinutes !== null)
<div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
  <span class="muted">Rata-rata waktu penyelesaian</span>
  <b class="display" style="font-size:20px;color:var(--teal-deep)">{{ \App\Models\Complaint::humanMinutes($avgMinutes) }}</b>
</div>
@endif

{{-- ---------------------------------------------------------------
     Empat grafik. Bukan hiasan: masing-masing menjawab satu pertanyaan
     yang tidak terjawab oleh daftar angka di bawahnya. Delapan
     pengelompokan lain tetap tabel — halaman penuh grafik membuat tidak
     ada satu pun yang dibaca. (API-52)
     --------------------------------------------------------------- --}}

@php $tanpaOutlet = $grafik->tanpaOutlet(); @endphp
@php
  // Saat saringannya satu outlet, pembaginya satu — kalimat "dibagi jumlah
  // outlet aktif" jadi keterangan yang tidak menjelaskan apa pun, dan
  // pembacanya mengira angkanya sudah dinormalkan padahal itu jumlah mentah.
  $catatanTren = $outlet
    ? 'Saringan sedang pada satu outlet, jadi pembaginya satu: yang digambar jumlah complaint '.$outlet->name.' '.$grafik->satuan()->perSatuan().'. Lepas saringan outletnya untuk membandingkan antar outlet.'
    : 'Jumlah mentah naik setiap kali outlet bertambah, jadi yang digambar adalah angka per outlet. Pembaginya jumlah outlet yang sudah aktif pada periode itu — outlet yang belum pernah menerima complaint tidak ikut membagi periode sebelumnya.';
@endphp
@php $catatanTren .= $tanpaOutlet > 0 ? ' '.$tanpaOutlet.' complaint pada periode ini tidak punya outlet, jadi tidak bisa dibagi per outlet dan tidak masuk grafik ini.' : ''; @endphp

@php $titikPerOutlet = $grafik->titikPerOutlet(); @endphp

<x-grafik.garis
  :judul="($outlet ? 'Complaint '.$outlet->name : 'Complaint per outlet').' '.$grafik->satuan()->perSatuan()"
  :catatan="$catatanTren"
  :titik="$titikPerOutlet"
  :catatan-bawah="$grafik->keteranganPadatData($titikPerOutlet)">
  <x-slot:tabel>
    <table>
      <thead><tr><th>{{ $kolomPeriode }}</th><th class="num">Complaint</th><th class="num">Outlet aktif</th><th class="num">Per outlet</th></tr></thead>
      <tbody>
        @foreach($perPeriode as $periode)
          <tr>
            <td>{{ $periode['judul'] }}</td>
            <td class="num">{{ $periode['complaint'] }}</td>
            <td class="num">{{ $periode['outlet'] }}</td>
            <td class="num">{{ $periode['per'] === null ? '—' : $grafik->desimal($periode['per']) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </x-slot:tabel>
</x-grafik.garis>

@if($cakupan['rendah'])
  {{-- Periode dengan cakupan biaya di bawah 50% ditandai. Tanpa tanda ini,
       grafik biaya di bawahnya akan dibaca sebagai penghematan. --}}
  <div class="card">
    <div class="panel bad">
      <b>Cakupan biaya periode ini hanya {{ $cakupan['persen'] }}%.</b>
      Baru {{ $cakupan['terisi'] }} dari {{ $cakupan['total'] }} complaint yang punya nilai biaya, jadi angka di
      grafik berikutnya adalah biaya YANG TERCATAT — bukan biaya yang terjadi. Jangan membandingkannya dengan
      periode lain yang cakupannya berbeda.
    </div>
  </div>
@endif

@php $totalBiaya = $cakupan['biaya']; @endphp
@php $sorotan = collect($biayaKategori)->firstWhere('kategori', 'barang_rusak'); @endphp
@php $catatanBiaya = 'Satu ukuran yang digambar: biaya. Jumlah kasus ikut sebagai label, bukan sebagai batang kedua. Total '.\App\Services\GrafikLaporan::rupiah($totalBiaya).' '.$grafik->cakupanTeks($cakupan['terisi'], $cakupan['total']).'.'; @endphp

<x-grafik.batang
  judul="Biaya complaint per kategori"
  :catatan="$catatanBiaya"
  :baris="$grafik->batangBiaya()">
  <x-slot:tabel>
    <table>
      <thead><tr>
        <th>Kategori</th><th class="num">Kasus</th><th class="num">Punya nilai</th>
        <th class="num">Biaya</th><th class="num">Rata-rata</th><th class="num">Porsi biaya</th>
      </tr></thead>
      <tbody>
        @foreach($biayaKategori as $baris)
          <tr>
            <td>{{ $baris['label'] }}</td>
            <td class="num">{{ $baris['kasus'] }}</td>
            {{-- Cakupan per kategori, dan ditandai saat di bawah setengah:
                 satu kategori bisa lengkap sementara kategori lain kosong. --}}
            <td class="num">{{ $baris['terisi'] }}@if($baris['kasus'] > 0 && $baris['terisi'] / $baris['kasus'] < 0.5)<span class="muted small"> · cakupan rendah</span>@endif</td>
            <td class="num">{{ \App\Services\GrafikLaporan::rupiah($baris['biaya']) }}</td>
            {{-- Rata-rata hanya atas complaint yang punya nilai. Complaint
                 tanpa nilai bukan Rp 0, jadi tidak ikut jadi pembagi. --}}
            <td class="num">{{ $baris['rata'] === null ? '—' : \App\Services\GrafikLaporan::rupiah($baris['rata']) }}</td>
            <td class="num">{{ $totalBiaya > 0 ? round(100 * $baris['biaya'] / $totalBiaya).'%' : '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </x-slot:tabel>
</x-grafik.batang>

@php
  // Kalimat kedua WAJIB ikut, bukan hiasan: grafik ini memakai pembagi yang
  // berbeda dari grafik pertama, dan tanpa alasannya tertulis pembacanya
  // akan menyimpulkan salah satu dari keduanya keliru.
  //
  // Porsi kasus dibanding porsi biaya dihitung dari data periode yang sedang
  // dilihat — bukan kalimat tetap yang perlahan jadi salah.
  $catatanRusak = 'Sengaja tidak dibagi jumlah outlet: ini ukuran kerugian, bukan mutu — satu barang rusak tetap harus dibayar berapa pun outlet yang buka.';

  if ($sorotan && $totalBiaya > 0 && $total > 0) {
      $catatanRusak = 'Kategori yang menanggung '.round(100 * $sorotan['biaya'] / $totalBiaya)
          .'% biaya tercatat dengan '.round(100 * $sorotan['kasus'] / $total).'% kasus. '.$catatanRusak;
  }
@endphp

@php $titikRusak = $grafik->titikBarangRusak(); @endphp

<x-grafik.garis
  :judul="'Barang Rusak '.$grafik->satuan()->perSatuan().' — jumlah kasus'"
  :catatan="$catatanRusak"
  :titik="$titikRusak"
  :catatan-bawah="$grafik->keteranganPadatData($titikRusak)"
  warna="var(--danger)">
  <x-slot:tabel>
    <table>
      <thead><tr><th>{{ $kolomPeriode }}</th><th class="num">Kasus Barang Rusak</th></tr></thead>
      <tbody>
        @foreach($perPeriode as $periode)
          <tr>
            <td>{{ $periode['judul'] }}</td>
            <td class="num">{{ $periode['rusak'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </x-slot:tabel>
</x-grafik.garis>

<x-grafik.garis
  :judul="'Median waktu penyelesaian '.$grafik->satuan()->perSatuan().', dalam hari'"
  :catatan="'Median, bukan rata-rata: satu kasus 41 hari menarik rata-rata dan membuat '.mb_strtolower($grafik->satuan()->satuan()).' yang baik terlihat buruk. Garis di bawah pita berarti '.mb_strtolower($grafik->satuan()->satuan()).' itu masih di dalam ambang SLA. Complaint yang belum selesai tidak ikut dihitung, dan waktu jeda menunggu pelanggan sudah dikurangi.'"
  :titik="$grafik->titikMedian()"
  :pita="['min' => $ambang['min'], 'max' => $ambang['max'], 'label' => 'Ambang SLA '.$ambang['min'].'–'.$ambang['max'].' hari, menurut bobot']">
  <x-slot:tabel>
    <table>
      <thead><tr><th>{{ $kolomPeriode }}</th><th class="num">Median (hari)</th><th class="num">Complaint selesai</th></tr></thead>
      <tbody>
        @foreach($median as $periode)
          <tr>
            <td>{{ $periode['judul'] }}</td>
            <td class="num">{{ $periode['median'] === null ? '—' : $grafik->desimal($periode['median']) }}</td>
            <td class="num">{{ $periode['n'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </x-slot:tabel>
</x-grafik.garis>

<div class="grid g2">
  @foreach([
    ['Kategori keluhan',$byCategory,'complaint.categories'],
    ['Bobot',$byBobot,'complaint.bobot'],
    ['Layanan yang dikeluhkan',$byLayanan,'complaint.layanan'],
    ['Tindak lanjut',$byTindakLanjut,'complaint.tindak_lanjut'],
    ['Kanal masuk',$byChannel,null],
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
