@extends('layouts.app')
@section('title','Catat Complaint')
@section('content')
<div class="eyebrow">Complaint baru</div>
<h1>Catat keluhan pelanggan</h1>
<p class="lede">Isi seadanya dulu — yang penting keluhannya masuk. Detail bisa dilengkapi setelah pelanggan pergi.</p>

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

<div class="grid g2">
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
          <option value="">Belum diketahui</option>
          @foreach($outlets as $o)<option value="{{ $o->id }}" @selected(old('outlet_id')==$o->id)>{{ $o->name }}</option>@endforeach
        </select>
      </div>
      @endif
    </div>
    <label for="rn">Nama pelapor <span class="req">*</span></label>
    <input id="rn" name="reporter_name" value="{{ old('reporter_name') }}" required>
    <label for="rp">Nomor telepon</label>
    <input id="rp" name="reporter_phone" value="{{ old('reporter_phone') }}" inputmode="tel" placeholder="08xxxxxxxxxx">
    <p class="hint">Dipakai untuk mengabari hasil penanganan, dan untuk melihat kalau pelanggan ini pernah komplain sebelumnya.</p>
  </div>

  <div class="card">
    <div class="eyebrow">Order di NEVIRA</div>
    <label for="nv">Nomor nota NEVIRA <span class="req">*</span></label>
    <div style="display:flex;gap:10px">
      <input id="nv" name="nevira_transaction_number" value="{{ old('nevira_transaction_number') }}"
             placeholder="Salin dari struk, mis. INV/118/1787749345365/1">
      <button type="button" class="ghost shrink" id="cek">Cek</button>
    </div>
    <div id="nvbox" class="panel" style="display:none"></div>
    <p class="hint">
      Boleh nomor nota yang tercetak di struk, boleh juga ID transaksi. Pengecekan berjalan
      sendiri begitu kolom ini ditinggalkan; data pelapor ikut terisi.
    </p>

    <label for="exempt">Kalau tidak ada notanya, pilih alasannya</label>
    <select id="exempt" name="nota_exemption">
      <option value="">— complaint ini punya nomor nota —</option>
      @foreach(config('complaint.nota_exemptions') as $k=>$v)
        <option value="{{ $k }}" @selected(old('nota_exemption')===$k)>{{ $v }}</option>
      @endforeach
    </select>
    <p class="hint">
      Complaint tanpa nota tidak bisa ditelusuri ke ordernya, jadi alasannya harus disebut.
      Isi salah satu: nomor nota, atau alasan di atas.
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
const KEY = 'lw_complaint_draft';
if (form) {
  try{
    const saved = JSON.parse(localStorage.getItem(KEY) || '{}');
    for (const [k,v] of Object.entries(saved)) {
      const f = form.elements[k];
      if (f && f.type !== 'file' && !f.value) f.value = v;
    }
    if (saved.category && cat) { cat.value = saved.category; fillSub(saved.sub_category); }
  }catch(e){}

  form.addEventListener('input', () => {
    const d = {};
    for (const f of form.elements) if (f.name && f.type !== 'file' && f.type !== 'hidden') d[f.name] = f.value;
    localStorage.setItem(KEY, JSON.stringify(d));
  });
  form.addEventListener('submit', () => localStorage.removeItem(KEY));
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
      if (nm && d.customer_name && !nm.value) nm.value = d.customer_name;
      if (tp && d.customer_phone && !tp.value) tp.value = d.customer_phone;

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

if (btn)     btn.addEventListener('click', () => { terakhirDicek = ''; cekNota(); });
if (nvInput) {
  nvInput.addEventListener('blur', cekNota);
  nvInput.addEventListener('paste', () => setTimeout(cekNota, 60));
}
</script>
@endsection
