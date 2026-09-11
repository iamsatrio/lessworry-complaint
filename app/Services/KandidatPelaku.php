<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ComplaintResponsible;
use App\Models\User;

/**
 * Daftar orang yang bisa ditetapkan sebagai pelaku satu complaint. (API-19)
 *
 * Di spreadsheet lama kolom Pelaku terisi 2 dari 90 baris. Sebabnya bukan
 * tidak ada pelakunya — mengisinya berarti mengingat nama dan NIP lalu
 * mengetiknya. Karena itu daftar ini disusun server, diurutkan dari yang
 * paling mungkin:
 *
 *   1. orang yang MEMANG menyentuh nota ini menurut NEVIRA — kasir penerima,
 *      tiap tahap produksi, dan kurirnya. Perannya sudah diketahui, jadi
 *      petugas cukup mencentang nama dan menulis alasan.
 *   2. karyawan outlet nota itu, ditarik lewat NeviraGate.
 *   3. pengguna sistem complaint sendiri — Customer Care ikut bisa jadi pelaku.
 *
 * Kunci kandidat sengaja tidak membawa nama: yang dikirim browser hanya
 * kuncinya, dan identitas karyawannya dibaca server dari daftarnya sendiri.
 *
 * Kunci yang DIRENDER bukan identitas itu sendiri melainkan HMAC-nya, karena
 * identitasnya berbentuk `staff:<id_staff NEVIRA>` dan id itu tidak boleh
 * sampai ke browser. Lihat kunciPublik(). (API-58 nomor 1)
 */
class KandidatPelaku
{
    /** @var array<string,array<string,mixed>> */
    private array $kandidat = [];

    /** @var array<int,array{label:string,collapsed:bool,items:array<int,array<string,mixed>>}> */
    private array $grup = [];

    /**
     * @param  array<int,array{staff_id:?string,name:string,nip:?string}>  $karyawanOutlet
     * @param  iterable<User>  $penggunaSistem
     */
    public static function untuk(Complaint $complaint, array $karyawanOutlet = [], iterable $penggunaSistem = []): self
    {
        $daftar = new self;

        foreach ($daftar->grupDariNota($complaint) as $grup) {
            $daftar->tambahGrup($grup['label'], $grup['items'], $grup['collapsed']);
        }

        $daftar->tambahGrup(
            'Karyawan '.($complaint->outlet?->name ? 'outlet '.$complaint->outlet->name : 'outlet nota ini'),
            collect($karyawanOutlet)->map(fn ($k) => [
                'staff_id' => $k['staff_id'] ?? null,
                'name' => $k['name'],
                'nip' => $k['nip'] ?? null,
                'role' => 'lainnya',
                'stage' => null,
                'service_index' => null,
            ])->all()
        );
        $daftar->tambahGrup('Pengguna sistem complaint', collect($penggunaSistem)->map(fn ($u) => [
            'staff_id' => null,
            'name' => $u->name,
            'nip' => null,
            'role' => $u->isCustomerCare() ? 'customer_care' : ($u->isKasir() ? 'kasir' : 'lainnya'),
            'stage' => null,
            'service_index' => null,
        ])->all());

        return $daftar;
    }

    /** @return array<int,array{label:string,collapsed:bool,items:array<int,array<string,mixed>>}> */
    public function groups(): array
    {
        return array_values(array_filter($this->grup, fn ($g) => $g['items'] !== []));
    }

    public function find(string $key): ?array
    {
        return $this->kandidat[$key] ?? null;
    }

    /**
     * Kunci yang boleh dilihat browser. (API-58 nomor 1)
     *
     * Identitas kandidat berbentuk `staff:<id_staff>` kalau orangnya dikenal
     * NEVIRA, dan `id_staff` adalah pengenal internal sistem lain — aturan
     * repositori ini melarangnya sampai ke browser, sama seperti
     * `nevira_transaction_id`. Sebelum ini ia terkirim apa adanya sebagai
     * `value` checkbox dan sebagai nama `peran[...]`.
     *
     * Kuncinya TIDAK boleh sekadar dibuang: rancangan API-19 berdiri di atas
     * browser mengirim kunci dan server membaca nama/NIP/id dari daftarnya
     * sendiri — itu yang membuat nama karyawan tidak bisa disuntikkan lewat
     * form. Jadi bentuknya yang diganti, bukan perannya.
     *
     * HMAC dengan APP_KEY, bukan indeks posisi di daftar: indeks berubah
     * artinya kalau daftar kandidat berubah antara halaman dirender dan form
     * dikirim — NEVIRA mati di antara keduanya sudah cukup — dan pergeseran
     * satu posisi berarti pelaku yang salah tercatat tanpa satu pun galat.
     * HMAC terikat pada orangnya, bukan pada urutannya: kalau orangnya hilang
     * dari daftar, kuncinya tidak ketemu dan form-nya ditolak dengan terang.
     *
     * Bukan rahasia yang dijaga kerahasiaannya, melainkan penyamaran satu
     * arah: tanpa APP_KEY, `staff:...` tidak bisa dipulihkan dari 32 heksa
     * ini, dan nilainya tetap sama antar permintaan sehingga old() dan
     * `peran[<kunci>]` tetap bekerja.
     */
    public static function kunciPublik(string $identity): string
    {
        return substr(hash_hmac('sha256', $identity, (string) config('app.key')), 0, 32);
    }

