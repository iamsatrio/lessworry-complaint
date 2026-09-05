{{--
  Surat verifikasi akun. Sengaja polos: dibaca di ponsel outlet, dan yang
  penting hanya satu — tautannya.

  Tidak memuat password, tidak memuat data pelanggan.
--}}
<p>Halo {{ $user->name }},</p>

<p>
    Akun kamu di sistem Complaint Management Less Worry sudah dibuat.
    Sebelum bisa memasang password baru, alamat email ini perlu diverifikasi dulu —
    supaya orang lain yang kebetulan melihat password sementaramu tidak bisa mendahului kamu.
</p>

<p>Buka tautan berikut:</p>

<p><a href="{{ $tautan }}">{{ $tautan }}</a></p>

<p>
    Tautan ini berlaku {{ $umurMenit }} menit dan hanya bisa dipakai sekali.
    Kalau sudah lewat, minta tautan baru lewat tombol "Kirim Ulang Tautan" di halaman verifikasi.
</p>

<p>
    <b>Kalau kamu tidak merasa meminta ini, beri tahu Admin sekarang juga.</b>
    Artinya ada orang lain yang sedang mencoba masuk memakai akunmu.
</p>

<p>
    — Sistem Complaint Management Less Worry<br>
    Surat ini dikirim otomatis; balasannya tidak dibaca siapa pun.
</p>
