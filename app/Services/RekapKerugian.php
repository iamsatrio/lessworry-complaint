<?php

namespace App\Services;

use App\Models\Complaint;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Angka halaman Kerugian. (API-43)
 *
 * Pertanyaan yang dijawab halaman itu: "berapa uang yang keluar karena
 * complaint, dan dari mana asalnya". Sebelum ini jawabannya hanya ada di
 * spreadsheet dan tidak pernah dijumlahkan.
 *
 * Semuanya dihitung dari koleksi complaint yang SUDAH melewati
 * `Complaint::visibleTo()`, rentang tanggal, dan saringan outlet — koleksi
 * yang sama yang dipakai halaman Laporan (lihat SaringanLaporan). Tidak ada
 * kueri data sendiri di sini: satu jalur data berarti satu jalur wewenang,
 * dan kasir yang tidak boleh melihat biaya outlet lain dijaga di satu tempat,
 * bukan di setiap halaman.
 *
 * Tiga aturan yang membuat halaman ini tidak berbohong, dan ketiganya bukan
 * soal tampilan:
 *
 * 1. TIDAK ADA total tanpa cakupannya. Kolom biaya terisi 96% pada 2025 dan
 *    38% pada 2026; menjumlah apa adanya akan menyimpulkan kerugian 2026
 *    turun setengah, padahal yang turun pengisian kolomnya.
 * 2. Complaint tanpa nilai tercatat BUKAN Rp 0 — lihat NilaiBiaya::tercatat().
 * 3. Median berdampingan dengan rata-rata. Satu kasus Rp 3.330.000 menarik
 *    rata-rata dan membuat bulan yang baik terlihat buruk.
 */
final class RekapKerugian
{
    /**
     * Tindak lanjut yang berarti KAS BENAR-BENAR BERKURANG — uang berpindah
     * ke pelanggan.
     */
    public const UANG_KELUAR = ['compensate', 'voucher'];

    /**
     * Tindak lanjut yang berarti KERJA ULANG — biaya bahan dan tenaga di
     * dalam, bukan uang ke pelanggan.
     */
    public const KERJA_ULANG = ['proses_ulang', 'repair', 'repaint', 'delivery_ulang', 'pickup_ulang'];

    /** @var list<string>|null */
    private ?array $sumbu = null;

    /** @var EloquentCollection<int,Complaint>|null */
    private ?EloquentCollection $bernilai = null;

    /**
     * @param  EloquentCollection<int,Complaint>  $complaints  sudah disaring wewenang, rentang tanggal, dan outlet
     */
    public function __construct(
        private readonly EloquentCollection $complaints,
        private readonly SatuanWaktu $satuan,
    ) {}

    public function satuan(): SatuanWaktu
    {
        return $this->satuan;
    }

    /* ---------- Ringkasan ---------- */

    /**
     * Angka-angka kepala halaman. `rata` DAN `median` dua-duanya ikut: yang
     * satu tanpa yang lain menyembunyikan persis hal yang membuat angka
     * kerugian sulit dibaca — sebaran yang panjang ekornya.
     *
     * @return array{total:int,terisi:int,biaya:int,persen:int|null,rendah:bool,rata:int|null,median:int|null,tertinggi:int|null}
     */
    public function ringkasan(): array
    {
        $nilai = $this->nilaiTercatat();
        $total = $this->complaints->count();
        $persen = NilaiBiaya::persen(count($nilai), $total);

        return [
            'total' => $total,
            'terisi' => count($nilai),
            'biaya' => (int) array_sum($nilai),
            'persen' => $persen,
            'rendah' => NilaiBiaya::rendah($persen),
            // Pembaginya jumlah yang PUNYA nilai, bukan jumlah complaint:
            // rata-rata yang ikut membagi dengan kolom kosong turun setiap
            // kali ada orang yang lupa mengisi.
            'rata' => $nilai === [] ? null : (int) round(array_sum($nilai) / count($nilai)),
            'median' => $nilai === [] ? null : (int) round(NilaiBiaya::median(array_map('floatval', $nilai))),
            'tertinggi' => $nilai === [] ? null : max($nilai),
        ];
    }

    /* ---------- Uang keluar vs kerja ulang ---------- */

