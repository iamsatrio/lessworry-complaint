{{--
  Kapan barang berpindah ke pelanggan, dan berapa lama setelah itu
  complaint-nya masuk. (API-48)

  Angkanya selalu datang bersama SUMBERNYA. "3 hari setelah pengambilan"
  berarti lain kalau tanggalnya diketik orang dari spreadsheet lama daripada
  kalau diambil dari jejak serah terima NEVIRA, dan yang membaca halaman ini
  berhak tahu yang mana.

  Tidak diketahui ditampilkan sebagai tidak diketahui — tanpa angka hari sama
  sekali. Menampilkan "0 hari" untuk tanggal yang tidak ada akan membuat
  keluhan yang datang sebulan kemudian terbaca datang seketika.
--}}
<div class="card">
  <div class="eyebrow">Tanggal pengambilan</div>

  @if($complaint->tanggalPengambilanDiketahui())
    @php $jarak = $complaint->jarakKomplainHari(); @endphp
    <b class="display">{{ $complaint->tanggal_pengambilan->translatedFormat('d M Y') }}</b>
    <div class="muted small">{{ $complaint->sumberTanggalPengambilanLabel() }}</div>

    <div style="margin-top:8px">
      @if($jarak === null)
        <span class="muted">Jarak hari tidak bisa dihitung.</span>
      @elseif($jarak < 0)
        Complaint masuk <b>{{ abs($jarak) }} hari sebelum</b> barang diambil.
      @else
        Complaint masuk <b>{{ $jarak }} hari</b> setelah pengambilan.
      @endif
    </div>
  @else
    <b class="display">Tanggal pengambilan tidak diketahui</b>
    <p class="muted small" style="margin:6px 0 0">
      @if($complaint->isLinkedToOrder())
        Nota ini belum menunjukkan barangnya sudah diserahkan — pengantarannya belum
        selesai, atau pelanggan belum mengambilnya. Tarik ulang notanya setelah barang
        diserahkan dan tanggalnya terisi sendiri.
      @else
        Complaint ini tidak tertaut ke nota NEVIRA, jadi tidak ada jejak serah terima
        yang bisa dibaca.
      @endif
    </p>
  @endif
</div>
