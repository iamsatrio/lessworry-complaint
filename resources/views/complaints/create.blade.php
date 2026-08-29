@extends('layouts.app')
@section('title','Catat Complaint')
@section('content')
<div class="eyebrow">Complaint baru</div>
<h1>Catat keluhan pelanggan</h1>
<p class="lede">Isi seadanya dulu — yang penting keluhannya masuk. Detail bisa dilengkapi setelah pelanggan pergi.</p>

@php
  // Server sudah mengembalikan isian (validasi gagal): draft lama tidak boleh
  // ikut ditawarkan, nanti dua sumber isian bertabrakan di layar yang sama.
  $terisi = filled(old('description')) || filled(old('reporter_name'));
@endphp

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

<div class="card">
  <div class="eyebrow">Keluhannya apa</div>
  <div class="row">
    <div><label for="cat">Kategori <span class="req">*</span></label>
      <select id="cat" name="category" required>
        @foreach(config('complaint.categories') as $k=>$v)
          <option value="{{ $k }}" @selected(old('category')===$k)>{{ $v['label'] }}</option>
        @endforeach
      </select>
    </div>
    <div><label for="sub">Rincian</label><select id="sub" name="sub_category"></select></div>
    <div><label for="pri">Prioritas <span class="req">*</span></label>
      <select id="pri" name="priority" required>
        @foreach(config('complaint.priorities') as $k=>$v)
          <option value="{{ $k }}" @selected((old('priority') ?? 'medium')===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
  </div>
  <label for="desc">Isi keluhan <span class="req">*</span></label>
  <textarea id="desc" name="description" required placeholder="Tulis keluhan pelanggan apa adanya, pakai kalimatnya sendiri.">{{ old('description') }}</textarea>
  <p class="hint">Tulis apa yang pelanggan katakan, bukan tafsiranmu. Itu yang menolong saat kasusnya ditelusuri nanti.</p>

  <label for="att">Foto bukti</label>
  <input id="att" type="file" name="attachments[]" multiple accept="image/*">
  <p class="hint">Untuk keluhan hasil cuci dan barang rusak, foto hampir selalu menentukan.</p>
</div>

<div class="card">
    <div class="eyebrow">Siapa yang melapor</div>
    <div class="row">
      <div><label for="ch">Masuk lewat <span class="req">*</span></label>
        <select id="ch" name="channel" required>
          @foreach(config('complaint.channels') as $k=>$v)
            <option value="{{ $k }}" @selected(old('channel')===$k)>{{ $v }}</option>
          @endforeach
        </select>
      </div>
      @if(!auth()->user()->isKasir())
      <div><label for="out">Outlet</label>
        <select id="out" name="outlet_id">
          <option value="">Terisi sendiri dari nota</option>
          @foreach($outlets as $o)<option value="{{ $o->id }}" @selected(old('outlet_id')==$o->id)>{{ $o->name }}</option>@endforeach
        </select>
        <p class="hint" id="out-hint" style="display:none"></p>
      </div>
      @endif
    </div>
    {{-- Nota didahulukan: begitu diisi, identitas pelapor terisi sendiri.
         Kalau nama diketik lebih dulu, sistem tidak menimpanya, dan
         pengisian otomatis jadi terasa tidak jalan. --}}
    <label for="nv">Nomor nota NEVIRA <span class="req">*</span></label>
    <div style="display:flex;gap:10px">
      <input id="nv" name="nevira_transaction_number" value="{{ old('nevira_transaction_number') }}"
             placeholder="Salin dari struk, mis. INV/118/1787749345365/1">
      <button type="button" class="ghost shrink" id="cek">Cek</button>
    </div>
    <div id="nvbox" class="panel" style="display:none"></div>
    <p class="hint">Isi ini lebih dulu — nama dan telepon pelapor akan terisi sendiri dari data pelanggan pada nota.</p>

    <label for="exempt">Kalau tidak ada notanya, pilih alasannya</label>
    <select id="exempt" name="nota_exemption">
      <option value="">— complaint ini punya nomor nota —</option>
      @foreach(config('complaint.nota_exemptions') as $k=>$v)
        <option value="{{ $k }}" @selected(old('nota_exemption')===$k)>{{ $v }}</option>
      @endforeach
    </select>

    <label for="rn">Nama pelapor <span class="req">*</span></label>
    <input id="rn" name="reporter_name" value="{{ old('reporter_name') }}" required>
    <label for="rp">Nomor telepon</label>
    <input id="rp" name="reporter_phone" value="{{ old('reporter_phone') }}" inputmode="tel" placeholder="08xxxxxxxxxx">
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

</div>

<div class="card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
  <button>Simpan Complaint</button>
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
fillSub(@json(old('sub_category')));

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

function simpanDraft(){
  if (!form) return;
  const isi = {};
  for (const f of form.elements) if (f.name && f.type !== 'file' && f.type !== 'hidden') isi[f.name] = f.value;
  try{ localStorage.setItem(KEY, JSON.stringify({isi, waktu: Date.now()})); }catch(e){}
}

function pakaiDraft(d){
  if (!d || !d.isi || !form) return;
  for (const [k,v] of Object.entries(d.isi)) {
    const f = form.elements[k];
    if (f && f.type !== 'file') f.value = v;
  }
  if (d.isi.category && cat) { cat.value = d.isi.category; fillSub(d.isi.sub_category); }
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
    if (this.value && nvInput) { nvInput.value = ''; if (box) box.style.display = 'none'; }
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
</script>
@endsection