    /**
     * Dua jenis kerugian yang tidak boleh dijumlahkan tanpa dibedakan, plus
     * golongan ketiga yang jujur.
     *
     * Rp 15,7 juta uang keluar berbeda artinya dari Rp 12,4 juta kerja ulang
     * walau jumlahnya mirip: yang pertama kas yang hilang, yang kedua
     * kapasitas yang terpakai dua kali.
     *
     * Golongan ketiga — `lainnya` — ADA DENGAN SENGAJA. Pada data nyata,
     * complaint bertindak-lanjut `tracking` dan `terkonfirmasi` (dan yang
     * tindak lanjutnya tidak diisi sama sekali) tetap membawa nilai biaya
     * tercatat, sekitar Rp 1,1 juta pada 545 baris pertama. Tanpa golongan
     * ketiga, dua golongan itu tidak pernah berjumlah sama dengan totalnya,
     * dan selisihnya hilang tanpa ada yang menyebutnya. Invariannya:
     * uang keluar + kerja ulang + lainnya = total biaya. Selalu.
     *
     * @return list<array{kunci:string,label:string,keterangan:string,kasus:int,terisi:int,biaya:int,persen:int|null,rendah:bool}>
     */
    public function golongan(): array
    {
        $bentuk = [
            ['kunci' => 'uang_keluar', 'label' => 'Uang keluar',
                'keterangan' => 'Kas berkurang — compensate dan voucher.'],
            ['kunci' => 'kerja_ulang', 'label' => 'Biaya kerja ulang',
                'keterangan' => 'Bahan dan tenaga — proses ulang, repair, repaint, delivery ulang, pickup ulang.'],
            ['kunci' => 'lainnya', 'label' => 'Belum digolongkan',
                'keterangan' => 'Tindak lanjutnya tidak menyatakan uang keluar maupun kerja ulang, atau belum diisi.'],
        ];

        $isi = $this->complaints->groupBy(fn (Complaint $c) => self::golonganDari($c->tindak_lanjut));

        return array_map(function (array $g) use ($isi) {
            /** @var Collection<int,Complaint> $anggota */
            $anggota = $isi->get($g['kunci'], collect());

            return $g + $this->angka($anggota);
        }, $bentuk);
    }

    /** Golongan sebuah tindak lanjut. Yang belum diisi ikut `lainnya`, bukan hilang. */
    public static function golonganDari(?string $tindakLanjut): string
    {
        if ($tindakLanjut !== null && in_array($tindakLanjut, self::UANG_KELUAR, true)) {
            return 'uang_keluar';
        }

        if ($tindakLanjut !== null && in_array($tindakLanjut, self::KERJA_ULANG, true)) {
            return 'kerja_ulang';
        }

        return 'lainnya';
    }

    /* ---------- Tiga pengelompokan ---------- */

    /**
     * @return list<array{label:string,kasus:int,terisi:int,biaya:int,persen:int|null,rendah:bool}>
     */
    public function perKategori(): array
    {
        return $this->kelompok(fn (Complaint $c) => $c->categoryLabel());
    }

    /**
     * Per outlet. Complaint tanpa outlet tidak dibuang — ia muncul sebagai
     * `Tanpa outlet`, karena total yang diam-diam kehilangan sebagian barisnya
     * lebih menyesatkan daripada baris berlabel jujur.
     *
     * Isinya mengikuti wewenang pembacanya dengan sendirinya: koleksinya sudah
     * lewat `visibleTo()`, jadi kasir hanya menemukan outletnya di sini.
     *
     * @return list<array{label:string,kasus:int,terisi:int,biaya:int,persen:int|null,rendah:bool}>
     */
    public function perOutlet(): array
    {
        return $this->kelompok(fn (Complaint $c) => $c->outlet->name ?? 'Tanpa outlet');
    }

    /**
     * @return list<array{label:string,kasus:int,terisi:int,biaya:int,persen:int|null,rendah:bool}>
     */
    public function perTindakLanjut(): array
    {
        return $this->kelompok(fn (Complaint $c) => $c->tindak_lanjut === null
            ? 'Belum diisi'
            : $c->tindakLanjutLabel());
    }

