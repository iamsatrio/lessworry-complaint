<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ComplaintActivity;
use App\Models\ComplaintResponsible;
use App\Models\User;

/**
 * Satu tempat yang tahu cara menulis riwayat complaint.
 *
 * Sebelumnya `ComplaintActivity::create` dipanggil sembilan kali tersebar di
 * controller, masing-masing menyusun sendiri `type` dan kalimat catatannya.
 * Itu dua masalah: kalimat yang sama ditulis ulang di tempat berbeda, dan
 * nilai `type` — yang dipakai tampilan untuk MENYARING baris berisi nama
 * karyawan dari mata kasir (API-19) — jadi string lepas yang gampang salah
 * ketik tanpa ada yang tahu.
 *
 * Nilai `type` di sini sengaja tetap persis seperti yang sudah tersimpan di
 * basis data: 'created', 'status_change', 'note', 'assign', 'forward',
 * 'responsible'. Riwayat lama tidak ditulis ulang, jadi mengubah namanya
 * berarti baris lama berhenti cocok dengan penyaring di tampilan.
 */
class JejakComplaint
{
    public function dibuat(Complaint $complaint, User $user): ComplaintActivity
    {
        return $this->tulis($complaint, $user, 'created', [
            'to_status' => 'baru',
            'note' => 'Complaint dibuat lewat '.$complaint->channelLabel(),
        ]);
    }

    public function statusBerubah(
        Complaint $complaint,
        User $user,
        string $dari,
        string $ke,
        ?string $catatan = null,
    ): ComplaintActivity {
        return $this->tulis($complaint, $user, 'status_change', [
            'from_status' => $dari,
            'to_status' => $ke,
            'note' => $catatan,
        ]);
    }

    /**
     * Uang yang berpindah punya barisnya sendiri.
     *
     * Riwayat dulu hanya mencatat perubahan status, jadi nilai kompensasi
     * bisa bergerak tanpa siapa pun bisa menelusurinya. (API-14 #10)
     */
    public function kompensasiBerubah(Complaint $complaint, User $user, int $dari, int $ke): ComplaintActivity
    {
        return $this->tulis($complaint, $user, 'note', [
            'note' => 'Kompensasi diubah dari Rp '.$this->rupiah($dari).' ke Rp '.$this->rupiah($ke).'.',
        ]);
    }

    /** Catatan penanganan yang diketik petugas. Foto menempel pada baris ini. (API-20) */
    public function catatan(Complaint $complaint, User $user, string $isi): ComplaintActivity
    {
        return $this->tulis($complaint, $user, 'note', ['note' => $isi]);
    }

    public function penugasan(Complaint $complaint, User $user, ?string $divisi): ComplaintActivity
    {
        $diteruskan = filled($divisi);

        return $this->tulis($complaint, $user, $diteruskan ? 'forward' : 'assign', [
            'note' => $diteruskan
                ? 'Diteruskan ke divisi '.config('complaint.divisions.'.$divisi)
                : 'Penanggung jawab diperbarui',
        ]);
    }

    /**
     * Perubahan tautan ke order NEVIRA, lengkap dengan nomor lama dan barunya.
     *
     * Isi complaint tidak pernah berubah diam-diam: nomor nota yang bergeser
     * berarti data pelanggan yang tampil ikut bergeser.
     */
    public function tautanOrder(Complaint $complaint, User $user, string $lama, string $baru): ComplaintActivity
    {
        $note = match (true) {
            $lama === '' => 'Ditautkan ke order NEVIRA '.$baru,
            $baru === '' => 'Tautan ke order NEVIRA '.$lama.' dilepas',
            default => 'Tautan order NEVIRA diubah dari '.$lama.' ke '.$baru,
        };

        return $this->tulis($complaint, $user, 'note', ['note' => $note]);
    }

    /**
     * Penetapan pelaku. (API-19)
     *
     * @param  array<int,array<string,mixed>>  $pelaku
     */
    public function pelakuDitetapkan(Complaint $complaint, User $user, array $pelaku, string $alasan): ComplaintActivity
    {
        return $this->tulis($complaint, $user, 'responsible', [
            'note' => 'Pelaku ditetapkan: '.$this->sebutPelaku($pelaku).'. Alasan: '.$alasan,
        ]);
    }

    public function pelakuDiperbarui(
        Complaint $complaint,
        User $user,
        ComplaintResponsible $responsible,
        string $alasan,
    ): ComplaintActivity {
        return $this->tulis($complaint, $user, 'responsible', [
            'note' => 'Penetapan pelaku '.$responsible->staff_name.' diperbarui ('
                .$responsible->roleLabel().'). Alasan: '.$alasan,
        ]);
    }

    public function pelakuDicabut(Complaint $complaint, User $user, string $nama): ComplaintActivity
    {
        return $this->tulis($complaint, $user, 'responsible', [
            'note' => 'Penetapan pelaku ('.$nama.') dicabut.',
        ]);
    }

    /**
     * Satu-satunya jalan menulis baris riwayat.
     *
     * 'responsible' punya jenis tersendiri, bukan 'note', karena isinya nama
     * karyawan dan riwayat complaint dibaca juga oleh kasir. Yang boleh
     * melihatnya disaring di tampilan. (API-19)
     *
     * @param  array<string,mixed>  $isi
     */
    private function tulis(Complaint $complaint, User $user, string $type, array $isi): ComplaintActivity
    {
        return ComplaintActivity::create($isi + [
            'complaint_id' => $complaint->id,
            'user_id' => $user->id,
            'type' => $type,
        ]);
    }

    /** @param  array<int,array<string,mixed>>  $pelaku */
    private function sebutPelaku(array $pelaku): string
    {
        return collect($pelaku)->map(function ($p) {
            $peran = config('complaint.responsible_roles.'.$p['role'], $p['role']);

            return $p['name'].' ('.$peran.($p['stage'] ? ' · '.$p['stage'] : '').')';
        })->implode(', ');
    }

    private function rupiah(int $nilai): string
    {
        return number_format($nilai, 0, ',', '.');
    }
}
