<?php

namespace App\Alarms;

use App\Models\Complaint;

/**
 * Complaint yang berstatus Open dan tidak ada pemiliknya. (API-72 bagian 2A)
 *
 * Angka yang belum pernah dilihat siapa pun di Less Worry. Status `Open`
 * muncul NOL KALI dari 545 baris riwayat complaint — spreadsheet lama hanya
 * diisi saat complaint ditutup, jadi "berapa yang sedang menganggur tanpa
 * pemilik" bukan pertanyaan yang jawabannya buruk, melainkan pertanyaan yang
 * belum pernah bisa dijawab.
 */
final class ComplaintBelumDipegang implements Alarm
{
    public function kunci(): string
    {
        return 'complaint.belum_dipegang';
    }

    public function judul(): string
    {
        return 'Complaint belum dipegang';
    }

    public function tindakan(): string
    {
        return 'Buka papan kerja, tetapkan siapa yang menanganinya.';
    }

    public function periksa(Lingkup $lingkup): ?Nyala
    {
        $jam = self::ambangJam();

        $query = $lingkup->complaints()
            // Status HARFIAH 'open', bukan scope open(): tiket `handling`
            // sudah ada yang menyentuhnya. Yang dicari alarm ini justru yang
            // belum tersentuh sama sekali, dan `open_statuses` memuat
            // keduanya.
            ->where('status', 'open')
            ->whereNull('assigned_to')
            // Ambang umur menahan alarm dari keluhan yang baru masuk lima
            // menit lalu — kasir belum selesai bicara dengan pelanggannya.
            ->where('created_at', '<=', now()->subHours($jam));

        $jumlah = $query->clone()->count();

        if ($jumlah === 0) {
            return null;
        }

        $daftar = RingkasanComplaint::daftar(
            $query,
            'created_at',
            fn (Complaint $complaint): string => Complaint::humanMinutes(
                (int) round(($complaint->created_at ?? now())->diffInMinutes(now()))
            ),
        );

        $terlama = $daftar[0]['umur'] ?? '';

        return new Nyala(
            jumlah: $jumlah,
            ringkasan: $jumlah.' complaint terbuka lebih dari '.$jam.' jam tanpa pemilik. Terlama menganggur '.$terlama.'.',
            daftar: $daftar,
            perOutlet: RingkasanComplaint::perOutlet($query),
        );
    }

    /**
     * Ambang umur, dalam jam.
     *
     * Bawaannya 4 jam dan itu TEBAKAN — dipilih supaya keluhan yang masuk pagi
     * dan dipegang sebelum makan siang tidak menyalakan apa pun. Uji coba
     * lapangan (API-10) yang akan memperbaiki angkanya, dan karena itu ia
     * setelan env, bukan angka yang tertanam di kode.
     */
    public static function ambangJam(): int
    {
        return max(0, (int) config('complaint.alarms.belum_dipegang_jam', 4));
    }
}
