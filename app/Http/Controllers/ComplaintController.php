<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComplaintRequest;
use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DaftarPetugas;
use App\Services\JejakComplaint;
use App\Services\KandidatPelaku;
use App\Services\PenutupanDiTempat;
use App\Services\PenyelarasNevira;
use App\Services\PenyimpanFoto;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ComplaintController extends Controller
{
    public function __construct(
        private PenyimpanFoto $foto,
        private JejakComplaint $jejak,
        private DaftarPetugas $petugas,
        private PenyelarasNevira $penyelaras,
        private PenutupanDiTempat $penutupan,
    ) {}

    /**
     * Saringan papan kerja yang boleh datang dari query string.
     *
     * Semuanya SKALAR. `?category[]=x` mengirim array, dan array yang lolos ke
     * `$request->string()` maupun ke `{{ }}` di Blade melempar — halaman
     * tersibuk di sistem berbalas HTTP 500 pada tautan yang disunting tangan,
     * bookmark yang rusak, atau crawler. Disaring sekali di satu tempat, bukan
     * ditambal per pemakainya. (Tinjauan PR #14 nomor 1 dan 4)
     *
     * `channel` ikut di sini: controller memang menyaringnya, jadi ia harus
     * ikut terbawa saat kotak Cari dipakai — kalau tidak, saringan kanal hilang
     * diam-diam begitu ada tautan tembus yang menghasilkannya.
     */
    private const SARINGAN = ['status', 'category', 'bobot', 'channel', 'outlet_id', 'layanan'];

    /** Papan kerja: complaint terbuka, disaring per peran. */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Complaint::query()
            ->visibleTo($user)
            ->with(['outlet', 'assignee']);

        $saringan = $this->saringan($request);
        $q = $this->skalar($request, 'q');

        // Pencarian eksplisit mencari di SELURUH data, termasuk tiket Close.
        // Sebelumnya scope open() tetap berlaku saat status tidak dipilih,
        // jadi mencari nomor tiket yang sudah ditutup selalu berbalas "tidak
        // ada complaint yang cocok" — halaman menyatakan tiketnya tidak ada
        // padahal ada. Mayoritas dari 545 baris impor berstatus Close, jadi
        // supervisor yang mencari kasus lama nyaris selalu mendapat nol.
        // (API-38 #1)
        $mencari = $q !== null;

        if (isset($saringan['status'])) {
            $query->where('status', $saringan['status'])->latest();
        } elseif ($mencari) {
            $query->latest();
        } else {
            // Papan kerja diurut menurut tenggat: yang paling mepet tampil dulu.
            // Complaint tanpa tenggat jatuh ke bawah, bukan ke atas.
            $query->open()
                ->orderByRaw('due_resolution_at is null')
                ->orderBy('due_resolution_at');
        }

        foreach ($saringan as $kolom => $nilai) {
            if ($kolom !== 'status') {
                $query->where($kolom, $nilai);
            }
        }

        if ($mencari) {
            $query->where(function ($sub) use ($q) {
                $sub->where('ticket_number', 'like', "%{$q}%")
                    ->orWhere('reporter_name', 'like', "%{$q}%")
                    ->orWhere('reporter_phone', 'like', "%{$q}%")
                    // Dicari lewat nomor nota, bukan id internal NEVIRA:
                    // kotak pencarian tidak boleh jadi alat memastikan
                    // tebakan id internal satu per satu. (API-8 T12)
                    ->orWhere('nevira_transaction_number', 'like', "%{$q}%");
            });
        }

        return view('complaints.index', [
            'complaints' => $query->paginate(20)->withQueryString(),
            'outlets' => Outlet::orderBy('name')->get(),
            // Dikirim dari sini, tidak dihitung ulang di view: yang memutuskan
            // "ini pencarian" adalah query yang dibangun di atas. Dua tempat
            // yang menghitungnya sendiri akan berpisah begitu aturannya
            // berubah — judul halaman menyebut sesuatu yang tidak sesuai
            // dengan baris yang benar-benar diambil. (Tinjauan PR #12)
            'mencari' => $mencari,
            // View membaca DARI SINI, tidak memanggil request() lagi: nilai
            // yang sudah disaring di satu tempat tidak boleh diambil ulang
            // mentah-mentah di tempat kedua.
            'saringan' => $saringan,
            'q' => $q,
        ]);
    }

    /**
     * Saringan yang benar-benar terpakai, sudah dipastikan skalar dan terisi.
     *
     * @return array<string,string>
     */
    private function saringan(Request $request): array
    {
        $terpakai = [];

        foreach (self::SARINGAN as $kunci) {
            $nilai = $this->skalar($request, $kunci);

            if ($nilai !== null) {
                $terpakai[$kunci] = $nilai;
            }
        }

        return $terpakai;
    }

    /**
     * Nilai skalar yang terisi, atau null.
     *
     * Array dianggap TIDAK ADA, bukan digabung jadi teks: `?category[]=a&category[]=b`
     * bukan permintaan yang punya arti di halaman ini, dan menebak artinya
     * lebih buruk daripada mengabaikannya. Halamannya tetap 200 dan tetap
     * menampilkan papan kerja apa adanya.
     */
    private function skalar(Request $request, string $kunci): ?string
    {
        $nilai = $request->input($kunci);

        if (! is_scalar($nilai)) {
            return null;
        }

        $nilai = trim((string) $nilai);

        return $nilai === '' ? null : $nilai;
    }

    public function create()
    {
        $this->authorize('create', Complaint::class);

        return view('complaints.create', [
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreComplaintRequest $request)
    {
        $user = $request->user();
        $data = $this->kunciKeWewenang($request->validated(), $user);

        // Kolom penyelesaian dipisahkan dari kolom model SEBELUM apa pun
        // disimpan. Tanpa pemisahan ini, permintaan yang dirakit tangan bisa
        // menitipkan resolusi dan kompensasi ke tiket biasa tanpa pernah
        // melewati wewenang penutupan. (API-26)
        [$data, $penanganan] = $this->pisahkanPenangananDiTempat($data, $request->boolean('tangani_di_tempat'));

        // Berkas ditulis SEBELUM transaksi, jadi nomor complaint-nya belum
        // ada dan berkasnya masuk ke folder intake. Itu disengaja: percobaan
        // ulang saat nomor tiket bentrok tidak boleh menulis unggahan yang
        // sama dua kali.
        //
        // Disk privat, bukan 'public': foto bukti berisi barang dan kadang
        // wajah pelanggan. Dikecilkan dan dibuang EXIF-nya lewat penyimpan
        // yang sama dengan foto catatan penanganan — foto pelanggan dari
        // ponsel membawa koordinat GPS di mana pun ia diunggah. (API-20)
        [$berkas] = $this->foto->simpanBanyak($request->file('attachments', []), 'complaints/intake');

        // Boleh-tidaknya menutup diputuskan DI SERVER dari peran dan bobotnya,
        // bukan dari apa yang dikirim peramban — statusnya sendiri tidak
        // pernah diterima sebagai masukan form. (API-26)
        $tutup = $penanganan === null
            ? ['boleh' => false, 'alasan' => null]
            : $this->penutupan->putuskan(
                $user,
                new Complaint($data),
                $penanganan['compensation_amount'],
            );

        $complaint = $this->simpanMeskiNomorBentrok(
            fn () => $this->simpanComplaint($data, $user, $berkas, $penanganan, $tutup['boleh'])
        );

        // Tarik data order NEVIRA kalau ID diisi. Kegagalan tidak boleh
        // membatalkan complaint — dicatat, bisa dicoba lagi nanti. (API-8)
        if (filled($complaint->nevira_transaction_number)) {
            $this->penyelaras->selaraskan($complaint, $user);
        }

        return $this->keHalamanComplaintBaru($complaint, $user, $tutup['alasan']);
    }

    /**
     * Pisahkan kolom penanganan-di-tempat dari kolom yang boleh mass-assign.
     *
     * Keempatnya hanya berlaku kalau centangnya benar-benar dipakai. Kalau
     * tidak, nilainya dibuang di sini — bukan diabaikan diam-diam di bawah.
     *
     * @param  array<string,mixed>  $data
     * @return array{0:array<string,mixed>,1:?array{resolution:?string,tindak_lanjut:?string,compensation_amount:int}}
     */
    private function pisahkanPenangananDiTempat(array $data, bool $diTempat): array
    {
        $penanganan = $diTempat ? [
            'resolution' => $data['resolution'] ?? null,
            'tindak_lanjut' => $data['tindak_lanjut'] ?? null,
            'compensation_amount' => (int) ($data['compensation_amount'] ?? 0),
        ] : null;

        unset(
            $data['tangani_di_tempat'],
            $data['resolution'],
            $data['tindak_lanjut'],
            $data['compensation_amount'],
        );

        return [$data, $penanganan];
    }

    /**
     * Kunci isian yang tidak boleh ditentukan pengirim form.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function kunciKeWewenang(array $data, User $user): array
    {
        // Nota terisi berarti tidak ada pengecualian yang berlaku.
        if (filled($data['nevira_transaction_number'] ?? null)) {
            $data['nota_exemption'] = null;
        } else {
            // Tanpa nota tidak ada baris layanan yang bisa ditunjuk. Nomor
            // yang tertinggal di form akan menunjuk barang di nota yang
            // tidak pernah tertaut. (API-51)
            $data['nevira_service_index'] = null;
        }

        // Kasir hanya boleh mencatat untuk outletnya sendiri.
        if ($user->isKasir()) {
            $data['outlet_id'] = $user->outlet_id;
        }

        return $data;
    }

    /**
     * Satu percobaan penyimpanan: complaint, jejaknya, dan lampirannya.
     *
     * Ketiganya jatuh bersama atau tidak sama sekali — complaint tanpa baris
     * riwayat tidak bisa ditelusuri asal-usulnya.
     *
     * @param  array<string,mixed>  $data
     * @param  array<int,array<string,mixed>>  $berkas
     */
    private function simpanComplaint(
        array $data,
        User $user,
        array $berkas,
        ?array $penanganan = null,
        bool $tutup = false,
    ): Complaint {
        return DB::transaction(function () use ($data, $user, $berkas, $penanganan, $tutup) {
            $complaint = new Complaint($data);
            $complaint->ticket_number = Complaint::nextTicketNumber();
            $complaint->status = 'open';
            $complaint->created_by = $user->id;
            $complaint->created_at = now();
            $complaint->applySla();
            $complaint->save();

            $this->jejak->dibuat($complaint, $user);

            if ($penanganan !== null) {
                $this->terapkanPenangananDiTempat($complaint, $user, $penanganan, $tutup);
            }

            foreach ($berkas as $b) {
                $complaint->attachments()->create($b);
            }

            return $complaint;
        });
    }

    /**
     * Satu kali simpan, DUA kejadian di riwayat: dibuat, lalu berpindah.
     *
     * Diringkas jadi satu baris "lahir sudah tertutup" akan menghemat sebaris
     * dan merusak laporan: waktu penyelesaian dihitung dari selisih stempel
     * waktu kedua kejadian itu. Tiket yang ditangani di tempat memang
     * berdurasi 0 hari — dan 0 hari adalah angka yang benar, bukan angka yang
     * hilang. Median penyelesaian complaint Ringan 2026 juga 0 hari.
     *
     * @param  array{resolution:?string,tindak_lanjut:?string,compensation_amount:int}  $penanganan
     */
    private function terapkanPenangananDiTempat(
        Complaint $complaint,
        User $user,
        array $penanganan,
        bool $tutup,
    ): void {
        $complaint->resolution = $penanganan['resolution'];
        $complaint->tindak_lanjut = $penanganan['tindak_lanjut'];
        $complaint->compensation_amount = $penanganan['compensation_amount'];

        // Keluhan yang sudah ditangani sudah pasti direspons. Tanpa ini SLA
        // respon pertama tampak terlewat pada tiket yang justru paling cepat.
        $complaint->first_response_at = now();

        $ke = $tutup ? 'close' : 'handling';
        $complaint->status = $ke;
        $complaint->close_reason = $tutup ? 'selesai' : null;
        $complaint->resolved_at = $tutup ? now() : null;
        $complaint->save();

        $this->jejak->statusBerubah($complaint, $user, 'open', $ke, $tutup
            ? 'Ditangani dan ditutup di tempat saat complaint dicatat.'
            : 'Ditangani di tempat, tapi penutupannya di luar wewenang pencatat — '
                .'menunggu yang berwenang menutup.');
    }

    private function keHalamanComplaintBaru(
        Complaint $complaint,
        User $user,
        ?string $alasanTidakDitutup = null,
    ): RedirectResponse {
        $redirect = redirect()
            ->route('complaints.show', $complaint)
            ->with('status', 'Complaint '.$complaint->ticket_number.' tercatat.')
            // Baru di sini draft di perangkat boleh dibuang: complaint ini
            // sudah punya nomor tiket. Draft yang dihapus saat form dikirim
            // ikut hilang justru ketika simpannya gagal.
            ->with('bersihkan_draft', true);

        // Kenapa tiketnya TIDAK tertutup padahal centangnya dipakai. Kuncinya
        // sendiri, bukan menumpang 'warning': peringatan nota kembar di bawah
        // bisa muncul pada complaint yang sama, dan yang belakangan menimpa
        // yang duluan — kasir kehilangan justru kalimat yang menjelaskan
        // kenapa pekerjaannya belum selesai. (API-26)
        if ($alasanTidakDitutup !== null) {
            $redirect->with('penutupan_ditolak', $alasanTidakDitutup);
        }

        // Peringatan, bukan larangan: satu nota boleh punya dua keluhan
        // berbeda. Yang tidak boleh adalah petugas tidak tahu. (API-8 T7)
        $kembaran = $complaint->kembaranNota($user);

        if ($kembaran->isNotEmpty()) {
            $redirect->with('warning', 'Nota '.$complaint->nevira_transaction_number
                .' sudah pernah dikeluhkan: '.$kembaran->pluck('ticket_number')->implode(', ')
                .'. Periksa dulu — kalau keluhannya sama, gabungkan supaya tidak dihitung dua kali.');
        }

        return $redirect;
    }

    /**
     * Simpan complaint, ambil nomor tiket lagi kalau keburu disambar.
     *
     * Dua kasir menekan Simpan pada detik yang sama: keduanya membaca nomor
     * berikutnya yang sama, yang kedua kena UNIQUE constraint di dalam
     * transaksi dan dulu berakhir HTTP 500 — complaint hilang, sementara
     * pelanggannya sudah telanjur ditutup teleponnya. Kehilangan data, bukan
     * sekadar galat.
     *
     * Transaksinya sudah rollback saat kita sampai ke sini, jadi mencoba
     * ulang aman: tidak ada baris separuh jadi yang tertinggal. Bentrok yang
     * tidak juga reda tetap dilempar ke atas — gagal yang terlihat lebih baik
     * daripada complaint yang diam-diam tidak tersimpan. (API-8 T5)
     */
    private function simpanMeskiNomorBentrok(callable $simpan, int $percobaan = 5): Complaint
    {
        for ($ke = 1; ; $ke++) {
            try {
                return $simpan();
            } catch (QueryException $e) {
                if ($ke >= $percobaan || ! $this->bentrokNomorTiket($e)) {
                    throw $e;
                }

                // Jeda acak singkat supaya dua permintaan yang bertabrakan
                // tidak mengulang bersamaan lagi.
                usleep(random_int(1_000, 5_000));
            }
        }
    }

    private function bentrokNomorTiket(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'ticket_number');
    }

    public function show(Complaint $complaint)
    {
        $this->authorize('view', $complaint);

        $user = Auth::user();

        $complaint->load(['outlet', 'assignee', 'creator', 'activities.user', 'activities.attachments', 'attachments', 'responsibles.setter']);

        // Daftar pegawai hanya untuk peran yang memang menetapkan
        // penanggung jawab. Sebelumnya setiap kasir menerima nama dan
        // peran seluruh pegawai perusahaan, termasuk outlet lain.
        // (API-14 #6)
        $penggunaSistem = $user->canAssignResponsibility() ? $this->petugas->penggunaSistem() : collect();

        return view('complaints.show', [
            'complaint' => $complaint,
            'kembaran' => $complaint->kembaranNota($user),
            'handlers' => $penggunaSistem,
            // Kandidat pelaku disusun server: orang dari nota ini, karyawan
            // outletnya, lalu pengguna sistem. Kasir tidak pernah menerimanya
            // — daftar nama karyawan bukan konsumsinya. (API-19)
            'kandidat' => $user->canAssignResponsibility()
                ? KandidatPelaku::untuk($complaint, $this->petugas->karyawanOutlet($user, $complaint), $penggunaSistem)
                : null,
        ]);
    }

    /**
     * Tentukan siapa yang menangani complaint ini, dan apakah diteruskan ke
     * divisi.
     *
     * Rute ini dulu hanya memeriksa canView, padahal baris audit yang
     * ditulisnya sendiri berbunyi "Penanggung jawab diperbarui" — kasir yang
     * dikeluhkan bisa memindahkan namanya ke rekannya, dan pengguna divisi
     * bisa melempar complaint ke divisi lain sampai lenyap dari antrean
     * semua orang. Wewenangnya sama dengan penetapan pelaku. (API-14 #3)
     */
    public function assign(Request $request, Complaint $complaint)
    {
        $this->authorize('assign', $complaint);

        $user = $request->user();

        $data = $request->validate([
            // Hanya ke akun aktif yang memang menangani complaint. Sebelumnya
            // exists:users,id saja, jadi penugasan bisa diarahkan ke akun
            // nonaktif atau ke pengguna divisi yang tidak pernah muncul di
            // dropdown — dan complaint itu tidak pernah tersentuh siapa pun.
            'assigned_to' => ['nullable', Rule::exists('users', 'id')
                ->where('is_active', true)
                ->whereIn('role', User::peranBisaDitugasi())],
            'forwarded_division' => ['nullable', Rule::in(array_keys(config('complaint.divisions')))],
        ], [], ['assigned_to' => 'penanggung jawab']);

        $complaint->fill($data)->save();

        $this->jejak->penugasan($complaint, $user, $data['forwarded_division'] ?? null);

        return back()->with('status', 'Penugasan diperbarui.');
    }
}
