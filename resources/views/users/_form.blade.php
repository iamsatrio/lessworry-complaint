<label for="name">Nama lengkap <span class="req">*</span></label>
<input id="name" name="name" value="{{ old('name', $user->name ?? '') }}" required>

@if(!isset($user))
<label for="email">Email <span class="req">*</span></label>
<input id="email" type="email" name="email" value="{{ old('email') }}" required>
<p class="hint">Dipakai untuk masuk ke sistem. Tidak bisa diubah setelah akun dibuat.</p>
@endif

<label for="role">Peran <span class="req">*</span></label>
<select id="role" name="role" required>
  @foreach(['kasir'=>'Kasir','customer_care'=>'Customer Care','divisi'=>'Produksi / Kurir','supervisor'=>'Supervisor','admin'=>'Admin'] as $k=>$v)
    <option value="{{ $k }}" @selected(old('role', $user->role ?? '')===$k)>{{ $v }}</option>
  @endforeach
</select>
<p class="hint">
  Kasir hanya melihat complaint outletnya. Customer Care melihat semua outlet dan bisa menutup complaint.
  Divisi hanya melihat yang diteruskan ke divisinya. Supervisor melihat semua dan mengelola pengguna.
</p>

<label for="outlet_id">Outlet</label>
<select id="outlet_id" name="outlet_id">
  <option value="">Tidak terikat outlet</option>
  @foreach($outlets as $o)
    <option value="{{ $o->id }}" @selected(old('outlet_id', $user->outlet_id ?? '')==$o->id)>{{ $o->name }}</option>
  @endforeach
</select>
<p class="hint">Wajib diisi untuk peran Kasir — itu yang membatasi complaint mana yang dia lihat.</p>

<label for="division">Divisi</label>
<select id="division" name="division">
  <option value="">Bukan divisi</option>
  @foreach(config('complaint.divisions') as $k=>$v)
    <option value="{{ $k }}" @selected(old('division', $user->division ?? '')===$k)>{{ $v }}</option>
  @endforeach
</select>
<p class="hint">Hanya untuk peran Divisi.</p>
