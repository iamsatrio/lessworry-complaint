{{-- Grafik batang mendatar: SVG yang sudah jadi saat halaman dikirim, tanpa skrip. --}}
<div class="card fig">
  <div class="eyebrow">{{ $judul }}</div>
  <p class="fig-note">{{ $catatan }}</p>

  @if($kosong())
    <p class="muted small" style="margin:0">{{ $kosongTeks }}</p>
  @else
    <div class="g-canvas">
    <svg viewBox="{{ $viewBox() }}" role="img" aria-label="{{ $judul }}" preserveAspectRatio="xMidYMid meet">
      <title>{{ $judul }}</title>

      @foreach($bidang() as $baris)
        <text class="g-baris-lab" x="{{ $labelX() }}" y="{{ $baris['labelY'] }}" text-anchor="end">{{ $baris['label'] }}</text>
        <rect x="{{ $kiri() }}" y="{{ $baris['y'] + 4 }}" width="{{ $baris['lebar'] }}" height="19" rx="4"
              fill="{{ $warna }}"><title>{{ $baris['judul'] }}</title></rect>
        <text class="g-nilai" x="{{ $baris['teksX'] }}" y="{{ $baris['labelY'] }}">{{ $baris['teks'] }}</text>
      @endforeach
    </svg>
    </div>
  @endif

  @isset($tabel)
    <details class="g-tabel">
      <summary>Lihat angkanya sebagai tabel</summary>
      {{ $tabel }}
    </details>
  @endisset
</div>
