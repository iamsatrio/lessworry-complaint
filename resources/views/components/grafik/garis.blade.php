{{-- Grafik garis: SVG yang sudah jadi saat halaman dikirim, tanpa skrip. --}}
<div class="card fig">
  <div class="eyebrow">{{ $judul }}</div>
  <p class="fig-note">{{ $catatan }}</p>

  @if($kosong())
    <p class="muted small" style="margin:0">{{ $kosongTeks }}</p>
  @else
    <div class="g-canvas">
    {{-- min-width dihitung dari jumlah titiknya, bukan angka tetap: itu yang
         menjamin tiap titik kebagian ruang layar cukup untuk sasaran 28px.
         Lihat Garis::lebarMin(). (API-62 nomor 1) --}}
    <svg viewBox="{{ $viewBox() }}" role="img" aria-label="{{ $judul }}" preserveAspectRatio="xMidYMid meet"
         style="min-width:{{ $lebarMin() }}px">
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

      @php $simpul = $simpul(); @endphp

      {{-- Titiknya digambar lebih dulu, SELURUHNYA, baru tooltipnya. Kalau
           keduanya dicampur per titik, tooltip titik ke-3 tergambar di bawah
           bulatan titik ke-4 — SVG menumpuk menurut urutan tulis, bukan
           menurut mana yang sedang ditunjuk. --}}
      @foreach($simpul as $titikGambar)
        {{-- <title> dipertahankan: cadangan untuk pembaca layar dan untuk
             keadaan CSS gagal dimuat. Yang dipakai sehari-hari tooltip di
             bawah — <title> baru muncul setelah tertunda ~1 detik dan
             tampilannya milik sistem operasi, bukan halaman ini. --}}
        <circle cx="{{ $titikGambar['x'] }}" cy="{{ $titikGambar['y'] }}" r="4.5"
                fill="{{ $warna }}" stroke="var(--surface)" stroke-width="2"><title>{{ $titikGambar['teks'] }}</title></circle>
      @endforeach

      @foreach($simpul as $titikGambar)
        @php $tip = $tooltip($titikGambar['x'], $titikGambar['y'], $titikGambar['label'], $titikGambar['nilai']); @endphp
        <g class="g-titik">
          <g class="g-tip">
            <polygon points="{{ $tip['ekor'] }}"/>
            <rect x="{{ $tip['x'] }}" y="{{ $tip['y'] }}" width="{{ $tip['lebar'] }}" height="{{ $tip['tinggi'] }}" rx="8"/>
            <text class="g-tip-lab" x="{{ $tip['teksX'] }}" y="{{ $tip['labelY'] }}">{{ $titikGambar['label'] }}</text>
            <text class="g-tip-nil" x="{{ $tip['teksX'] }}" y="{{ $tip['nilaiY'] }}">{{ $titikGambar['nilai'] }}</text>
          </g>
          {{-- Sasaran tunjuk tak terlihat. Bulatan yang tergambar berjari-jari
               4,5 satuan — di layar sentuh praktis mustahil ditunjuk, di
               tetikus pun meleset. Yang membesar sasarannya, bukan titiknya. --}}
          <circle class="g-sasaran" cx="{{ $titikGambar['x'] }}" cy="{{ $titikGambar['y'] }}"
                  r="{{ $jariSasaran() }}"/>
        </g>
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
