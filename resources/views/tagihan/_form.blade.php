@php $t = $tagihan ?? null; @endphp

<label for="nama">Nama tagihan <span class="req">*</span></label>
<input id="nama" name="nama" value="{{ old('nama', $t->nama ?? '') }}" maxlength="120" required autofocus>
<p class="hint">Yang kamu sebut sehari-hari: “Sewa Kelapa Gading”, “Internet Tebet”, “Langganan NEVIRA”.</p>

<label for="jumlah">Jumlah (Rp)</label>
<input id="jumlah" name="jumlah" type="number" min="0" step="1" inputmode="numeric"
       value="{{ old('jumlah', $t->jumlah ?? '') }}">
<p class="hint">
  Boleh dikosongkan. Listrik dan air berubah tiap bulan — angka karangan lebih menyesatkan
  daripada kolom kosong.
</p>

<label for="pengulangan">Pengulangan <span class="req">*</span></label>
<select id="pengulangan" name="pengulangan" required onchange="document.getElementById('kotak-bulan').hidden = this.value !== 'tahunan'">
  @foreach(['bulanan' => 'Tiap bulan', 'tahunan' => 'Tiap tahun'] as $k => $v)
    <option value="{{ $k }}" @selected(old('pengulangan', $t->pengulangan ?? 'bulanan') === $k)>{{ $v }}</option>
  @endforeach
</select>

@php $tahunan = old('pengulangan', $t->pengulangan ?? 'bulanan') === 'tahunan'; @endphp

<div id="kotak-bulan" @if(! $tahunan) hidden @endif>
  <label for="jatuh_tempo_bulan">Bulan jatuh tempo <span class="req">*</span></label>
  <select id="jatuh_tempo_bulan" name="jatuh_tempo_bulan">
    <option value="">Pilih bulan</option>
    @foreach(['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'] as $i => $nama)
      <option value="{{ $i + 1 }}" @selected((int) old('jatuh_tempo_bulan', $t->jatuh_tempo_bulan ?? 0) === $i + 1)>{{ $nama }}</option>
    @endforeach
  </select>
  <p class="hint">Hanya untuk tagihan tahunan.</p>
</div>

<label for="jatuh_tempo_hari">Tanggal jatuh tempo <span class="req">*</span></label>
<input id="jatuh_tempo_hari" name="jatuh_tempo_hari" type="number" min="1" max="31" inputmode="numeric"
       value="{{ old('jatuh_tempo_hari', $t->jatuh_tempo_hari ?? '') }}" required>
{{-- Perilaku tanggal 29–31 disebut di muka, bukan dibiarkan ditemukan sendiri
     bulan Februari nanti. (API-73) --}}
<p class="hint">
  Tanggal 1–31. Kalau bulannya tidak punya tanggal itu, jatuh temponya memakai
  <b>hari terakhir bulan tersebut</b> — tanggal 31 di bulan Februari jatuh pada 28, atau 29 di tahun kabisat.
</p>

<label for="outlet_id">Outlet</label>
<select id="outlet_id" name="outlet_id">
  <option value="">Tagihan jaringan (semua outlet)</option>
  @foreach($outlets as $o)
    <option value="{{ $o->id }}" @selected((string) old('outlet_id', $t->outlet_id ?? '') === (string) $o->id)>{{ $o->name }}</option>
  @endforeach
</select>
<p class="hint">Kosongkan untuk tagihan tingkat jaringan — sewa kantor pusat, langganan perangkat lunak.</p>
