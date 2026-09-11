<?php

namespace App\Services;

/**
 * Apa yang sebenarnya ada di path yang diketik orang. (API-60)
 *
 * Yang membuatnya ada: `complaint:import` menjawab berkas yang tidak ketemu
 * dengan mengulang persis apa yang baru saja diketik — tanpa menyebut di mana
 * ia mencari. Berkasnya ada di `~/Downloads`, perintahnya mencari di akar
 * proyek, dan pesannya tidak memuat satu pun keterangan yang bisa membawa
 * orangnya ke sana. Perintah itu dijalankan sekali, oleh orang yang jarang
 * memakai terminal, pada malam deploy.
 *
 * Kelas ini hanya mengumpulkan FAKTA tentang satu path: ada atau tidak,
 * direktori atau berkas, boleh dibaca atau tidak, ekstensinya apa, dan ke mana
 * path relatif itu jatuh. Kata-katanya dipilih masing-masing perintah, karena
 * saran yang berguna berbeda per perintah — `backup:verify` tidak menyuruh
 * orang mengekspor CSV.
 *
 * Ia TIDAK pernah menyisir direktori mencari berkas yang mirip. Perintah yang
 * diberi satu path tidak berhak mengintip isi folder di sekitarnya; yang boleh
 * dikatakannya hanya di mana ia tadi mencari.
 */
class BerkasMasukan
{
    /**
     * Ekstensi yang pasti bukan CSV walau isinya tabel.
     *
     * Dibuka dengan fgetcsv, berkas-berkas ini menghasilkan ratusan baris
     * gagal yang tidak menyebut sebabnya — padahal sebabnya satu: berkasnya
     * belum diekspor.
     */
    private const SPREADSHEET_BINER = ['xlsx', 'xls', 'xlsm', 'ods', 'numbers'];

    /** Direktori tempat path relatif diukur. */
    public readonly string $basis;

    /** Path yang benar-benar dilihat sistem berkas. */
    public readonly string $absolut;

    public function __construct(public readonly string $masukan, ?string $basis = null)
    {
        $this->basis = $basis ?? (getcwd() ?: base_path());
        $this->absolut = $this->resolusi();
    }

    public function ada(): bool
    {
        return file_exists($this->absolut);
    }

    public function direktori(): bool
    {
        return is_dir($this->absolut);
    }

    public function bisaDibaca(): bool
    {
        return is_readable($this->absolut);
    }

    public function relatif(): bool
    {
        return ! str_starts_with($this->masukan, DIRECTORY_SEPARATOR);
    }

    /**
     * Ekstensi spreadsheet biner, atau null kalau bukan.
     *
     * Bukan CSV yang berekstensi lain (.txt hasil ekspor tetap CSV) — hanya
     * bentuk yang memang tidak bisa dibaca sebagai teks berkoma.
     */
    public function spreadsheetBiner(): ?string
    {
        $ekstensi = mb_strtolower(pathinfo($this->masukan, PATHINFO_EXTENSION));

        return in_array($ekstensi, self::SPREADSHEET_BINER, true) ? $ekstensi : null;
    }

    /**
     * Kenapa path-nya jatuh di situ.
     *
     * Kosong kalau tidak ada yang bisa disalahpahami — path absolut yang
     * ditulis lengkap tidak butuh penjelasan. Di situlah salah pahamnya lahir,
     * jadi hanya di situ keterangannya muncul.
     *
     * @return array<int,string>
     */
    public function catatanResolusi(): array
    {
        $catatan = [];

        if ($this->relatif()) {
            $catatan[] = '"'.$this->masukan.'" dibaca sebagai path relatif terhadap direktori kerja: '.$this->basis;
        }

        // Tilde diperluas oleh shell, bukan oleh PHP. Path yang ditulis di
        // dalam tanda kutip sampai ke sini apa adanya, lalu jatuh di dalam
        // akar proyek — dan orangnya melihat path yang tidak pernah ia tulis.
        if (str_starts_with($this->masukan, '~')) {
            $catatan[] = 'Tanda ~ hanya diperluas kalau path-nya ditulis TANPA tanda kutip. '
                .'Di dalam kutip, tulis path lengkapnya — mis. /Users/nama/Downloads/berkas.csv.';
        }

        return $catatan;
    }

    /** Izin berkas dalam bentuk oktal, mis. `0600`. */
    public function izin(): string
    {
        $mode = @fileperms($this->absolut);

        return $mode === false ? 'tidak diketahui' : mb_substr(sprintf('%o', $mode), -4);
    }

    public function pemilik(): string
    {
        $uid = @fileowner($this->absolut);

        return $uid === false ? 'tidak diketahui' : $this->namaPengguna($uid);
    }

    public function penggunaSekarang(): string
    {
        return function_exists('posix_geteuid') ? $this->namaPengguna(posix_geteuid()) : 'tidak diketahui';
    }

    private function namaPengguna(int $uid): string
    {
        $data = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;

        return $data === false ? 'uid '.$uid : $data['name'].' (uid '.$uid.')';
    }

    private function resolusi(): string
    {
        $path = $this->relatif()
            ? rtrim($this->basis, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->masukan
            : $this->masukan;

        $nyata = realpath($path);

        if ($nyata !== false) {
            return $nyata;
        }

        // Berkasnya tidak ada, jadi realpath menolak — padahal justru di
        // kasus inilah orangnya paling butuh melihat path yang utuh. Dirapikan
        // sendiri: `./` dibuang, `..` dinaikkan satu tingkat.
        $bagian = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $potong) {
            if ($potong === '' || $potong === '.') {
                continue;
            }

            if ($potong === '..') {
                array_pop($bagian);

                continue;
            }

            $bagian[] = $potong;
        }

        return DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $bagian);
    }
}