    /**
     * Satu bentuk untuk ketiga pengelompokan: jumlah kasus, jumlah yang punya
     * nilai, total biaya, dan cakupannya. Terurut menurut biaya — urutan
     * besar-ke-kecil itulah isi pesannya, bahwa Barang Rusak menyedot 65%
     * biaya dari 22% kasus.
     *
     * @param  callable(Complaint):string  $kunci
     * @return list<array{label:string,kasus:int,terisi:int,biaya:int,persen:int|null,rendah:bool}>
     */
    private function kelompok(callable $kunci): array
    {
        $baris = $this->complaints
            ->groupBy($kunci)
            ->map(fn ($isi, $label) => ['label' => (string) $label] + $this->angka($isi))
            ->sortByDesc('biaya')
            ->values()
            ->all();

        /** @var list<array{label:string,kasus:int,terisi:int,biaya:int,persen:int|null,rendah:bool}> $baris */
        return $baris;
    }

    /* ---------- Tren per periode ---------- */

    /**
     * Biaya per periode, dengan cakupannya berdampingan.
     *
     * Cakupan ikut per periode, bukan hanya sekali di kepala halaman: yang
     * berbahaya justru penurunan biaya antar bulan yang sebenarnya lubang
     * data. Periode dengan cakupan di bawah ambang ditandai `rendah`, dan
     * halaman WAJIB memperlihatkan tandanya.
     *
     * @return list<array{periode:string,label:string,judul:string,kasus:int,terisi:int,biaya:int,persen:int|null,rendah:bool}>
     */
    public function perPeriode(): array
    {
        $isi = $this->complaints
            ->filter(fn (Complaint $c) => $c->created_at !== null)
            ->groupBy(fn (Complaint $c) => $this->satuan->kunci($c->created_at));

        $baris = [];

        foreach ($this->sumbuPeriode() as $periode) {
            $baris[] = [
                'periode' => $periode,
                'label' => $this->satuan->labelSumbu($periode),
                'judul' => $this->satuan->labelPenuh($periode),
            ] + $this->angka($isi->get($periode, collect()));
        }

        return $baris;
    }

    /**
     * Titik grafik biaya per periode.
     *
     * Keterangan tiap titik menyebut cakupannya, dan periode bercakupan
     * rendah mengatakannya di situ juga — angka yang hanya bisa dibaca benar
     * kalau pembacanya ingat catatan kaki adalah angka yang akan salah dibaca.
     *
     * @return list<array{label:string,judul:string,nilai:float|null,teks:string}>
     */
    public function titikBiaya(): array
    {
        return array_map(fn (array $b) => [
            'label' => $b['label'],
            'judul' => $b['judul'],
            'nilai' => (float) $b['biaya'],
            'teks' => NilaiBiaya::rupiah($b['biaya']).' · '
                .NilaiBiaya::cakupanTeks($b['terisi'], $b['kasus'])
                .($b['rendah'] ? ' · cakupan rendah' : ''),
        ], $this->perPeriode());
    }

    /**
     * Titik grafik cakupan, dalam persen. Grafik KEDUA, bukan sumbu kedua di
     * grafik biaya: dua skala pada satu gambar membuat keduanya salah dibaca.
     *
     * Periode tanpa satu pun complaint tidak digambar sebagai 0% — cakupannya
     * tidak ada, dan nol berarti "diukur, hasilnya kosong".
     *
     * @return list<array{label:string,judul:string,nilai:float|null,teks:string}>
     */
    public function titikCakupan(): array
    {
        return array_map(fn (array $b) => [
            'label' => $b['label'],
            'judul' => $b['judul'],
            'nilai' => $b['persen'] === null ? null : (float) $b['persen'],
            'teks' => $b['persen'] === null
                ? 'tidak ada complaint pada periode ini'
                : $b['persen'].'% — '.NilaiBiaya::cakupanTeks($b['terisi'], $b['kasus']),
        ], $this->perPeriode());
    }

