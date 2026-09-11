<?php

namespace App\Alarms;

use App\Models\Complaint;

/**
 * Complaint yang tenggat penyelesaiannya sudah lewat dan belum tertutup.
 * (API-72 bagian 2B)
 *
 * SLA yang berlaku: Ringan 2 hari · Sedang 3 hari · Berat 5 hari
 * (`config/complaint.php` → `sla.resolution_days`).
 */
final class ComplaintLewatSla implements Alarm
{
    public function kunci(): string
    {
        return 'complaint.lewat_sla';
    }

    public function judul(): string
    {
        return 'Complaint melewati SLA';
    }

    public function tindakan(): string
    {
        return 'Telepon outlet atau Hub yang mengerjakannya, lalu tutup atau jeda dengan alasan.';
    }

    public function periksa(Lingkup $lingkup): ?Nyala
    {
        $query = $lingkup->complaints()
            ->open()
            // Jeda "Menunggu Pelanggan" MENGHENTIKAN jam SLA, jadi tiket yang
            // sedang dijeda tidak boleh menyalakan alarm ini: bolanya ada di
            // pelanggan, dan alarm yang menyalahkan tim atas balasan yang
            // belum datang akan berhenti dibaca — persis kegagalan yang
            // membuat jeda ini dibuat (API-18 #6).
            //
            // Ini pasangan SQL dari Complaint::isOverdue(). Keduanya dijaga
            // sepakat oleh tests/Feature/AlarmComplaintTest.php.
            ->whereNull('paused_at')
            ->whereNotNull('due_resolution_at')
            ->where('due_resolution_at', '<', now());

        $jumlah = $query->clone()->count();

        if ($jumlah === 0) {
            return null;
        }

        $daftar = RingkasanComplaint::daftar(
            $query,
            'due_resolution_at',
            fn (Complaint $complaint): string => Complaint::humanMinutes(
                (int) round(($complaint->due_resolution_at ?? now())->diffInMinutes(now()))
            ),
        );

        $terlama = $daftar[0]['umur'] ?? '';

        return new Nyala(
            jumlah: $jumlah,
            ringkasan: $jumlah.' complaint sudah lewat tenggat penyelesaian. Paling lama telat '.$terlama.'.',
            daftar: $daftar,
            perOutlet: RingkasanComplaint::perOutlet($query),
        );
    }
}
