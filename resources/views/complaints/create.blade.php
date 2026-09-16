@extends('layouts.app')
@section('title','Catat Complaint')
{{-- Butir ringkasan galat di layout menautkan ke kolomnya lewat peta ini.
     Nama kolom datang dari StoreComplaintRequest; id-nya dari markup di bawah.
     (API-86 #1, #2) --}}
@section('galat-anchor'){!! json_encode([
  'nevira_transaction_number' => 'nv',
  'nevira_service_index'      => 'barang',
  'nota_exemption'            => 'exempt',
  'outlet_id'                 => 'out',
  'category'                  => 'cat',
  'sub_category'              => 'sub',
  'bobot'                     => 'bob',
  'layanan'                   => 'lay',
  'description'               => 'desc',
  'channel'                   => 'ch',
  'reporter_name'             => 'rn',
  'reporter_phone'            => 'rp',
]) !!}@endsection
@section('content')
<div class="eyebrow">Complaint baru</div>
<h1>Catat keluhan pelanggan</h1>
<p class="lede">Isi seadanya dulu — yang penting keluhannya masuk. Detail bisa dilengkapi setelah pelanggan pergi.</p>

@php
  // Isian yang dikembalikan LANGSUNG oleh server, bukan lewat flash session.
  // Saat sesinya yang mati, flash ikut mati bersamanya — lihat SesiKedaluwarsa.
  $kembali = $kembali ?? [];
  $gagal   = $gagal ?? null;
  $nilai   = fn (string $k, $d = null) => $kembali[$k] ?? old($k, $d);

  // Server sudah mengembalikan isian: draft lama tidak boleh ikut ditawarkan,
  // nanti dua sumber isian bertabrakan di layar yang sama.
  $terisi = filled($kembali) || filled(old('description')) || filled(old('reporter_name'));
@endphp

@if($gagal)
  {{-- Form yang gagal disimpan tidak boleh terlihat seperti form kosong siap
       pakai. Blok ini dicetak di halaman yang dibalas, bukan dititipkan ke sesi. --}}
  <div class="err" id="gagal-simpan" role="alert">
    <b style="font-size:16px">Complaint ini BELUM tersimpan.</b>
    <p style="margin:8px 0 0">{{ $gagal }}</p>
  </div>
@endif

{{-- Draft yang dipulihkan tidak boleh senyap. Isian yang muncul sendiri tidak
     bisa dibedakan dari kolom yang memang sudah begitu — dan complaint bisa
     tersimpan atas nama pelapor pelanggan sebelumnya. --}}
<div class="flash warn" id="draft-tawar" style="display:none">
  <b>Ada isian yang belum tersimpan dari sebelumnya.</b>
  <div class="small" id="draft-kapan"></div>
  <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
    <button type="button" class="ghost shrink" id="draft-lanjut">Lanjutkan isian itu</button>
    <button type="button" class="ghost shrink" id="draft-buang">Buang, mulai baru</button>
  </div>
</div>

<form method="POST" action="{{ route('complaints.store') }}" enctype="multipart/form-data" id="f">
@csrf

{{-- NOTA LEBIH DULU.

     Blok ini dulu berada di kartu kedua, sekitar 1362px dari atas — dua layar
     di bawah titik masuk pada 390px. Petunjuknya sendiri berbunyi "isi ini
     lebih dulu", tapi kasir yang membaca dari atas ke bawah sudah melewati
     empat select dan satu textarea sebelum sampai ke kalimat yang menyuruhnya
     kembali ke atas — jadi pengisian otomatis yang sudah dibangun tidak
     pernah terpakai.

     Urutan sekarang mengikuti kejadian nyata di konter: pelanggan menyodorkan
     struk → nota dicek → outlet, nama, dan telepon terisi sendiri → kasir
     tinggal mengetik keluhannya. (API-38 #5) --}}
<div class="card">
  <div class="eyebrow">Mulai dari notanya</div>

  {{-- Tempel, jangan ketik. (API-26)

       Alasannya bukan "24 karakter terlalu panjang": dari 500 nomor nota di
       riwayat complaint, NOL berformat INV/…. Tim menulis angka pendek —
       4348, "2138 (Juli)", 929/1 — dan angka pendek itu tidak unik; 132
       baris membubuhkan nama bulan dengan tangan justru untuk membedakannya.
       Jadi kolom di bawah meminta bentuk yang belum pernah ditulis siapa pun
       dalam 545 kejadian.

       Pelanggan menerima nota elektronik lewat WhatsApp dan isinya bisa
       disalin. Kotak ini mengambil pengenalnya sendiri.

       Tanpa atribut name: isi tempelan TIDAK PERNAH dikirim ke server. Di
       dalamnya ada alamat, telepon outlet, dan saldo deposit pelanggan —
       yang berangkat hanya nomor notanya. --}}
  <label for="tempel">Tempel nota WhatsApp di sini</label>
  <textarea id="tempel" rows="3" autocomplete="off" spellcheck="false"
            placeholder="Salin seluruh pesan nota dari WhatsApp, tempel di sini. Nomor notanya diambil sendiri."></textarea>
  <div id="tempel-box" class="panel" style="display:none"></div>
  <p class="hint">Tidak perlu dirapikan — tempel apa adanya. Isinya tidak ikut tersimpan; yang diambil hanya nomor notanya.</p>

  <input type="hidden" id="webstruk" name="nevira_webstruk_token" value="{{ $nilai('nevira_webstruk_token') }}">

  <label for="nv">Nomor nota NEVIRA <span class="req">*</span></label>
  <div style="display:flex;gap:10px">
    <input id="nv" name="nevira_transaction_number" value="{{ $nilai('nevira_transaction_number') }}"
           @error('nevira_transaction_number') aria-invalid="true" aria-describedby="nv-error" @enderror
           placeholder="Salin dari struk, mis. INV/118/1787749345365/1">
    <button type="button" class="ghost shrink" id="cek">Cek</button>
  </div>
  @error('nevira_transaction_number')<p class="err-field" id="nv-error">{{ $message }}</p>@enderror
  <div id="nvbox" class="panel" style="display:none"></div>
  <p class="hint">Cek notanya lebih dulu — outlet, nama, dan telepon pelapor terisi sendiri dari data pelanggan pada nota.</p>

  {{-- Barang yang dikeluhkan. Kosong dan tersembunyi sampai notanya
       diperiksa DAN ternyata berisi lebih dari satu baris layanan: nota
       satu layanan tidak menambah satu langkah pun bagi kasir yang sedang
       melayani antrean. (API-51)

       Yang dirender server hanya pilihan yang sudah terpilih sebelumnya —
       supaya simpan yang gagal tidak menghapus barang yang sudah dipilih.
       Daftar penuhnya disusun skrip dari jawaban pemeriksaan nota.

       Ikut naik ke kartu pertama bersama notanya: isinya datang dari
       pemeriksaan nota, jadi memisahkannya dua kartu ke bawah mengulang
       persis kesalahan yang diperbaiki API-38 #5. --}}
  @php $barangDipilih = $nilai('nevira_service_index'); @endphp
  <div id="barang-blok" @style(['display:none' => blank($barangDipilih)])>
    <label for="barang">Keluhannya tentang barang yang mana</label>
    <select id="barang" name="nevira_service_index"
            @error('nevira_service_index') aria-invalid="true" aria-describedby="barang-error" @enderror>
      <option value="">Seluruh nota</option>
      @if(filled($barangDipilih))
        <option value="{{ $barangDipilih }}" selected>Barang ke-{{ $barangDipilih }}</option>
      @endif
    </select>
    @error('nevira_service_index')<p class="err-field" id="barang-error">{{ $message }}</p>@enderror
    <p class="hint">
      Bawaannya seluruh nota — tidak perlu diubah kalau sedang buru-buru. Kalau barangnya sudah
      jelas, memilihnya membuat penelusuran pelaku nanti mendahulukan yang mengerjakan barang itu.
    </p>
  </div>

  <label for="exempt">Kalau tidak ada notanya, pilih alasannya</label>
  <select id="exempt" name="nota_exemption"
          @error('nota_exemption') aria-invalid="true" aria-describedby="exempt-error" @enderror>
    <option value="">— complaint ini punya nomor nota —</option>
    @foreach(config('complaint.nota_exemptions') as $k=>$v)
      <option value="{{ $k }}" @selected($nilai('nota_exemption')===$k)>{{ $v }}</option>
    @endforeach
  </select>
  @error('nota_exemption')<p class="err-field" id="exempt-error">{{ $message }}</p>@enderror

  @if(!auth()->user()->isKasir())
    <label for="out">Outlet</label>
    <select id="out" name="outlet_id"
            @error('outlet_id') aria-invalid="true" aria-describedby="out-error" @enderror>
      <option value="">Terisi sendiri dari nota</option>
      @foreach($outlets as $o)<option value="{{ $o->id }}" @selected($nilai('outlet_id')==$o->id)>{{ $o->name }}</option>@endforeach
    </select>
    @error('outlet_id')<p class="err-field" id="out-error">{{ $message }}</p>@enderror
    <p class="hint" id="out-hint" style="display:none"></p>
  @endif
</div>

<div class="card">
  <div class="eyebrow">Keluhannya apa</div>
  <div class="row">
    <div><label for="cat">Kategori <span class="req">*</span></label>
      <select id="cat" name="category" required
              @error('category') aria-invalid="true" aria-describedby="cat-error" @enderror>
        {{-- Tanpa opsi kosong, opsi pertama selalu terpilih dan `required`
             tidak menuntut apa pun: complaint salah kategori tersimpan diam-diam.
             Kategori adalah kolom yang menentukan pola keluhan bisa dilihat
             atau tidak, dan kategori yang salah terlihat seperti data benar. --}}
        <option value="" disabled @selected(blank($nilai('category')))>— pilih kategori —</option>
        @foreach(config('complaint.categories') as $k=>$v)
          <option value="{{ $k }}" @selected($nilai('category')===$k)>{{ $v['label'] }}</option>
        @endforeach
      </select>
      @error('category')<p class="err-field" id="cat-error">{{ $message }}</p>@enderror
    </div>
    <div><label for="sub">Rincian</label>
      <select id="sub" name="sub_category"
              @error('sub_category') aria-invalid="true" aria-describedby="sub-error" @enderror></select>
      @error('sub_category')<p class="err-field" id="sub-error">{{ $message }}</p>@enderror
    </div>
    <div><label for="lay">Layanan <span class="req">*</span></label>
      <select id="lay" name="layanan" required
              @error('layanan') aria-invalid="true" aria-describedby="lay-error" @enderror>
        <option value="" disabled @selected(blank($nilai('layanan')))>— pilih layanan —</option>
        @foreach(config('complaint.layanan') as $k=>$v)
          <option value="{{ $k }}" @selected($nilai('layanan')===$k)>{{ $v }}</option>
        @endforeach
      </select>
      @error('layanan')<p class="err-field" id="lay-error">{{ $message }}</p>@enderror
    </div>
  </div>

  {{-- Bobot memakai kosakata tim: Ringan / Sedang / Berat. Tidak ada yang
       terpilih lebih dulu — bobot menentukan tenggat DAN siapa yang boleh
       menutup, jadi ia harus dipilih, bukan kebetulan.

       Radio, bukan select: tiga opsi terpapar seluruhnya seketika dan cuma
       butuh satu ketukan, sementara select menyembunyikan dua dari tiga
       sampai dibuka. Untuk kolom yang konsekuensinya perlu dibandingkan
       — tenggat DAN wewenang penutupan — menyembunyikannya justru mahal.

       Kategori (8 opsi) dan Layanan (6 opsi) sengaja TETAP select: radio
       sebanyak itu menambah panjang halaman yang sudah jadi masalah
       tersendiri. Batasnya di sekitar 5 opsi, bukan keseragaman. (API-86 #4)

       Keluar dari .row di atas karena tingginya tiga baris: di dalam flex
       row yang align-items-nya flex-end, ia menyisakan rongga di atas tiga
       kolom lainnya. --}}
  <fieldset class="pilihan" @error('bobot') aria-describedby="bob-error" @enderror>
    <legend>Bobot <span class="req">*</span></legend>
    @foreach(config('complaint.bobot') as $k=>$v)
      <label class="pick">
        <input type="radio" name="bobot" value="{{ $k }}" required
               @if($loop->first) id="bob" @endif
               @checked($nilai('bobot')===$k)>
        {{ $v }}
      </label>
    @endforeach
  </fieldset>
  @error('bobot')<p class="err-field" id="bob-error">{{ $message }}</p>@enderror

  <label for="desc">Isi keluhan <span class="req">*</span></label>
  <textarea id="desc" name="description" required
    @error('description') aria-invalid="true" aria-describedby="desc-error" @enderror
    placeholder="Tulis keluhan pelanggan apa adanya, pakai kalimatnya sendiri.">{{ $nilai('description') }}</textarea>
  @error('description')<p class="err-field" id="desc-error">{{ $message }}</p>@enderror
  <p class="hint">Tulis apa yang pelanggan katakan, bukan tafsiranmu. Itu yang menolong saat kasusnya ditelusuri nanti.</p>

  <label for="att">Foto bukti</label>
  <input id="att" type="file" name="attachments[]" multiple accept="image/*"
    @error('attachments.*') aria-invalid="true" aria-describedby="att-error" @enderror>
  {{-- Kuncinya attachments.0, attachments.1, ...; tiap berkas bisa gagal
       sendiri-sendiri, jadi semuanya dicetak di bawah satu kolom. --}}
  @error('attachments.*')
    <div id="att-error">@foreach($errors->get('attachments.*') as $pesanBerkas)@foreach($pesanBerkas as $pesan)<p class="err-field">{{ $pesan }}</p>@endforeach @endforeach</div>
  @enderror
  <p class="hint">Untuk keluhan hasil cuci dan barang rusak, foto hampir selalu menentukan.</p>
</div>

<div class="card">
  <div class="eyebrow">Siapa yang melapor</div>
  @php $kanal = $nilai('channel', auth()->user()->defaultChannel()); @endphp
  {{-- Kanal ada tiga, peran hanya dua: WA Outlet diterima kasir juga.
       Jadi yang disimpulkan dari peran adalah NILAI BAWAANNYA, bukan
       kanalnya — kolomnya tetap tampil, ketiga opsinya tetap bisa
       dipilih, dan pilihan manual menimpa bawaan. (API-38 #4)

       Tiga opsi, jadi radio dan bukan select (API-86 #4). Opsi kosong yang
       dulu dipasang untuk peran tanpa bawaan tidak diperlukan lagi: pada
       radio, "belum memilih" adalah keadaan yang memang tidak ada
       centangnya — tidak ada opsi pertama yang terpilih diam-diam. `required`
       di tiap radio menuntut satu di antaranya dipilih. --}}
  <fieldset class="pilihan" @error('channel') aria-describedby="ch-error" @enderror>
    <legend>Masuk lewat <span class="req">*</span></legend>
    @foreach(config('complaint.channels') as $k=>$v)
      <label class="pick">
        <input type="radio" name="channel" value="{{ $k }}" required
               @if($loop->first) id="ch" @endif
               @checked($kanal===$k)>
        {{ $v }}
      </label>
    @endforeach
  </fieldset>
  @error('channel')<p class="err-field" id="ch-error">{{ $message }}</p>@enderror
  @if(filled(auth()->user()->defaultChannel()) && blank($nilai('channel')))
    <p class="hint">Terisi dari peranmu. Ganti kalau keluhan ini sebenarnya masuk lewat kanal lain.</p>
  @endif

  <label for="rn">Nama pelapor <span class="req">*</span></label>
  <input id="rn" name="reporter_name" value="{{ $nilai('reporter_name') }}" required
    @error('reporter_name') aria-invalid="true" aria-describedby="rn-error" @enderror>
  @error('reporter_name')<p class="err-field" id="rn-error">{{ $message }}</p>@enderror
  <label for="rp">Nomor telepon</label>
  <input id="rp" name="reporter_phone" value="{{ $nilai('reporter_phone') }}" inputmode="tel"
    @error('reporter_phone') aria-invalid="true" aria-describedby="rp-error" @enderror
    placeholder="08xxxxxxxxxx">
  @error('reporter_phone')<p class="err-field" id="rp-error">{{ $message }}</p>@enderror
  <div id="pakai" style="display:none;margin-top:10px">
    <button type="button" class="ghost" id="btn-pakai" style="padding:9px 16px;min-height:40px;font-size:13.5px">
      Pakai data pelanggan dari nota
    </button>
    <p class="hint" id="pakai-hint"></p>
  </div>
  <p class="hint">
    Pelapor tidak selalu pemilik order — bisa saja yang mengantarkan. Kalau berbeda, tulis siapa yang benar-benar melapor.
  </p>
</div>

{{-- Sudah saya tangani di tempat. (API-26)

     Kasir mencatat SETELAH menangani — status `open` muncul nol kali dari
     545 baris riwayat, jadi sheet-nya hanya diisi saat perkaranya sudah
     selesai. Memaksa dua langkah (simpan, cari lagi, buka, isi, tutup)
     untuk 52% kasus berarti mewarisi kebiasaan yang sama.

     Kolom penyelesaiannya TERTUTUP sampai centangnya dipakai: complaint
     yang belum selesai adalah jalur lain, dan waktu mengisinya tidak boleh
     bertambah satu detik pun oleh kolom yang tidak ia perlukan. --}}
@php $diTempat = (bool) $nilai('tangani_di_tempat'); @endphp
<div class="card">
  <div class="eyebrow">Sudah selesai di tempat?</div>
  <label for="ditempat" style="display:flex;gap:10px;align-items:center;text-transform:none;font-size:15px;color:var(--ink);letter-spacing:0">
    <input type="checkbox" id="ditempat" name="tangani_di_tempat" value="1" @checked($diTempat)>
    <span>Sudah saya tangani di tempat</span>
  </label>
  <p class="hint">Centang kalau keluhannya sudah beres sebelum kamu mencatatnya. Satu kali simpan, tiketnya langsung tertutup.</p>

  <div id="ditempat-blok" @style(['display:none' => ! $diTempat])>
    <label for="res">Apa yang kamu lakukan <span class="req">*</span></label>
    <textarea id="res" name="resolution" placeholder="Mis. Dicuci ulang saat itu juga, pelanggan menunggu dan setuju.">{{ $nilai('resolution') }}</textarea>

    <div class="row">
      <div><label for="tl">Tindak lanjut <span class="req">*</span></label>
        <select id="tl" name="tindak_lanjut">
          <option value="" disabled @selected(blank($nilai('tindak_lanjut')))>— pilih tindak lanjut —</option>
          @foreach(config('complaint.tindak_lanjut') as $k=>$v)
            <option value="{{ $k }}" @selected($nilai('tindak_lanjut')===$k)>{{ $v }}</option>
          @endforeach
        </select>
      </div>
      {{-- Petunjuknya DI LUAR baris: .row meratakan bawah, jadi kolom yang
           punya hint di bawahnya jadi lebih tinggi dan kolom sebelahnya
           terdorong turun. --}}
      <div><label for="komp">Kompensasi (Rp)</label>
        <input id="komp" name="compensation_amount" inputmode="numeric" value="{{ $nilai('compensation_amount') }}" placeholder="Kosongkan kalau tidak ada">
      </div>
    </div>

    {{-- Batasnya disebutkan DI DEPAN, bukan hanya setelah ditolak. Kasir
         yang tahu batasnya sebelum mengetik tidak perlu mengulang. --}}
    @if(auth()->user()->isKasir())
      <p class="hint" id="ditempat-batas">
        Kasir menutup sendiri complaint berbobot <b>Ringan</b> dengan kompensasi sampai
        <b>Rp {{ number_format(auth()->user()->compensationLimit(), 0, ',', '.') }}</b>.
        Di luar itu keluhannya tetap tercatat lengkap dengan catatanmu, dan Customer Care yang menutup.
      </p>
    @endif
  </div>
</div>

<div class="card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
  <button>{{ $gagal ? 'Coba Simpan Lagi' : 'Simpan Complaint' }}</button>
  <a href="{{ route('complaints.index') }}" class="btn ghost">Batal</a>
  <span class="muted small" style="flex:1;min-width:200px">Nomor tiket dibuat otomatis setelah disimpan.</span>
</div>
</form>

<script>
// Satu elemen yang hilang tidak boleh mematikan seluruh halaman. Sebelumnya
// select alasan sempat absen dari markup sementara skrip tetap memanggilnya,
// sehingga skripnya berhenti dan tombol Cek jadi tidak bereaksi sama sekali.
const el = id => document.getElementById(id);

const form   = el('f');
const cat    = el('cat');
const sub    = el('sub');
const exempt = el('exempt');
const nvInput= el('nv');
const btn    = el('cek');
const box    = el('nvbox');
const nm     = el('rn');
const tp     = el('rp');
const lay    = el('lay');
const barang = el('barang');
const barangBlok = el('barang-blok');
const out    = el('out');
const outHint= el('out-hint');
const pakai  = el('pakai');
const btnPakai = el('btn-pakai');
const pakaiHint= el('pakai-hint');
let pelangganNota = null;

/* ---------- Sub-kategori mengikuti kategori ---------- */
const SUB = @json(collect(config('complaint.categories'))->map(fn ($c) => $c['sub']));

function fillSub(keep){
  if(!cat || !sub) return;
  const list = SUB[cat.value] || [];
  sub.innerHTML = '<option value="">Tidak dirinci</option>' + list.map(x => `<option>${x}</option>`).join('');
  if (keep) sub.value = keep;
}
if (cat) cat.addEventListener('change', () => fillSub());
fillSub(@json($nilai('sub_category')));

/* ---------- Draft lokal: isian tidak hilang kalau koneksi outlet putus ---------- */
// Kunci diumumkan layout dan terikat pengguna (User::draftKey). Perangkat
// outlet dipakai bergantian: kunci bersama membuat keluhan dan identitas
// pelanggan yang dicatat petugas sebelumnya muncul di form petugas berikutnya.
const KEY        = window.LW_DRAFT_KEY || 'lw_complaint_draft';
const tawar      = el('draft-tawar');
const draftKapan = el('draft-kapan');
const btnLanjut  = el('draft-lanjut');
const btnBuang   = el('draft-buang');
const TERISI     = @json($terisi);

function bacaDraft(){
  try{ return JSON.parse(localStorage.getItem(KEY) || 'null'); }catch(e){ return null; }
}
function buangDraft(){ try{ localStorage.removeItem(KEY); }catch(e){} }

// Checkbox disimpan lewat `checked`, bukan `value`. Nilai sebuah checkbox
// selalu "1" entah ia tercentang atau tidak, jadi memulihkannya lewat `value`
// tidak mencentang apa pun. `tangani_di_tempat` adalah checkbox pertama di
// form ini — sebelum API-26 tidak ada satu pun, jadi celahnya baru.
//
// Akibatnya kalau dibiarkan: kasir mencentang, mengetik penyelesaiannya,
// halamannya hilang. Ia kembali dan memilih "Lanjutkan isian itu"; resolution
// dan tindak_lanjut terisi DI DALAM BLOK YANG TERSEMBUNYI, controller
// membuang keduanya karena centangnya tidak ikut terkirim, dan yang
// diketiknya hilang tanpa sepatah kata. (Tinjauan PR #32)
function simpanDraft(){
  if (!form) return;
  const isi = {};
  for (const f of form.elements) {
    if (!f.name || f.type === 'file' || f.type === 'hidden') continue;
    // Tiap radio punya value-nya sendiri terlepas dari tercentang atau tidak.
    // Tanpa saringan ini, radio TERAKHIR dalam grup yang tersimpan ke draft —
    // kasir memilih Ringan, draftnya pulang sebagai Berat.
    if (f.type === 'radio' && !f.checked) continue;
    isi[f.name] = f.type === 'checkbox' ? f.checked : f.value;
  }
  try{ localStorage.setItem(KEY, JSON.stringify({isi, waktu: Date.now()})); }catch(e){}
}

function pakaiDraft(d){
  if (!d || !d.isi || !form) return;
  for (const [k,v] of Object.entries(d.isi)) {
    const f = form.elements[k];
    if (!f || f.type === 'file') continue;
    if (f.type === 'checkbox') f.checked = !!v; else f.value = v;
  }
  if (d.isi.category && cat) { cat.value = d.isi.category; fillSub(d.isi.sub_category); }
  // Centang yang pulih tidak memicu event 'change', jadi bloknya harus
  // dibuka dari sini — kalau tidak, isian yang barusan dipulihkan duduk di
  // balik blok tertutup.
  tampilkanPenyelesaian();
}

if (form) {
  const draft = bacaDraft();
  const adaIsi = !!(draft && draft.isi && Object.values(draft.isi).some(v => v && String(v).trim() !== ''));

  // Ditawarkan, tidak diterapkan diam-diam.
  if (adaIsi && !TERISI && tawar) {
    tawar.style.display = 'block';
    if (draftKapan && draft.waktu) {
      draftKapan.textContent = 'Tersimpan di perangkat ini pada ' + new Date(draft.waktu).toLocaleString('id-ID') + '.';
    }
  }

  if (btnLanjut) btnLanjut.addEventListener('click', () => {
    pakaiDraft(draft);
    if (tawar) tawar.style.display = 'none';
  });
  if (btnBuang) btnBuang.addEventListener('click', () => {
    buangDraft();
    if (tawar) tawar.style.display = 'none';
  });

  form.addEventListener('input', simpanDraft);
  form.addEventListener('change', simpanDraft);
  // Draft TIDAK dihapus saat form dikirim: dikirim bukan berarti tersimpan.
  // Penghapusannya dilakukan layout setelah server memastikan complaint
  // punya nomor tiket.
}

/* ---------- Nota dan alasan tidak boleh terisi dua-duanya ---------- */
if (exempt) {
  exempt.addEventListener('change', function(){
    if (this.value && nvInput) {
      nvInput.value = '';
      if (box) box.style.display = 'none';
      // Tanpa nota tidak ada barang yang bisa ditunjuk.
      isiBarang([]);
    }
  });
}

// Keluhan telat jemput memang belum punya nota — disarankan, tidak diterapkan diam-diam.
const NO_NOTA = @json(config('complaint.no_nota_yet'));
function saranPengecualian(){
  if (!cat || !exempt) return;
  const list = NO_NOTA[cat.value] || [];
  const cocok = list.length && (list.includes(sub?.value) || !sub?.value);
  if (cocok && !exempt.value && !nvInput?.value) exempt.value = 'belum_terbit';
}
if (cat) cat.addEventListener('change', saranPengecualian);
if (sub) sub.addEventListener('change', saranPengecualian);

/* ---------- Cek nota ke NEVIRA ---------- */
const rupiah = n => n == null ? '—' : 'Rp ' + Number(n).toLocaleString('id-ID');
let terakhirDicek = '';

async function cekNota(){
  if (!nvInput || !box) return;
  const id = nvInput.value.trim();
  if (!id || id === terakhirDicek) return;
  terakhirDicek = id;

  if (btn) { btn.disabled = true; btn.textContent = 'Mencari…'; }
  box.style.display = 'block'; box.className = 'panel'; box.textContent = 'Mencari nota di NEVIRA…';

  try{
    const r = await fetch(`{{ route('nevira.lookup') }}?id=${encodeURIComponent(id)}`, {headers:{'Accept':'application/json'}});
    const j = await r.json();

    if (j.ok) {
      const d = j.data;
      // Kolom tetap memegang nomor nota. Id internal NEVIRA tidak pernah
      // dikirim ke browser, apalagi ditulis balik ke layar.
      if (d.invoice) nvInput.value = d.invoice;
      terakhirDicek = nvInput.value;
      if (exempt) exempt.value = '';
      // Kolom kosong diisi sendiri. Yang sudah diketik petugas TIDAK ditimpa —
      // pelapor belum tentu pemilik order. Kalau isinya berbeda, tawarkan
      // tombol supaya petugas bisa memilih secara sadar.
      pelangganNota = {nama: d.customer_name || '', telp: d.customer_phone || ''};

      // Outlet ditentukan dari nota. Yang dipilih petugas tidak ditimpa —
      // complaint bisa saja dilaporkan di outlet lain daripada tempat cuci.
      if (out && d.outlet_id) {
        const ada = [...out.options].some(o => o.value == d.outlet_id);
        if (ada && !out.value) {
          out.value = d.outlet_id;
          if (outHint) { outHint.style.display='block'; outHint.textContent = 'Terisi dari nota: ' + (d.outlet_name || ''); }
        } else if (ada && out.value != d.outlet_id && outHint) {
          outHint.style.display='block';
          outHint.textContent = 'Nota ini dari outlet ' + (d.outlet_name || '') + ' — berbeda dari pilihanmu.';
        }
      } else if (out && !d.outlet_id && d.outlet_name && outHint) {
        outHint.style.display='block';
        outHint.textContent = 'Outlet "' + d.outlet_name + '" belum terdaftar di sistem ini. Jalankan nevira:sync-outlets.';
      }
      if (nm && pelangganNota.nama && !nm.value) nm.value = pelangganNota.nama;
      if (tp && pelangganNota.telp && !tp.value) tp.value = pelangganNota.telp;
      tawarkanPakai();
      isiBarang(d.services);

      let umur = '';
      if (d.created_at) {
        const hari = Math.floor((Date.now() - new Date(d.created_at)) / 86400000);
        if (hari > 30) umur = `<div style="margin-top:8px;font-weight:700">Nota ini berumur ${hari} hari — lebih dari 1 bulan.</div>`;
      }

      box.className = 'panel good';
      box.innerHTML = `<b>Order ketemu</b><br>
        Nota ${d.invoice ?? '—'} · ${d.outlet_name ?? 'outlet tidak tercatat'}<br>
        ${d.customer_name ?? 'Nama pelanggan tidak tercatat'}${d.customer_phone ? ' · ' + d.customer_phone : ''}<br>
        ${rupiah(d.grand_total)} · ${d.status ?? '—'} · ${d.payment_status ?? '—'}
        <div style="margin-top:8px;font-size:13px">Cocokkan dengan struk pelanggan sebelum menyimpan.</div>${umur}`;
    } else {
      box.className = 'panel bad';
      box.textContent = j.message;
    }
  }catch(e){
    box.className = 'panel bad';
    box.textContent = 'Server tidak merespons. Simpan complaint dulu — nomor nota bisa dipasang setelah ini.';
  }finally{
    if (btn) { btn.disabled = false; btn.textContent = 'Cek'; }
  }
}

/* ---------- Barang yang dikeluhkan ---------- */
// Satu nota bisa berisi sepuluh sprei, masing-masing dengan rantai
// pengerjaannya sendiri. Keluhan pelanggan hampir selalu tentang satu
// barang, jadi complaint boleh menunjuk barisnya — tapi pilihannya hanya
// muncul kalau memang ada yang perlu dipilih. (API-51)
let barisLayanan = [];

function pakaiLayanan(kunci, timpa){
  if (!lay || !kunci) return;
  if (!timpa && lay.value) return;
  if ([...lay.options].some(o => o.value === kunci)) lay.value = kunci;
}

function isiBarang(services){
  barisLayanan = Array.isArray(services) ? services : [];
  if (!barang || !barangBlok) return;

  const sebelum = barang.value;

  // Nama barang datang dari NEVIRA: dipasang lewat new Option, bukan
  // innerHTML, supaya isinya tidak pernah dibaca sebagai markup.
  barang.innerHTML = '';
  barang.add(new Option('Seluruh nota', ''));

  if (barisLayanan.length < 2) {
    // Tidak ada yang perlu dipilih. Pilihan berisi satu jawaban tetap satu
    // hal lagi yang harus dibaca kasir, jadi blok ini tidak ditampilkan.
    barangBlok.style.display = 'none';
    barang.value = '';
    if (barisLayanan.length === 1) pakaiLayanan(barisLayanan[0].layanan, false);
    return;
  }

  // Sebutannya disusun server: nama layanan NEVIRA belum dipastikan ada, dan
  // yang tampil kalau namanya tidak ada tetap harus bisa dicocokkan dengan
  // struk — nomor urut dan jumlahnya, bukan kode mentah. (API-51)
  barisLayanan.forEach(s => barang.add(
    new Option(s.label || ('Barang ke-' + s.index), String(s.index))
  ));

  // Bawaannya seluruh nota; pilihan sebelumnya dipertahankan kalau notanya
  // memang masih punya barisnya.
  barang.value = [...barang.options].some(o => o.value === sebelum) ? sebelum : '';
  barangBlok.style.display = 'block';
}

if (barang) barang.addEventListener('change', function(){
  const baris = barisLayanan.find(s => String(s.index) === this.value);
  // Dipilih sendiri oleh petugas: kolom layanan boleh ditimpa, dan tetap
  // bisa disunting lagi setelahnya.
  if (baris) pakaiLayanan(baris.layanan, true);
});

function tawarkanPakai(){
  if (!pakai || !pelangganNota) return;
  const beda = (pelangganNota.nama && nm && nm.value !== pelangganNota.nama)
            || (pelangganNota.telp && tp && tp.value !== pelangganNota.telp);
  pakai.style.display = beda ? 'block' : 'none';
  if (beda && pakaiHint) {
    pakaiHint.textContent = 'Pada nota tercatat atas nama ' + (pelangganNota.nama || '—')
      + (pelangganNota.telp ? ' · ' + pelangganNota.telp : '') + '.';
  }
}
if (btnPakai) btnPakai.addEventListener('click', () => {
  if (!pelangganNota) return;
  if (nm && pelangganNota.nama) nm.value = pelangganNota.nama;
  if (tp && pelangganNota.telp) tp.value = pelangganNota.telp;
  tawarkanPakai();
});
if (nm) nm.addEventListener('input', tawarkanPakai);
if (tp) tp.addEventListener('input', tawarkanPakai);

if (btn)     btn.addEventListener('click', () => { terakhirDicek = ''; cekNota(); });
if (nvInput) {
  nvInput.addEventListener('blur', cekNota);
  nvInput.addEventListener('paste', () => setTimeout(cekNota, 60));
}

/* ---------- Tempel nota WhatsApp (API-26) ----------
   Pengambilan dilakukan DI SINI, di peramban, bukan di server: teks
   tempelan memuat alamat, telepon outlet, dan saldo deposit pelanggan, dan
   yang tidak pernah dikirim tidak bisa bocor, tidak bisa tercatat di log,
   dan tidak bisa tersimpan karena kelalaian nanti.

   Polanya datang dari App\Support\PolaNota supaya PHP dan peramban tidak
   punya dua versi yang berbeda diam-diam. */
const POLA   = @json(\App\Support\PolaNota::untukPeramban());
const tempel = el('tempel');
const tempelBox = el('tempel-box');
const webstruk  = el('webstruk');

// `isi` masuk sebagai innerHTML, dan yang disisipkan pemanggilnya memuat
// hasil.invoice serta hasil.masuk. Aman selama POLA hanya meloloskan angka,
// garis miring, dan tanda hubung — keamanannya bergantung pada pola di
// PolaNota, bukan pada apa pun di fungsi ini. Melonggarkan pola INV di sana
// berarti membaca ulang baris ini lebih dulu. (Tinjauan PR #32)
function kabarTempel(kelas, isi){
  if (!tempelBox) return;
  tempelBox.style.display = 'block';
  tempelBox.className = 'panel ' + kelas;
  tempelBox.innerHTML = isi;
}

function ambilDariTempelan(teks){
  // Panjang diperiksa SEBELUM regex apa pun jalan. Tempelan raksasa ditolak
  // sebagai kalimat, bukan dijalankan sampai halamannya menggantung.
  if (teks.length > POLA.maks) {
    return {terlaluPanjang: true};
  }
  const satu = (p, k) => { const m = teks.match(new RegExp(p)); return m ? m[k || 1] : null; };
  return {
    terlaluPanjang: false,
    invoice:  satu(POLA.inv),
    webstruk: satu(POLA.webstruk),
    masuk:    satu(POLA.masuk),
  };
}

function prosesTempelan(){
  if (!tempel) return;
  const teks = tempel.value;
  if (!teks.trim()) { if (tempelBox) tempelBox.style.display = 'none'; return; }

  const hasil = ambilDariTempelan(teks);

  if (hasil.terlaluPanjang) {
    // Ditolak dengan kalimat, dan kotaknya dikosongkan supaya teks raksasa
    // itu tidak menggantung di memori halaman.
    tempel.value = '';
    kabarTempel('bad', 'Teks yang ditempel terlalu panjang (lebih dari '
      + POLA.maks.toLocaleString('id-ID') + ' karakter). '
      + 'Tempel hanya pesan notanya, atau ketik nomor notanya di bawah.');
    return;
  }

  if (hasil.invoice) {
    if (nvInput) nvInput.value = hasil.invoice;
    if (exempt)  exempt.value = '';
    if (webstruk) webstruk.value = hasil.webstruk || '';
    // Teks mentahnya dibuang begitu pengenalnya diambil. Kotaknya tidak
    // menyimpan alamat dan saldo deposit lebih lama dari yang diperlukan.
    tempel.value = '';
    kabarTempel('good', '<b>Nomor nota terambil</b><br>' + hasil.invoice
      + (hasil.masuk ? ' · masuk ' + hasil.masuk : '')
      + '<div style="margin-top:6px;font-size:13px">Memeriksa ke NEVIRA…</div>');
    terakhirDicek = '';
    cekNota();
    return;
  }

  // Tidak ketemu berarti tidak ketemu. Angka pendek TIDAK diterima sebagai
  // gantinya: 132 baris di riwayat membubuhkan nama bulan dengan tangan
  // justru karena angka pendek tidak unik, dan menebaknya akan menautkan
  // complaint ke order orang lain.
  if (hasil.webstruk && webstruk) webstruk.value = hasil.webstruk;
  kabarTempel('bad', '<b>Nomor nota tidak ditemukan di teks yang ditempel.</b>'
    + (hasil.webstruk ? '<br>Tautan webstruk-nya tersimpan sebagai rujukan.' : '')
    + '<div style="margin-top:6px;font-size:13px">Ketik nomor notanya di kolom bawah.</div>');
}

if (tempel) {
  tempel.addEventListener('paste', () => setTimeout(prosesTempelan, 0));
  tempel.addEventListener('change', prosesTempelan);
}

/* ---------- Kolom penyelesaian muncul hanya kalau dicentang ---------- */
const diTempat = el('ditempat');
const diTempatBlok = el('ditempat-blok');

function tampilkanPenyelesaian(){
  if (!diTempat || !diTempatBlok) return;
  const aktif = diTempat.checked;
  diTempatBlok.style.display = aktif ? 'block' : 'none';
  // required dipasang lewat skrip, bukan di markup: kolom yang tersembunyi
  // tapi required membuat peramban menolak kirim tanpa memperlihatkan
  // kolom mana yang salah — form yang macet tanpa keterangan.
  for (const id of ['res', 'tl']) {
    const f = el(id);
    if (f) f.required = aktif;
  }
}
if (diTempat) diTempat.addEventListener('change', tampilkanPenyelesaian);
tampilkanPenyelesaian();
</script>
@endsection
