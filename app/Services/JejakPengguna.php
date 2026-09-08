<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAudit;

/**
 * Satu tempat yang tahu cara menulis jejak audit akun. (API-35 bagian 4a)
 *
 * Dibuat mengikuti pola JejakComplaint: nilai `action` dipakai tampilan untuk
 * memberi label, jadi ia tidak boleh jadi string lepas yang diketik ulang di
 * controller dan perintah shell.
 */
class JejakPengguna
{
    /**
     * Admin menandai satu akun terverifikasi tanpa lewat email.
     *
     * Ini melemahkan pengaman — dipakai untuk akun bersama (Kasir, Produksi,
     * Kurir) dan untuk alamat yang ternyata tidak ada. Karena itu alasannya
     * wajib, dan wajibnya ditegakkan di validasi, bukan di sini.
     */
    public function emailDiverifikasiManual(User $user, User $actor, string $alasan): UserAudit
    {
        return UserAudit::create([
            'user_id' => $user->id,
            'actor_id' => $actor->id,
            'action' => 'email_diverifikasi_manual',
            'reason' => $alasan,
            'detail' => 'Akun ditandai terverifikasi tanpa membuka tautan email.',
        ]);
    }

    /**
     * Jalan yang sama, tapi dari shell: dipakai saat SMTP mati dan tidak ada
     * satu pun admin terverifikasi yang bisa menandai lewat antarmuka.
     */
    public function emailDiverifikasiLewatKonsol(User $user): UserAudit
    {
        return UserAudit::create([
            'user_id' => $user->id,
            'actor_id' => null,
            'action' => 'email_diverifikasi_konsol',
            'reason' => 'Pemulihan lewat perintah lessworry:pulihkan-admin.',
            'detail' => 'Akun ditandai terverifikasi tanpa membuka tautan email.',
        ]);
    }

    /**
     * Alamat email diganti admin. Alamat lama ikut dicatat: kalau akun
     * dibajak lewat pergantian alamat, satu-satunya cara menelusurinya
     * adalah tahu ke mana ia tadinya menunjuk.
     */
    public function emailDiubah(User $user, User $actor, string $emailLama): UserAudit
    {
        return UserAudit::create([
            'user_id' => $user->id,
            'actor_id' => $actor->id,
            'action' => 'email_diubah',
            'detail' => 'Alamat email diubah dari '.$emailLama.' ke '.$user->email
                .'. Verifikasi email direset — akun ini harus memverifikasi alamat barunya.',
        ]);
    }
}
