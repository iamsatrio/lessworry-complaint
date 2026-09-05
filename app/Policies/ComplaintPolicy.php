<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

/**
 * Wewenang atas satu complaint, di satu tempat.
 *
 * Sebelumnya 25 `abort_unless` tersebar di ComplaintController, dan Buffon
 * pernah menemukan lubangnya justru karena dua rute bersebelahan memeriksa
 * hal yang berbeda: `/responsibility` menuntut canAssignResponsibility()
 * sementara `/assign` di sebelahnya hanya canView() (API-14 #3).
 *
 * Aturannya di sini TIDAK berubah sedikit pun dari yang tersebar itu —
 * hanya pindah tempat. Yang berubah adalah sekarang ada satu berkas untuk
 * dibaca kalau mau tahu siapa boleh apa.
 *
 * Yang sengaja TIDAK ikut pindah: pemeriksaan yang membalas 404, bukan 403.
 * "Lampiran ini milik complaint lain" dan "berkasnya tidak ada di disk"
 * bukan soal wewenang — itu keutuhan rute — dan Buffon menguji kode 404-nya.
 * Policy hanya bisa membalas 403, jadi memindahkannya ke sini akan mengubah
 * perilaku.
 */
class ComplaintPolicy
{
    /** Mencatat complaint baru. Divisi tidak mencatat, jadi tidak boleh. */
    public function create(User $user): bool
    {
        return $user->canCreateComplaint();
    }

    /** Membuka satu complaint: kasir sebatas outletnya, divisi sebatas divisinya. */
    public function view(User $user, Complaint $complaint): bool
    {
        return $user->canView($complaint);
    }

    /**
     * Mengubah status. Tiga tindakan di dalamnya punya syarat lebih berat dan
     * berdiri sendiri di bawah: close(), pause(), dan reopen().
     */
    public function updateStatus(User $user, Complaint $complaint): bool
    {
        return $user->canView($complaint);
    }

    /**
     * Menutup complaint. Kasir hanya boleh yang berbobot Ringan. (API-25 #5)
     *
     * Batas kompensasinya TIDAK ikut ke sini: ia bergantung pada angka yang
     * dikirim form, bukan hanya pada pengguna dan complaint-nya, jadi tempatnya
     * tetap di ComplaintStatusController — sama seperti alasan main dulu
     * menyisakan pemeriksaan penutupan di controller.
     */
    public function close(User $user, Complaint $complaint): bool
    {
        return $user->canView($complaint) && $user->canResolve($complaint);
    }

    /**
     * MEMULAI jeda SLA. Sumbu yang sama dengan menutup. (Review PR #1 temuan B)
     *
     * Jeda menghentikan jam SLA, jadi ia menentukan apakah sebuah tiket bisa
     * berubah merah di papan. Tanpa batas, satu outlet punya cara membungkam
     * papan per tiket.
     *
     * Melanjutkan tiket tidak lewat sini: arahnya aman — ia mengembalikan
     * tiket ke hitungan SLA alih-alih menyembunyikannya.
     */
    public function pause(User $user, Complaint $complaint): bool
    {
        return $user->canView($complaint) && $user->canPause($complaint);
    }

    /**
     * Membuka kembali tiket yang sudah ditutup. (Review PR #1 temuan A)
     *
     * Kebalikan sebuah tindakan berwewenang tetap tindakan berwewenang: kalau
     * kamu tidak boleh menutupnya, kamu tidak boleh membatalkan penutupan
     * orang lain.
     */
    public function reopen(User $user, Complaint $complaint): bool
    {
        return $user->canView($complaint) && $user->canReopen($complaint);
    }

    /** Menambah catatan penanganan beserta fotonya. */
    public function addNote(User $user, Complaint $complaint): bool
    {
        return $user->canView($complaint);
    }

    /**
     * Menugaskan penanggung jawab dan meneruskan ke divisi.
     *
     * Aturannya kebetulan sama dengan manageResponsible() hari ini, tapi
     * sengaja tidak digabung: keduanya keputusan yang berbeda, dan
     * menyatukannya berarti mengubah salah satu diam-diam ikut mengubah
     * yang lain.
     */
    public function assign(User $user, Complaint $complaint): bool
    {
        return $user->canView($complaint) && $user->canAssignResponsibility();
    }

    /** Menetapkan, mengubah, atau mencabut pelaku complaint. (API-19) */
    public function manageResponsible(User $user, Complaint $complaint): bool
    {
        return $user->canView($complaint) && $user->canAssignResponsibility();
    }

    /**
     * Memasang atau membetulkan tautan ke order NEVIRA, termasuk tarik ulang.
     *
     * Menautkan berarti menarik data pelanggan dari NEVIRA, jadi peran yang
     * tidak mencatat complaint tidak berkepentingan dengan itu. (API-8 T1)
     */
    public function link(User $user, Complaint $complaint): bool
    {
        return $user->canView($complaint) && $user->canCreateComplaint();
    }

    /**
     * Membuka foto bukti, penuh maupun versi kecilnya.
     *
     * Keduanya diperiksa sama persis — kalau tidak, versi kecil jadi jalan
     * memutar untuk melihat foto yang sama. (API-20)
     */
    public function viewAttachment(User $user, Complaint $complaint): bool
    {
        return $user->canView($complaint);
    }
}