    public function isEmpty(): bool
    {
        return $this->kandidat === [];
    }

    /**
     * Orang dari nota ini, dipisah menurut barang yang dikeluhkan.
     *
     * Complaint yang menunjuk satu baris layanan menampilkan yang
     * mengerjakan baris itu lebih dulu; sisanya tetap ada, hanya terlipat.
     * Kesalahan bisa terjadi di tahap mana pun — pengemasan yang mencampur
     * barang antar-baris, misalnya. Mempersempit itu menolong, mengunci itu
     * menebak. (API-51)
     *
     * @return array<int,array{label:string,collapsed:bool,items:array<int,array<string,mixed>>}>
     */
    private function grupDariNota(Complaint $complaint): array
    {
        $items = $this->dariNota($complaint);
        $dipilih = $complaint->nevira_service_index;

        if ($dipilih === null || ! $complaint->hasMultipleServices()) {
            return [['label' => 'Tercatat di nota ini', 'collapsed' => false, 'items' => $items]];
        }

        // Yang tidak menempel pada satu baris — kasir penerima, kurirnya —
        // menyentuh seluruh nota, jadi ia tetap relevan untuk barang apa pun.
        $terkait = fn (array $i) => in_array($i['service_index'] ?? null, [null, $dipilih], true);

        return [
            [
                'label' => 'Mengerjakan barang yang dikeluhkan',
                'collapsed' => false,
                'items' => array_values(array_filter($items, $terkait)),
            ],
            [
                'label' => 'Barang lain di nota yang sama',
                'collapsed' => true,
                'items' => array_values(array_filter($items, fn (array $i) => ! $terkait($i))),
            ],
        ];
    }

    /**
     * Orang yang menyentuh nota ini menurut NEVIRA: kasir penerima, tiap
     * tahap produksi, lalu kurirnya. Fakta, bukan tuduhan — yang menjadikannya
     * pelaku tetap penetapan manusia, lengkap dengan alasan.
     */
    private function dariNota(Complaint $complaint): array
    {
        $items = [];

        foreach ($complaint->orderHandlers() as $h) {
            $kasir = str_contains(mb_strtolower((string) $h['stage']), 'kasir');

            $items[] = [
                'staff_id' => isset($h['staff_id']) ? (string) $h['staff_id'] : null,
                'name' => $h['name'],
                'nip' => $h['nip'] ?? null,
                'role' => $kasir ? 'kasir' : 'produksi',
                'stage' => $kasir ? null : $h['stage'],
                'service_index' => $h['service_index'] ?? null,
            ];
        }

        foreach ($complaint->deliveries() as $d) {
            if (blank($d['courier_name'])) {
                continue;
            }

            $items[] = [
                'staff_id' => isset($d['courier_id']) ? (string) $d['courier_id'] : null,
                'name' => $d['courier_name'],
                'nip' => $d['courier_nip'] ?? null,
                'role' => 'kurir',
                'stage' => null,
                'service_index' => null,
            ];
        }

        return $items;
    }

    private function tambahGrup(string $label, array $items, bool $collapsed = false): void
    {
        $bersih = [];

        foreach ($items as $item) {
            if (blank($item['name'] ?? null)) {
                continue;
            }

            // Identitas internal tetap bentuk lamanya — ia yang dipakai
            // menyamakan orang dengan baris pelaku yang sudah tersimpan.
            // Yang dirender kunci publiknya.
            $key = self::kunciPublik(ComplaintResponsible::identityFor(
                $item['staff_id'] ?? null,
                $item['nip'] ?? null,
                $item['name']
            ));

            // Orang yang sama bisa muncul di beberapa sumber — kasir nota
            // ini juga ada di daftar karyawan outletnya. Yang pertama
            // menang, karena grup pertama membawa perannya sekalian.
            // Identitas yang sama menghasilkan HMAC yang sama, jadi
            // penyaringan ganda ini tidak berubah perilakunya.
            if (isset($this->kandidat[$key])) {
                continue;
            }

            $item['key'] = $key;
            $this->kandidat[$key] = $item;
            $bersih[] = $item;
        }

        $this->grup[] = ['label' => $label, 'collapsed' => $collapsed, 'items' => $bersih];
    }
}
