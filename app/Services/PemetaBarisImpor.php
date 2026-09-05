<?php

namespace App\Services;

use App\Models\Outlet;
use Illuminate\Support\Carbon;

/**
 * Menerjemahkan satu baris spreadsheet complaint lama menjadi bentuk yang
 * dipahami tabel `complaints`. (API-28)
 *
 * Dipisah dari perintahnya supaya bisa diuji per baris tanpa menyentuh
 * berkas, dan supaya setiap aturan penerjemahan punya satu tempat.
 *
 * Dua prinsip yang menentukan hampir semua keputusan di kelas ini:
 *
 * 1. **Nilai yang tidak dikenali dicatat, bukan ditebak.** Baris tetap
 *    masuk, nilainya jatuh ke tempat yang jujur (`lainnya`, null), dan
 *    keanehannya muncul di laporan impor. Menebak membuat cacat data
 *    menghilang dari pandangan justru saat sedang diperiksa.
 * 2. **Pencocokan selalu eksplisit, tidak pernah samar.** Nama outlet
 *    dicocokkan lewat peta padanan yang ditulis di sini. Pencocokan samar
 *    (mirip-mirip) akan menempelkan complaint ke outlet yang salah suatu
 *    hari nanti, dan tidak ada yang tahu kapan.
 */
class PemetaBarisImpor
{
    /**
     * Kolom yang tanpa isinya baris tidak bisa jadi complaint.
     * `Issue` sengaja TIDAK di sini: 2 baris memang kosong uraiannya, dan
     * membuang keluhan yang sungguh terjadi lebih merugikan daripada
     * menyimpannya dengan uraian bertanda kosong.
     */
    private const WAJIB = ['Date', 'Name'];

    /**
     * Nama outlet di spreadsheet → nama outlet di sistem. Ditulis satu per
     * satu dengan sengaja.
     *
     * `Park Sepong` salah ketik di sumbernya. Tidak diperbaiki di
     * spreadsheet — perbaikan diam-diam di sumber menyembunyikan bahwa
     * datanya pernah salah.
     */
    private const PADANAN_OUTLET = [
        'hampton gs' => 'Hampton Gading Serpong',
        'park sepong' => 'Park Serpong',
        'jatipadang' => 'Jati Padang',
    ];

    /** `Kiloan Cuset` dan kawan-kawan tidak sama persis dengan label config. */
    private const PADANAN_LAYANAN = [
        'kiloan cuset' => 'kiloan_cuset',
        'kiloan culip' => 'kiloan_culip',
        'satuan - cloth' => 'satuan_cloth',
        'satuan - non cloth' => 'satuan_non_cloth',
    ];

    /** Kanal masuk tidak dicatat di spreadsheet. Ditandai, bukan dikarang. */
    public const KANAL = 'impor';

    /** Dipakai saat `Issue` kosong — penanda, bukan uraian karangan. */
    public const URAIAN_KOSONG = '(uraian tidak ada di data sumber)';

    /** @var array<string,int>|null */
    private ?array $outlet = null;

    /**
     * @param  array<string,string>  $baris
     * @return array{data:array<string,mixed>,anomali:list<array{kolom:string,alasan:string}>,galat:list<string>}
     */
    public function petakan(array $baris): array
    {
        $anomali = [];
        $galat = [];

        foreach (self::WAJIB as $kolom) {
            if ($this->ambil($baris, $kolom) === '') {
                $galat[] = 'kolom '.$kolom.' kosong';
            }
        }

        $masuk = $this->tanggal($this->ambil($baris, 'Date'));

        if ($masuk === null) {
            $galat[] = 'kolom Date tidak terbaca';
        }

        if ($galat !== []) {
            return ['data' => [], 'anomali' => $anomali, 'galat' => $galat];
        }

        [$outletId, $namaOutletLama] = $this->outlet($this->ambil($baris, 'Outlet'), $anomali);
        [$kompensasi, $notaKompensasi] = $this->uang($this->ambil($baris, 'Tindak lanjut cost'));

        if ($notaKompensasi !== null) {
            $anomali[] = ['kolom' => 'Tindak lanjut cost', 'alasan' => $notaKompensasi];
        }

        $status = $this->enum('statuses', $this->ambil($baris, 'Status'));

        if ($status === null) {
            $anomali[] = ['kolom' => 'Status', 'alasan' => 'nilai tidak ada padanannya, dianggap open'];
            $status = 'open';
        }

        $tutup = $this->tanggal($this->ambil($baris, 'Date status close'));

        // Close tanpa tanggal tutup: waktu penyelesaiannya TIDAK DIKETAHUI,
        // bukan nol. Mengisinya dengan tanggal masuk supaya kolomnya terisi
        // akan membuat laporan mengumumkan penyelesaian instan yang tidak
        // pernah terjadi.
        if ($status === 'close' && $tutup === null) {
            $anomali[] = ['kolom' => 'Date status close', 'alasan' => 'Close tanpa tanggal tutup'];
        }

        $uraian = $this->ambil($baris, 'Issue');

        if ($uraian === '') {
            $anomali[] = ['kolom' => 'Issue', 'alasan' => 'uraian kosong'];
            $uraian = self::URAIAN_KOSONG;
        }

        $data = [
            'channel' => self::KANAL,
            'reporter_name' => $this->ambil($baris, 'Name'),
            // `No HP` terisi 0 dari 545. Dibiarkan kosong, bukan diisi '-'.
            'reporter_phone' => $this->ambil($baris, 'No HP') ?: null,
            'outlet_id' => $outletId,
            'legacy_outlet_name' => $namaOutletLama,
            'category' => $this->kategori($this->ambil($baris, 'Issue Category'), $anomali),
            'bobot' => $this->bobot($this->ambil($baris, 'Category Complaint'), $anomali),
            'layanan' => $this->layanan($this->ambil($baris, 'Layanan'), $anomali),
            'tindak_lanjut' => $this->tindakLanjut($this->ambil($baris, 'Tindak Lanjut Category'), $anomali),
            'description' => $uraian,
            'resolution' => $this->ambil($baris, 'Tindak lanjut') ?: null,
            'status' => $status,
            'compensation_amount' => $kompensasi ?? 0,
            'created_at' => $masuk,
            'resolved_at' => $status === 'close' ? $tutup : null,
            // Nomor nota lama TIDAK pernah ke kolom NEVIRA. Angkanya tidak
            // unik; menautkannya berarti menempelkan keluhan ini ke order
            // pelanggan lain. (API-28 bagian 3)
            'legacy_nota_number' => $this->ambil($baris, 'Nomor Nota') ?: null,
            'legacy_pelaku' => $this->pelaku($this->ambil($baris, 'Pelaku')),
        ];

        return ['data' => $data, 'anomali' => $anomali, 'galat' => []];
    }

