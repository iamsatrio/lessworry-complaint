<?php

namespace App\Services;

use App\Exceptions\NeviraAccessDenied;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Siapa saja yang bisa ditugasi atau ditetapkan sebagai pelaku.
 *
 * Halaman complaint dan penetapan pelaku menyusun daftar yang sama, dan
 * sejak keduanya pindah ke controller berbeda daftar itu harus punya satu
 * pemilik — kalau tidak, kandidat yang tampil di halaman bisa berbeda dari
 * kandidat yang diterima server saat disimpan.
 */
class DaftarPetugas
{
    public function __construct(private NeviraGate $nevira) {}

    /**
     * Pengguna sistem complaint yang aktif.
     *
     * @return Collection<int,User>
     */
    public function penggunaSistem(): Collection
    {
        return User::where('is_active', true)
            ->whereIn('role', User::peranBisaDitugasi())
            ->orderBy('name')->get();
    }

    /**
     * Karyawan outlet nota ini, lewat NeviraGate.
     *
     * Outlet complaint sudah ditentukan otomatis dari nota, jadi daftarnya
     * langsung tersaring — petugas tidak perlu memilih outlet dulu.
     *
     * NEVIRA yang sedang mati atau menolak menghasilkan daftar kosong, bukan
     * galat: penetapan pelaku lewat pengguna sistem tetap bisa jalan.
     *
     * @return array<int,array<string,mixed>>
     */
    public function karyawanOutlet(User $user, Complaint $complaint): array
    {
        try {
            return $this->nevira->outletStaff($user, $complaint->neviraOutletId());
        } catch (NeviraAccessDenied) {
            return [];
        }
    }
}
