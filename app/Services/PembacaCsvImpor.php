<?php

namespace App\Services;

/**
 * Membaca CSV ekspor spreadsheet complaint jadi baris bernama kolom.
 *
 * Berdiri sendiri karena dua perintah membacanya dengan aturan yang harus
 * sama persis: `complaint:import` dan `complaint:backfill-pengambilan`. Kalau
 * keduanya punya salinan pembacanya sendiri, sidik jari baris yang sama bisa
 * berbeda di antara keduanya — dan backfill akan gagal menemukan complaint
 * yang justru baru saja diimpornya.
 *
 * Nomor baris yang dikembalikan adalah nomor baris BERKAS (judul = 1), bukan
 * urutan data. Itu yang dicari orang saat membuka berkasnya. (API-48)
 */
class PembacaCsvImpor
{
    /**
     * @return array{baris:array<int,array<string,string>>,galat:?string}
     */
    public function baca(string $berkas): array
    {
        $fh = fopen($berkas, 'r');

        if ($fh === false) {
            return ['baris' => [], 'galat' => 'Berkas tidak bisa dibuka: '.$berkas];
        }

        $judul = fgetcsv($fh, 0, ',', '"', '');

        if (! is_array($judul)) {
            fclose($fh);

            return ['baris' => [], 'galat' => 'Berkas kosong atau tanpa baris judul.'];
        }

        // BOM dari ekspor spreadsheet menempel pada nama kolom PERTAMA, dan
        // membuat pencarian kolom `Date` gagal untuk seluruh berkas.
        $judul = array_map(fn ($k) => trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $k)), $judul);

        $hasil = [];
        $nomor = 1;

        while (($data = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $nomor++;

            // Baris kosong di ujung berkas: fgetcsv mengembalikannya sebagai
            // satu kolom berisi null, bukan sebagai larik kosong.
            if ($data === [null]) {
                continue;
            }

            $isi = [];

            foreach ($judul as $i => $kolom) {
                $isi[$kolom] = (string) ($data[$i] ?? '');
            }

            $hasil[$nomor] = $isi;
        }

        fclose($fh);

        return ['baris' => $hasil, 'galat' => null];
    }
}