    /** Catatan bebas yang menempel pada complaint, kalau ada. */
    public function catatan(array $baris): ?string
    {
        return $this->ambil($baris, 'Note') ?: null;
    }

    /** `Tipe` tidak diimpor, tapi jumlahnya dihitung untuk laporan. (API-28) */
    public function tipe(array $baris): string
    {
        return $this->ambil($baris, 'Tipe');
    }

    /* ---------- penerjemah per kolom ---------- */

    /**
     * `M/D/YYYY`. Jam tidak ada di sumber, jadi tengah malam — bukan jam
     * impor dijalankan, yang akan membuat semua 545 baris terlihat masuk
     * pada satu menit yang sama.
     */
    public function tanggal(string $mentah): ?Carbon
    {
        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $mentah, $m)) {
            return null;
        }

        [, $bulan, $hari, $tahun] = $m;

        if (! checkdate((int) $bulan, (int) $hari, (int) $tahun)) {
            return null;
        }

        return Carbon::create((int) $tahun, (int) $bulan, (int) $hari)->startOfDay();
    }

    /**
     * Nilai rupiah dengan dua bentuk yang bercampur di satu kolom:
     * `142,500.00` dan `Rp2,525,000`. Keduanya harus diterima.
     *
     * Pemisah ribuan ditentukan dari bentuknya, bukan diterka:
     * ada koma → koma ribuan dan titik desimal (`142,500.00`);
     * tidak ada koma tapi titik diikuti TEPAT tiga angka → titik ribuan
     * (`91.000` = sembilan puluh satu ribu, bukan sembilan puluh satu).
     *
     * `Rp207` diimpor apa adanya. Dua ratus tujuh rupiah memang aneh untuk
     * kompensasi, tapi menaikkannya jadi Rp207.000 adalah mengarang angka
     * kompensasi — angka yang orang lain akan pakai untuk menghitung biaya.
     *
     * @return array{0:?int,1:?string}
     */
    public function uang(string $mentah): array
    {
        $bersih = trim(preg_replace('/^rp\.?\s*/i', '', trim($mentah)) ?? '');

        if ($bersih === '') {
            return [null, null];
        }

        $catatan = null;

        if (str_contains($bersih, ',')) {
            $bersih = str_replace(',', '', $bersih);
        } elseif (preg_match('/^\d+\.\d{3}$/', $bersih)) {
            $bersih = str_replace('.', '', $bersih);
            $catatan = 'titik dibaca sebagai pemisah ribuan';
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $bersih)) {
            // Beberapa baris memakai kolom biaya untuk menulis catatan
            // tindak lanjut. Bukan angka, jadi tidak dipaksa jadi angka.
            return [null, 'bukan angka, kompensasi dianggap 0'];
        }

        return [(int) round((float) $bersih), $catatan];
    }

    /**
     * @param  list<array{kolom:string,alasan:string}>  $anomali
     * @return array{0:?int,1:?string}
     */
    private function outlet(string $mentah, array &$anomali): array
    {
        if ($mentah === '') {
            $anomali[] = ['kolom' => 'Outlet', 'alasan' => 'kosong'];

            return [null, null];
        }

        // Awalan `Less Worry <n> - ` dibuang; yang membedakan outlet adalah
        // namanya, bukan nomor urut yang dipakai tim di spreadsheet.
        $nama = trim((string) preg_replace('/^Less\s*Worry\s*[\d.]*\s*-\s*/i', '', $mentah));
        $kunci = $this->normal($nama);
        $kunci = $this->normal(self::PADANAN_OUTLET[$kunci] ?? $nama);

        $daftar = $this->daftarOutlet();

        if (isset($daftar[$kunci])) {
            return [$daftar[$kunci], null];
        }

        // Tidak ada padanannya. Baris tetap masuk dengan outlet kosong dan
        // nama aslinya disimpan: complaint tanpa outlet bisa dibetulkan
        // nanti, complaint di outlet yang salah tidak akan pernah ketahuan.
        $anomali[] = ['kolom' => 'Outlet', 'alasan' => 'tidak ada padanannya: '.$mentah];

        return [null, $mentah];
    }

    /** @param list<array{kolom:string,alasan:string}> $anomali */
    private function kategori(string $mentah, array &$anomali): string
    {
        // Pencocokan tidak peduli besar-kecil huruf; itulah yang merapikan
        // `Kurang rapih` dan `Kurang Rapih` jadi satu kategori, bukan dua
        // baris berbeda di laporan.
        $kunci = $this->cariLabel('categories', $mentah, fn ($isi) => $isi['label']);

        if ($kunci !== null) {
            return $kunci;
        }

        $anomali[] = [
            'kolom' => 'Issue Category',
            'alasan' => $mentah === '' ? 'kosong, jatuh ke lainnya' : 'tidak ada padanannya: '.$mentah,
        ];

        return 'lainnya';
    }

    /** @param list<array{kolom:string,alasan:string}> $anomali */
    private function bobot(string $mentah, array &$anomali): string
    {
        $kunci = $this->enum('bobot', $mentah);

        if ($kunci !== null) {
            return $kunci;
        }

        $anomali[] = [
            'kolom' => 'Category Complaint',
            'alasan' => $mentah === '' ? 'kosong, jatuh ke sedang' : 'tidak ada padanannya: '.$mentah,
        ];

        // Jatuh ke tengah, bukan ke Ringan: menganggap keluhan yang tidak
        // diketahui bobotnya sebagai yang paling ringan memberinya tenggat
        // paling pendek dan membuatnya terlihat telat tanpa sebab.
        return 'sedang';
    }

    /** @param list<array{kolom:string,alasan:string}> $anomali */
    private function layanan(string $mentah, array &$anomali): ?string
    {
        // `-` di kolom layanan artinya tidak dicatat, sama seperti kosong.
        if ($mentah === '' || $mentah === '-') {
            $anomali[] = ['kolom' => 'Layanan', 'alasan' => 'kosong'];

            return null;
        }

        $kunci = self::PADANAN_LAYANAN[$this->normal($mentah)]
            ?? $this->cariLabel('layanan', $mentah, fn ($label) => $label);

        if ($kunci !== null) {
            return $kunci;
        }

        $anomali[] = ['kolom' => 'Layanan', 'alasan' => 'tidak ada padanannya: '.$mentah];

        return null;
    }

    /** @param list<array{kolom:string,alasan:string}> $anomali */
    private function tindakLanjut(string $mentah, array &$anomali): ?string
    {
        if ($mentah === '') {
            $anomali[] = ['kolom' => 'Tindak Lanjut Category', 'alasan' => 'kosong'];

            return null;
        }

        $kunci = $this->cariLabel('tindak_lanjut', $mentah, fn ($label) => $label);

        if ($kunci !== null) {
            return $kunci;
        }

        $anomali[] = ['kolom' => 'Tindak Lanjut Category', 'alasan' => 'tidak ada padanannya: '.$mentah];

        return null;
    }

    /** `-` di kolom Pelaku berarti tidak ada pelaku, bukan orang bernama `-`. */
    private function pelaku(string $mentah): ?string
    {
        return ($mentah === '' || $mentah === '-') ? null : $mentah;
    }

    /* ---------- alat ---------- */

    /** @param array<string,string> $baris */
    private function ambil(array $baris, string $kolom): string
    {
        return trim($baris[$kolom] ?? '');
    }

    private function normal(string $nilai): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $nilai)));
    }

    /** Cocokkan nilai spreadsheet dengan label sebuah daftar di config. */
    private function enum(string $daftar, string $mentah): ?string
    {
        return $this->cariLabel($daftar, $mentah, fn ($label) => $label);
    }

    /**
     * @param  callable(mixed):string  $label
     */
    private function cariLabel(string $daftar, string $mentah, callable $label): ?string
    {
        if ($mentah === '') {
            return null;
        }

        $cari = $this->normal($mentah);

        /** @var array<string,mixed> $isi */
        $isi = config('complaint.'.$daftar, []);

        foreach ($isi as $kunci => $nilai) {
            if ($this->normal($label($nilai)) === $cari) {
                return (string) $kunci;
            }
        }

        return null;
    }

    /** @return array<string,int> */
    private function daftarOutlet(): array
    {
        if ($this->outlet === null) {
            $this->outlet = Outlet::query()->get(['id', 'name'])
                ->mapWithKeys(fn (Outlet $o) => [$this->normal($o->name) => $o->id])
                ->all();
        }

        return $this->outlet;
    }
}