    /**
     * Batang biaya untuk salah satu pengelompokan di atas.
     *
     * @param  list<array{label:string,kasus:int,terisi:int,biaya:int,persen:int|null,rendah:bool}>  $baris
     * @return list<array{label:string,nilai:float,teks:string,judul:string}>
     */
    public function batang(array $baris): array
    {
        return array_map(fn (array $b) => [
            'label' => $b['label'],
            'nilai' => (float) $b['biaya'],
            // Pembaginya yang punya nilai biaya, bukan seluruh kasus di
            // kelompok ini. Batang Rp 1.000.000 di sebelah "4 kasus" dibaca
            // sebagai Rp 250.000 per kasus; kalau rupiahnya datang dari satu
            // complaint, angka yang terbaca itu tidak pernah ada. Kelompok
            // bercakupan rendah akan selalu terlihat lebih murah per kasus
            // daripada kelompok bercakupan penuh — padahal yang rendah
            // pengisian kolomnya, bukan biayanya. (Tinjauan PR #35)
            'teks' => NilaiBiaya::rupiah($b['biaya']).' · '
                .$b['terisi'].' dari '.$b['kasus'].' kasus bernilai',
            'judul' => $b['label'].' · '.NilaiBiaya::rupiah($b['biaya']).' · '
                .NilaiBiaya::cakupanTeks($b['terisi'], $b['kasus']),
        ], array_values(array_filter($baris, fn (array $b) => $b['biaya'] > 0)));
    }

    /** Periode yang cakupannya rendah — dihitung, bukan dicari pembacanya. */
    public function periodeRendah(): int
    {
        return count(array_filter(
            $this->perPeriode(),
            fn (array $b) => $b['rendah'] && $b['kasus'] > 0,
        ));
    }

    /* ---------- Bahan ekspor ---------- */

    /**
     * Complaint yang punya nilai biaya, termahal lebih dulu. Inilah baris
     * yang diekspor — dan juga yang dipakai daftar "kasus termahal" di
     * halaman.
     *
     * @return Collection<int,Complaint>
     */
    public function complaintBerbiaya(): Collection
    {
        return $this->bernilaiTercatat()->sortByDesc('compensation_amount')->values();
    }

    /* ---------- Dalaman ---------- */

    /**
     * Empat angka yang selalu berpasangan: jumlah kasus, jumlah yang punya
     * nilai, totalnya, dan cakupannya. Dipakai ringkasan, ketiga
     * pengelompokan, dan tren — satu tempat, supaya tidak ada satu pun total
     * yang bisa tampil tanpa cakupannya karena penulisnya lupa.
     *
     * @param  Collection<int,Complaint>  $isi
     * @return array{kasus:int,terisi:int,biaya:int,persen:int|null,rendah:bool}
     */
    private function angka(Collection $isi): array
    {
        $bernilai = $isi->filter(fn (Complaint $c) => NilaiBiaya::tercatat($c));
        $persen = NilaiBiaya::persen($bernilai->count(), $isi->count());

        return [
            'kasus' => $isi->count(),
            'terisi' => $bernilai->count(),
            'biaya' => (int) $bernilai->sum('compensation_amount'),
            'persen' => $persen,
            'rendah' => NilaiBiaya::rendah($persen),
        ];
    }

    /** @return EloquentCollection<int,Complaint> */
    private function bernilaiTercatat(): EloquentCollection
    {
        return $this->bernilai ??= $this->complaints->filter(fn (Complaint $c) => NilaiBiaya::tercatat($c));
    }

    /** @return list<int> */
    private function nilaiTercatat(): array
    {
        return $this->bernilaiTercatat()
            ->map(fn (Complaint $c) => (int) $c->compensation_amount)
            ->values()
            ->all();
    }

    /**
     * Periode complaint pertama sampai periode complaint terakhir DI DALAM
     * rentang terpilih, tanpa bolong. Sama persis dengan sumbu grafik halaman
     * Laporan: periode kosong di tengah tetap digambar, periode kosong di
     * ujung tidak.
     *
     * @return list<string>
     */
    private function sumbuPeriode(): array
    {
        if ($this->sumbu !== null) {
            return $this->sumbu;
        }

        $kunci = $this->complaints
            ->filter(fn (Complaint $c) => $c->created_at !== null)
            ->map(fn (Complaint $c) => $this->satuan->kunci($c->created_at))
            ->unique()->sort()->values();

        if ($kunci->isEmpty()) {
            return $this->sumbu = [];
        }

        $periode = (string) $kunci->first();
        $terakhir = (string) $kunci->last();
        $sumbu = [];

        while ($periode <= $terakhir) {
            $sumbu[] = $periode;
            $periode = $this->satuan->sesudah($periode);
        }

        return $this->sumbu = $sumbu;
    }
}
