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
 */
class KandidatPelaku
{
    /** @var array<string,array<string,mixed>> */
    private array $kandidat = [];

    /** @var array<int,array{label:string,items:array<int,array<string,mixed>>}> */
    private array $grup = [];

    /**
     * @param  array<int,array{staff_id:?string,name:string,nip:?string}>  $karyawanOutlet
     * @param  iterable<User>  $penggunaSistem
     */
    public static function untuk(Complaint $complaint, array $karyawanOutlet = [], iterable $penggunaSistem = []): self
    {
        $daftar = new self;

        $daftar->tambahGrup('Tercatat di nota ini', $daftar->dariNota($complaint));
        $daftar->tambahGrup(
            'Karyawan '.($complaint->outlet?->name ? 'outlet '.$complaint->outlet->name : 'outlet nota ini'),
            collect($karyawanOutlet)->map(fn ($k) => [
                'staff_id' => $k['staff_id'] ?? null,
                'name' => $k['name'],
                'nip' => $k['nip'] ?? null,
                'role' => 'lainnya',
                'stage' => null,
            ])->all()
        );
        $daftar->tambahGrup('Pengguna sistem complaint', collect($penggunaSistem)->map(fn ($u) => [
            'staff_id' => null,
            'name' => $u->name,
            'nip' => null,
            'role' => $u->isCustomerCare() ? 'customer_care' : ($u->isKasir() ? 'kasir' : 'lainnya'),
            'stage' => null,
        ])->all());

        return $daftar;
    }

    /** @return array<int,array{label:string,items:array<int,array<string,mixed>>}> */
    public function groups(): array
    {
        return array_values(array_filter($this->grup, fn ($g) => $g['items'] !== []));
    }

    public function find(string $key): ?array
    {
        return $this->kandidat[$key] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->kandidat === [];
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
            ];
        }

        return $items;
    }

    private function tambahGrup(string $label, array $items): void
    {
        $bersih = [];

        foreach ($items as $item) {
            if (blank($item['name'] ?? null)) {
                continue;
            }

            $key = ComplaintResponsible::identityFor(
                $item['staff_id'] ?? null,
                $item['nip'] ?? null,
                $item['name']
            );

            // Orang yang sama bisa muncul di beberapa sumber — kasir nota
            // ini juga ada di daftar karyawan outletnya. Yang pertama
            // menang, karena grup pertama membawa perannya sekalian.
            if (isset($this->kandidat[$key])) {
                continue;
            }

            $item['key'] = $key;
            $this->kandidat[$key] = $item;
            $bersih[] = $item;
        }

        $this->grup[] = ['label' => $label, 'items' => $bersih];
    }
}
