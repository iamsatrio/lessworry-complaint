<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\User;

/**
 * Boleh-tidaknya sebuah complaint ditutup sekaligus saat dicatat. (API-26)
 *
 * Kasir mencatat complaint SETELAH menanganinya — status `open` muncul nol
 * kali dari 545 baris riwayat, jadi sheet-nya hanya diisi saat perkara sudah
 * selesai. Memaksa dua langkah (simpan, cari lagi, buka, isi, tutup) untuk
 * 52% kasus berarti mewarisi kebiasaan yang sama: yang belum selesai tidak
 * akan pernah tercatat.
 *
 * Yang TIDAK boleh terjadi: centang ini jadi jalan pintas melewati wewenang.
 * Syaratnya sama persis dengan menutup lewat halaman complaint —
 *
 *   1. ComplaintPolicy::close — kasir hanya bobot Ringan
 *   2. batas kompensasi peran — kasir ≤ Rp 50.000
 *
 * Keduanya dibaca dari sumber yang sama dengan jalur biasa, bukan disalin:
 * kalau batasnya berubah, ia berubah di kedua jalur sekaligus.
 *
 * Syarat tidak terpenuhi BUKAN berarti complaint ditolak. Keluhannya tetap
 * tercatat lengkap dengan catatan penyelesaian kasir, hanya statusnya
 * Handling — dan kasir diberi tahu sebabnya dengan kalimat yang menyebut
 * angkanya, bukan pesan galat umum.
 */
class PenutupanDiTempat
{
    /**
     * @return array{boleh:bool,alasan:?string}
     */
    public function putuskan(User $user, Complaint $complaint, int $kompensasi): array
    {
        if (! $user->can('close', $complaint)) {
            return [
                'boleh' => false,
                'alasan' => $user->isKasir()
                    ? 'Kasir hanya boleh menutup complaint berbobot Ringan, dan complaint ini '
                        .$complaint->bobotLabel().'. Tersimpan sebagai Handling berisi catatan '
                        .'penyelesaianmu — Customer Care yang menutupnya.'
                    : 'Peranmu tidak berwenang menutup complaint. Tersimpan sebagai Handling '
                        .'berisi catatan penyelesaianmu.',
            ];
        }

        $batas = $user->compensationLimit();

        if ($kompensasi > $batas) {
            return [
                'boleh' => false,
                'alasan' => 'Kompensasi Rp '.number_format($kompensasi, 0, ',', '.')
                    .' di atas batas wewenang '.$user->roleLabel()
                    .' (Rp '.number_format($batas, 0, ',', '.').'), perlu Customer Care menutup. '
                    .'Tersimpan sebagai Handling berisi catatan penyelesaianmu.',
            ];
        }

        return ['boleh' => true, 'alasan' => null];
    }
}
