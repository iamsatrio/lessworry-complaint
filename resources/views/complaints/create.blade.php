@extends('layouts.app')
@section('title','Complaint Baru')
@section('content')
<h1>Complaint Baru</h1>
<div class="sub">Isi cepat. Field bertanda * wajib.</div>

<form method="POST" action="{{ route('complaints.store') }}" enctype="multipart/form-data" id="f">
@csrf
<div class="grid g2">
  <div class="card">
    <h3 style="margin:0">Pelapor &amp; Kanal</h3>
    <div class="row">
      <div><label>Kanal Masuk *</label>
        <select name="channel" required>
          @foreach(config('complaint.channels') as $k=>$v)
            <option value="{{ $k }}" @selected(old('channel')===$k)>{{ $v }}</option>
          @endforeach
        </select>
      </div>
      @if(!auth()->user()->isKasir())
      <div><label>Outlet</label>
        <select name="outlet_id">
          <option value="">— pilih —</option>
          @foreach($outlets as $o)<option value="{{ $o->id }}" @selected(old('outlet_id')==$o->id)>{{ $o->name }}</option>@endforeach
        </select>
      </div>
      @endif
    </div>
    <label>Nama Pelapor *</label>
    <input name="reporter_name" value="{{ old('reporter_name') }}" required>
    <label>Nomor Telepon</label>
    <input name="reporter_phone" value="{{ old('reporter_phone') }}" placeholder="08xxxxxxxxxx">
  </div>

  <div class="card">
    <h3 style="margin:0">Tautan Order NEVIRA</h3>
    <label>ID Transaksi / Nomor Struk</label>
    <div style="display:flex;gap:8px">
      <input name="nevira_transaction_id" id="nv" value="{{ old('nevira_transaction_id') }}" placeholder="Kosongkan bila keluhan tidak terkait order">
      <button type="button" class="btn ghost" id="cek" style="white-space:nowrap">Cek</button>
    </div>
    <div id="nvbox" class="nevira" style="display:none"></div>
    <p class="muted" style="font-size:12px;margin-top:10px">
      Complaint tanpa ID order tetap bisa disimpan. Kalau NEVIRA sedang bermasalah, simpan dulu — tautan bisa diperbaiki nanti.
    </p>
  </div>
</div>

<div class="card">
  <h3 style="margin:0">Keluhan</h3>
  <div class="row">
    <div><label>Kategori *</label>
      <select name="category" id="cat" required>
        @foreach(config('complaint.categories') as $k=>$v)
          <option value="{{ $k }}" @selected(old('category')===$k)>{{ $v['label'] }}</option>
        @endforeach
      </select>
    </div>
    <div><label>Sub-kategori</label><select name="sub_category" id="sub"></select></div>
    <div><label>Prioritas *</label>
      <select name="priority" required>
        @foreach(config('complaint.priorities') as $k=>$v)
          <option value="{{ $k }}" @selected((old('priority') ?? 'medium')===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
  </div>
  <label>Isi Keluhan *</label>
  <textarea name="description" required placeholder="Tulis keluhan pelanggan apa adanya.">{{ old('description') }}</textarea>
  <label>Foto Bukti</label>
  <input type="file" name="attachments[]" multiple accept="image/*">
  <div style="margin-top:20px;display:flex;gap:10px">
    <button>Simpan Complaint</button>
    <a href="{{ route('complaints.index') }}" class="btn ghost">Batal</a>
  </div>
</div>
</form>

<script>
const SUB = @json(collect(config('complaint.categories'))->map(fn($c) => $c['sub']));
const cat = document.getElementById('cat'), sub = document.getElementById('sub');
function fillSub(){
  const list = SUB[cat.value] || [];
  sub.innerHTML = '<option value="">— pilih —</option>' + list.map(s => `<option>${s}</option>`).join('');
}
cat.addEventListener('change', fillSub); fillSub();

// Draft lokal: isian tidak hilang kalau koneksi putus di tengah pengisian.
const KEY='lw_complaint_draft';
const form=document.getElementById('f');
try{
  const saved=JSON.parse(localStorage.getItem(KEY)||'{}');
  for(const [k,v] of Object.entries(saved)){
    const el=form.elements[k];
    if(el && el.type!=='file' && !el.value) el.value=v;
  }
  fillSub();
}catch(e){}
form.addEventListener('input',()=>{
  const d={};
  for(const el of form.elements) if(el.name && el.type!=='file' && el.type!=='hidden') d[el.name]=el.value;
  localStorage.setItem(KEY,JSON.stringify(d));
});
form.addEventListener('submit',()=>localStorage.removeItem(KEY));

// Cek order ke NEVIRA
document.getElementById('cek').addEventListener('click', async () => {
  const id = document.getElementById('nv').value.trim();
  const box = document.getElementById('nvbox');
  if(!id){ box.style.display='none'; return; }
  box.style.display='block'; box.className='nevira'; box.textContent='Mengambil data dari NEVIRA…';
  try{
    const r = await fetch(`{{ route('nevira.lookup') }}?id=${encodeURIComponent(id)}`, {headers:{'Accept':'application/json'}});
    const j = await r.json();
    if(j.ok){
      const d = j.data;
      box.innerHTML = `<b>Order ditemukan</b><br>
        Invoice: ${d.invoice ?? '—'}<br>
        Pelanggan: ${d.customer_name ?? '—'} ${d.customer_phone ? '('+d.customer_phone+')' : ''}<br>
        Outlet: ${d.outlet_name ?? '—'}<br>
        Total: ${d.total ?? '—'} · Status: ${d.status ?? '—'}`;
    }else{
      box.className='nevira err-box'; box.textContent = j.message;
    }
  }catch(e){
    box.className='nevira err-box';
    box.textContent='Gagal menghubungi server. Complaint tetap bisa disimpan tanpa tautan order.';
  }
});
</script>
@endsection
