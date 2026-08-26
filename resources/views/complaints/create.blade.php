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
    <label for="nv">ID transaksi atau nomor struk</label>
    <div style="display:flex;gap:10px">
      <input id="nv" name="nevira_transaction_id" value="{{ old('nevira_transaction_id') }}" placeholder="Nomor ID transaksi dari struk">
      <button type="button" class="ghost shrink" id="cek">Cek</button>
    </div>
    <div id="nvbox" class="panel" style="display:none"></div>
    <p class="hint">
      Menautkan complaint ke order membuat riwayat cuciannya ikut terbaca.
      Tidak punya nomornya? Lewati saja — simpan complaint sekarang, lalu pasang nomornya
      dari halaman complaint itu setelah pelanggan membawa struknya.
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
const SUB = @json(collect(config('complaint.categories'))->map(fn($c) => $c['sub']));
const cat = document.getElementById('cat'), sub = document.getElementById('sub');
function fillSub(keep){
  const list = SUB[cat.value] || [];
  sub.innerHTML = '<option value="">Tidak dirinci</option>' + list.map(s => `<option>${s}</option>`).join('');
  if (keep) sub.value = keep;
}
cat.addEventListener('change', () => fillSub());
fillSub(@json(old('sub_category')));

// Draft lokal — isian tidak hilang kalau koneksi outlet putus di tengah pengisian.
const KEY='lw_complaint_draft', form=document.getElementById('f');
try{
  const saved=JSON.parse(localStorage.getItem(KEY)||'{}');
  for(const [k,v] of Object.entries(saved)){
    const el=form.elements[k];
    if(el && el.type!=='file' && !el.value) el.value=v;
  }
  if(saved.category){ cat.value=saved.category; fillSub(saved.sub_category); }
}catch(e){}
form.addEventListener('input',()=>{
  const d={};
  for(const el of form.elements) if(el.name && el.type!=='file' && el.type!=='hidden') d[el.name]=el.value;
  localStorage.setItem(KEY,JSON.stringify(d));
});
form.addEventListener('submit',()=>localStorage.removeItem(KEY));

// Cek order ke NEVIRA
const btn=document.getElementById('cek'), box=document.getElementById('nvbox');
btn.addEventListener('click', async () => {
  const id = document.getElementById('nv').value.trim();
  if(!id){ box.style.display='none'; return; }
  btn.disabled=true; btn.textContent='Mencari…';
  box.style.display='block'; box.className='panel'; box.textContent='Mengambil data order dari NEVIRA…';
  try{
    const r = await fetch(`{{ route('nevira.lookup') }}?id=${encodeURIComponent(id)}`, {headers:{'Accept':'application/json'}});
    const j = await r.json();
    if(j.ok){
      const d=j.data;
      box.className='panel good';
      const rupiah = n => n==null ? '—' : 'Rp '+Number(n).toLocaleString('id-ID');
      box.innerHTML = `<b>Order ketemu</b><br>
        Struk ${d.invoice ?? '—'} · ${d.outlet_name ?? 'outlet tidak tercatat'}<br>
        ${d.customer_name ?? 'Nama pelanggan tidak tercatat'}${d.customer_phone ? ' · '+d.customer_phone : ''}<br>
        ${rupiah(d.grand_total)} · ${d.status ?? '—'} · ${d.payment_status ?? '—'}
        <div style="margin-top:8px;font-size:13px">Cocokkan dengan struk pelanggan sebelum menyimpan.</div>`;
    }else{
      box.className='panel bad'; box.textContent=j.message;
    }
  }catch(e){
    box.className='panel bad';
    box.textContent='Server tidak merespons. Simpan complaint dulu — tautan order bisa dipasang setelah ini.';
  }finally{
    btn.disabled=false; btn.textContent='Cek';
  }
});
</script>
@endsection
