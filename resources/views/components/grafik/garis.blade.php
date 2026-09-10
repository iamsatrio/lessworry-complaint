{{-- Grafik garis: SVG yang sudah jadi saat halaman dikirim, tanpa skrip. --}}
<div class="card fig">
  <div class="eyebrow">{{ $judul }}</div>
  <p class="fig-note">{{ $catatan }}</p>

  @if($kosong())
    <p class="muted small" style="margin:0">{{ $kosongTeks }}</p>
  @else
    <div class="g-canvas">
    <svg viewBox="{{ $viewBox() }}" role="img" aria-label="{{ $judul }}" preserveAspectRatio="xMidYMid meet">
      <title>{{ $judul }}</title>

      @foreach($sumbuY() as $garis)
        <line class="g-grid" x1="{{ $kiri() }}" x2="{{ $kananX() }}" y1="{{ $garis['y'] }}" y2="{{ $garis['y'] }}"/>
        <text class="g-axis" x="{{ $kiri() - 8 }}" y="{{ $garis['y'] + 4 }}" text-anchor="end">{{ $garis['teks'] }}</text>
      @endforeach

      @php $pitaKotak = $pitaKotak(); @endphp
      @if($pitaKotak)
        {{-- Diberi label langsung di atas bidangnya: warna tidak boleh jadi
             satu-satunya yang membedakan acuan dari datanya. --}}
        <rect class="g-pita" x="{{ $kiri() }}" y="{{ $pitaKotak['y'] }}"
              width="{{ $kananX() - $kiri() }}" height="{{ $pitaKotak['tinggi'] }}"/>
        <text class="g-pita-lab" x="{{ $kiri() + 8 }}" y="{{ $pitaKotak['labelY'] }}">{{ $pitaKotak['label'] }}</text>
      @endif

      @foreach($segmen() as $jalur)
        <path d="{{ $jalur }}" fill="none" stroke="{{ $warna }}" stroke-width="2.2"
              stroke-linejoin="round" stroke-linecap="round"/>
      @endforeach

      @foreach($simpul() as $titikGambar)
        {{-- <title> memberi keterangan saat ditunjuk tanpa satu baris skrip. --}}
        <circle cx="{{ $titikGambar['x'] }}" cy="{{ $titikGambar['y'] }}" r="4.5"
                fill="{{ $warna }}" stroke="var(--surface)" stroke-width="2"><title>{{ $titikGambar['teks'] }}</title></circle>
      @endforeach

      @foreach($labelX() as $label)
        <text class="g-axis" x="{{ $label['x'] }}" y="{{ $labelY() }}" text-anchor="middle">{{ $label['teks'] }}</text>
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
