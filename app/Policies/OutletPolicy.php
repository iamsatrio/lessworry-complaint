<?php

namespace App\Policies;

use App\Models\Outlet;
use App\Models\User;

/**
 * Wewenang atas satu outlet. (API-62 nomor 3)
 *
 * Sejauh ini hanya menjawab satu pertanyaan: boleh tidak pengguna ini
 * menyaring laporan menurut outlet tersebut. Outlet bukan sesuatu yang
 * dibuat atau diubah lewat aplikasi ini — daftarnya datang dari NEVIRA lewat
 * `SyncNeviraOutlets` — jadi tidak ada create/update/delete untuk dijaga.
 */
class OutletPolicy
{
    /**
     * Memakai outlet ini sebagai saringan laporan.
     *
     * Kasir hanya outletnya sendiri. Yang menegakkan bukan daftar pilihan di
     * halaman — itu cuma menyembunyikan tombol — melainkan pemeriksaan ini,
     * yang dijalankan LaporanFilterRequest sebelum controller-nya berjalan.
     */
    public function view(User $user, Outlet $outlet): bool
    {
        return $user->canViewOutlet($outlet);
    }
}
