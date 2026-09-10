<?php

namespace App\Services;

use App\Http\Requests\LaporanFilterRequest;
use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Saringan halaman Laporan, di satu tempat. (API-62 nomor 3)
 *
 * Halaman dan ekspornya dulu masing-masing menulis sendiri rentang tanggal
 * bawaannya dan kueri complaint-nya. Selama saringannya cuma dua tanggal,
 * duplikasi itu tidak berbahaya. Begitu ada saringan outlet yang menyangkut
 * wewenang, dua salinan berarti dua tempat yang bisa berbeda — dan yang
 * berbeda diam-diam adalah ekspornya, karena tidak ada yang melihatnya di
 * layar.
 *
 * Yang TIDAK dikerjakan di sini: memeriksa wewenang atas outlet yang diminta.
 * Itu sudah dijawab LaporanFilterRequest::authorize() sebelum controller
 * berjalan — permintaan yang sampai ke sini pasti sudah lolos.
 */
final class SaringanLaporan
{
    private function __construct(
        public readonly User $user,
        public readonly Carbon $dari,
        public readonly Carbon $sampai,
        public readonly ?Outlet $outlet,
    ) {}

    public static function dariPermintaan(LaporanFilterRequest $request): self
    {
        /** @var User $user */
        $user = $request->user();

        return new self(
            user: $user,
            dari: $request->date('from') ?? now()->subDays(30)->startOfDay(),
            sampai: $request->date('to') ?? now()->endOfDay(),
            outlet: $request->outletDiminta(),
        );
    }

    /**
     * Complaint yang masuk saringan ini — SATU-SATUNYA jalur pengambilan data
     * halaman Laporan. Grafik, kartu angka, tabel, dan ekspor semuanya
     * berangkat dari koleksi yang sama, jadi kebocoran wewenang tidak bisa
     * muncul di salah satunya saja.
     *
     * @param  list<string>  $with
     * @return EloquentCollection<int,Complaint>
     */
    public function complaints(array $with = ['outlet']): EloquentCollection
    {
        return Complaint::query()
            ->visibleTo($this->user)
            ->whereBetween('created_at', [$this->dari, $this->sampai])
            ->when($this->outlet !== null, fn ($q) => $q->where('outlet_id', $this->outlet?->id))
            ->with($with)
            ->get();
    }

    /**
     * Isi daftar pilihan outlet. Kasir hanya menemukan outletnya sendiri di
     * sini — bukan seluruh sebelas dengan yang lain ditolak saat dipilih.
     *
     * @return EloquentCollection<int,Outlet>
     */
    public function pilihanOutlet(): EloquentCollection
    {
        return Outlet::query()->visibleTo($this->user)->orderBy('name')->get();
    }

    public function outletId(): ?int
    {
        return $this->outlet?->id;
    }
}
