<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComplaintRequest;
use App\Models\Complaint;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DaftarPetugas;
use App\Services\JejakComplaint;
use App\Services\KandidatPelaku;
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
    ) {}

    /** Papan kerja: complaint terbuka, disaring per peran. */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Complaint::query()
            ->visibleTo($user)
            ->with(['outlet', 'assignee']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'))->latest();
        } else {
            // Papan kerja diurut menurut tenggat: yang paling mepet tampil dulu.
            // Complaint tanpa tenggat jatuh ke bawah, bukan ke atas.
            $query->open()
                ->orderByRaw('due_resolution_at is null')
                ->orderBy('due_resolution_at');
        }

        foreach (['category', 'priority', 'channel', 'outlet_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('q')) {
            $q = $request->string('q');
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
        ]);
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

        $complaint = $this->simpanMeskiNomorBentrok(
            fn () => $this->simpanComplaint($data, $user, $berkas)
        );

        // Tarik data order NEVIRA kalau ID diisi. Kegagalan tidak boleh
        // membatalkan complaint — dicatat, bisa dicoba lagi nanti. (API-8)
        if (filled($complaint->nevira_transaction_number)) {
            $this->penyelaras->selaraskan($complaint, $user);
        }

        return $this->keHalamanComplaintBaru($complaint, $user);
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
    private function simpanComplaint(array $data, User $user, array $berkas): Complaint
    {
        return DB::transaction(function () use ($data, $user, $berkas) {
            $complaint = new Complaint($data);
            $complaint->ticket_number = Complaint::nextTicketNumber();
            $complaint->status = 'baru';
            $complaint->created_by = $user->id;
            $complaint->created_at = now();
            $complaint->applySla();
            $complaint->save();

            $this->jejak->dibuat($complaint, $user);

            foreach ($berkas as $b) {
                $complaint->attachments()->create($b);
            }

            return $complaint;
        });
    }

    private function keHalamanComplaintBaru(Complaint $complaint, User $user): RedirectResponse
    {
        $redirect = redirect()
            ->route('complaints.show', $complaint)
            ->with('status', 'Complaint '.$complaint->ticket_number.' tercatat.')
            // Baru di sini draft di perangkat boleh dibuang: complaint ini
            // sudah punya nomor tiket. Draft yang dihapus saat form dikirim
            // ikut hilang justru ketika simpannya gagal.
            ->with('bersihkan_draft', true);

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
