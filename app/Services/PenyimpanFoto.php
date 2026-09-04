<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Menyimpan foto bukti ke disk privat, dikecilkan dan dibersihkan. (API-20)
 *
 * Foto dari kamera HP berukuran 3–8 MB. Disimpan apa adanya, satu complaint
 * dengan empat foto sudah 30 MB, dan halaman detailnya harus diunduh utuh
 * oleh perangkat outlet setiap kali dibuka.
 *
 * Yang dilakukan:
 *
 *   1. isi berkas diperiksa — bukan ekstensi, bukan Content-Type. Keduanya
 *      datang dari klien dan bisa berbohong.
 *   2. sisi terpanjang dijadikan maksimal 1600 px; cukup untuk melihat noda
 *      dan kerusakan, dan sudah lebih besar dari layar mana pun di outlet.
 *   3. disimpan ulang sebagai JPEG kualitas 80. Encode ulang inilah yang
 *      MEMBUANG EXIF — dan EXIF foto ponsel memuat koordinat GPS tempat
 *      foto diambil. Itu alasan yang lebih penting daripada ukuran.
 *   4. dibuatkan versi kecil 320 px untuk lini masa, supaya membuka halaman
 *      complaint tidak berarti mengunduh semua foto ukuran penuh.
 *
 * Format keluaran sengaja JPEG, bukan WebP: dukungan WebP di gd bergantung
 * pada bagaimana PHP-nya dibangun, dan berkas yang tidak bisa dibuka lebih
 * mahal daripada selisih beberapa puluh kilobyte.
 */
class PenyimpanFoto
{
    /** Sisi terpanjang berkas simpanan, dalam piksel. */
    public const SISI_MAKS = 1600;

    /** Sisi terpanjang versi kecil untuk lini masa. */
    public const SISI_KECIL = 320;

    public const KUALITAS = 80;

    /**
     * @return array{path:string,thumb_path:?string,mime:string,original_name:string,original_bytes:int,stored_bytes:int,compression_error:?string}
     *
     * @throws RuntimeException kalau isinya bukan gambar yang bisa dibaca
     */
    public function simpan(UploadedFile $file, string $dir): array
    {
        $asli = (int) $file->getSize();
        $nama = $file->getClientOriginalName();
        $bytes = (string) file_get_contents($file->getRealPath());

        if (@getimagesizefromstring($bytes) === false) {
            throw new RuntimeException('Berkas ini bukan gambar yang bisa dibaca.');
        }

        try {
            $gambar = @imagecreatefromstring($bytes);

            if ($gambar === false) {
                throw new RuntimeException('gd tidak bisa membaca gambar ini.');
            }

            $penuh = $this->encode($this->skala($gambar, self::SISI_MAKS));
            $kecil = $this->encode($this->skala($gambar, self::SISI_KECIL));
            imagedestroy($gambar);

            $path = $dir.'/'.Str::random(40).'.jpg';
            $thumbPath = $dir.'/'.Str::random(40).'-kecil.jpg';

            Storage::disk('local')->put($path, $penuh);
            Storage::disk('local')->put($thumbPath, $kecil);

            return [
                'path' => $path,
                'thumb_path' => $thumbPath,
                'mime' => 'image/jpeg',
                'original_name' => $nama,
                'original_bytes' => $asli,
                'stored_bytes' => strlen($penuh),
                'compression_error' => null,
            ];
        } catch (\Throwable $e) {
            // Kompresi gagal bukan alasan membuang bukti yang sudah diunggah
            // petugas. Aslinya disimpan, kegagalannya dicatat, dan halaman
            // tetap bisa menampilkannya — hanya tanpa versi kecil.
            return [
                'path' => $file->store($dir, 'local'),
                'thumb_path' => null,
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'original_name' => $nama,
                'original_bytes' => $asli,
                'stored_bytes' => $asli,
                'compression_error' => Str::limit($e->getMessage(), 180),
            ];
        }
    }

    /** @param \GdImage $gambar */
    private function skala($gambar, int $sisi)
    {
        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $sisiTerpanjang = max($lebar, $tinggi);

        // Foto yang sudah lebih kecil dari batas tidak diperbesar: memperbesar
        // tidak menambah satu pun detail, hanya menambah bytes.
        $rasio = $sisiTerpanjang > $sisi ? $sisi / $sisiTerpanjang : 1;

        $baru = imagecreatetruecolor((int) round($lebar * $rasio), (int) round($tinggi * $rasio));

        // Dasar putih: PNG transparan yang dijadikan JPEG tanpa ini berakhir
        // berlatar hitam pekat, dan noda pada kain jadi tidak terbaca.
        imagefilledrectangle($baru, 0, 0, imagesx($baru), imagesy($baru), imagecolorallocate($baru, 255, 255, 255));
        imagecopyresampled($baru, $gambar, 0, 0, 0, 0, imagesx($baru), imagesy($baru), $lebar, $tinggi);

        return $baru;
    }

    /** @param \GdImage $gambar */
    private function encode($gambar): string
    {
        ob_start();
        imagejpeg($gambar, null, self::KUALITAS);
        $keluaran = (string) ob_get_clean();
        imagedestroy($gambar);

        if ($keluaran === '') {
            throw new RuntimeException('gd gagal menulis JPEG.');
        }

        return $keluaran;
    }
}
