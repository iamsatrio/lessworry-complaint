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
        public readonly SatuanWaktu $satuan,
        /** Satuan waktunya dipilih orang, bukan disimpulkan dari rentangnya. */
        public readonly bool $satuanDipilih,
    ) {}

    public static function dariPermintaan(LaporanFilterRequest $request): self
    {
        /** @var User $user */
        $user = $request->user();

        // Kedua ujungnya dipaksa ke batas harinya. Saringan ini bersatuan
        // HARI — kotak tanggalnya mengirim `yyyy-mm-dd` — tapi `date()`
        // menghasilkan pukul 00:00, jadi tanpa `endOfDay()` seluruh hari
        // terakhir rentang jatuh di luar `whereBetween` di bawah. Lima dari
        // delapan pintasan berakhir hari ini, dan "Hari Ini" tidak pernah
        // bisa menampilkan apa pun. `startOfDay()` di sisi `from` menjaga
        // ujung yang sama tetap benar kalau yang masuk berisi jam.
        // (Tinjauan Maldini PR #27, dan API-56)
        $dari = $request->date('from')?->startOfDay() ?? now()->subDays(30)->startOfDay();
        $sampai = $request->date('to')?->endOfDay() ?? now()->endOfDay();

        // Satuan waktunya ditentukan rentang tanggalnya sendiri KECUALI
        // orangnya memilih lain. Pilihan yang tidak dikenali diperlakukan
        // sebagai tidak memilih — bukan galat: ini saringan tampilan, bukan
        // data yang disimpan. (API-62 nomor 2)
        $diminta = SatuanWaktu::dariNilai($request->input('satuan'));

        return new self(
            user: $user,
            dari: $dari,
            sampai: $sampai,
            outlet: $request->outletDiminta(),
            satuan: $diminta ?? SatuanWaktu::bawaanUntuk($dari, $sampai),
            satuanDipilih: $diminta !== null,
        );
    }

    /**
     * Rentang terpilih ditulis dengan kata: "11 Agustus 2026 – 10 September
     * 2026". Orang membuka laporan untuk menjawab "bagaimana bulan ini", dan
     * dua kotak `yyyy-mm-dd` tidak menjawab pertanyaan itu sampai dibaca
     * dua kali. (API-62 nomor 5)
     */
    public function rentangTerbaca(): string
    {
        return $this->dari->translatedFormat('j F Y').' – '.$this->sampai->translatedFormat('j F Y');
    }

    /**
     * Pintasan rentang tanggal. Tanggalnya dihitung di sini dan ditulis utuh
     * ke dalam tautannya, bukan disimpan sebagai kata kunci seperti
     * `?rentang=bulan_ini`: tautan yang menyebut tanggalnya sendiri tetap
     * berarti sama kalau disalin ke orang lain atau dibuka besok.
     *
     * "Semua" mencakup seluruh data YANG BOLEH DILIHAT pengguna ini — kasir
     * mendapat awal sejarah outletnya, bukan awal sejarah jaringan.
     *
     * @return list<array{nama:string,dari:string,sampai:string,aktif:bool}>
     */
    public function pintasan(): array
    {
        $hariIni = now()->startOfDay();

        $rentang = [
            'Hari Ini' => [$hariIni, $hariIni],
            'Kemarin' => [$hariIni->copy()->subDay(), $hariIni->copy()->subDay()],
            'Minggu Ini' => [$hariIni->copy()->startOfWeek(), $hariIni],
            '7 Hari Terakhir' => [$hariIni->copy()->subDays(6), $hariIni],
            'Minggu Lalu' => [
                $hariIni->copy()->subWeek()->startOfWeek(),
                $hariIni->copy()->subWeek()->endOfWeek()->startOfDay(),
            ],
            'Bulan Ini' => [$hariIni->copy()->startOfMonth(), $hariIni],
            'Bulan Lalu' => [
                $hariIni->copy()->subMonthNoOverflow()->startOfMonth(),
                $hariIni->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay(),
            ],
            'Semua' => [$this->awalData(), $hariIni],
        ];

        $pintasan = [];

        foreach ($rentang as $nama => [$dari, $sampai]) {
            $pintasan[] = [
                'nama' => $nama,
                'dari' => $dari->format('Y-m-d'),
                'sampai' => $sampai->format('Y-m-d'),
                'aktif' => $this->dari->format('Y-m-d') === $dari->format('Y-m-d')
                    && $this->sampai->format('Y-m-d') === $sampai->format('Y-m-d'),
            ];
        }

        return $pintasan;
    }

    /**
     * Tanggal complaint paling awal yang boleh dilihat pengguna ini, atau hari
     * ini kalau belum ada satu pun. Lewat `visibleTo()`: awal sejarah jaringan
     * bukan sesuatu yang boleh disimpulkan kasir dari sebuah tombol.
     */
    private function awalData(): Carbon
    {
        $awal = Complaint::query()->visibleTo($this->user)->min('created_at');

        return blank($awal) ? now()->startOfDay() : Carbon::parse((string) $awal)->startOfDay();
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
