<label for="name">Nama lengkap <span class="req">*</span></label>
<input id="name" name="name" value="{{ old('name', $user->name ?? '') }}" required>

<label for="email">Email <span class="req">*</span></label>
<input id="email" type="email" name="email" value="{{ old('email', $user->email ?? '') }}" required>
@isset($user)
<p class="hint">
  Dipakai untuk masuk, dan untuk mengirim tautan verifikasi. Kalau alamat ini diganti,
  verifikasi akun ikut direset dan tautan lama yang sudah terkirim langsung mati —
  {{ $user->name }} harus memverifikasi alamat barunya sebelum bisa memakai sistem lagi.
</p>
@else
<p class="hint">
  Dipakai untuk masuk ke sistem, dan untuk mengirim tautan verifikasi.
  Pastikan kotak suratnya benar-benar ada dan bisa dibuka orangnya — tanpa itu dia tidak bisa masuk.
</p>
@endisset

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
