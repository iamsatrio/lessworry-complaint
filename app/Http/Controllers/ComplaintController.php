<?php

namespace App\Http\Controllers;

use App\Exceptions\NeviraAccessDenied;
use App\Exceptions\NeviraException;
use App\Models\Complaint;
use App\Models\ComplaintActivity;
use App\Models\ComplaintAttachment;
use App\Models\ComplaintResponsible;
use App\Models\Outlet;
use App\Models\User;
use App\Rules\GambarSungguhan;
use App\Services\KandidatPelaku;
use App\Services\NeviraGate;
use App\Services\PenyimpanFoto;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ComplaintController extends Controller
{
    /** Batas unggahan foto per catatan penanganan. (API-20) */
    public const FOTO_PER_CATATAN = 5;

    /** Foto kamera HP 3–8 MB; di atas ini hampir pasti bukan foto bukti. */
    public const FOTO_MAKS_KB = 8192;

    public function __construct(private NeviraGate $nevira, private PenyimpanFoto $foto) {}

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

    public function store(Request $request)
    {
        $this->authorize('create', Complaint::class);

        $user = $request->user();

        $data = $request->validate([
            'channel' => ['required', Rule::in(array_keys(config('complaint.channels')))],
            'reporter_name' => ['required', 'string', 'max:120'],
            'reporter_phone' => ['nullable', 'string', 'max:30'],
            'nevira_transaction_number' => ['required_without:nota_exemption', 'nullable', 'string', 'max:64'],
            'nota_exemption' => ['required_without:nevira_transaction_number', 'nullable', Rule::in(array_keys(config('complaint.nota_exemptions')))],
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'category' => ['required', Rule::in(array_keys(config('complaint.categories')))],
            'sub_category' => ['nullable', 'string', 'max:120'],
            'priority' => ['required', Rule::in(array_keys(config('complaint.priorities')))],
            // Batas panjang: tanpa ini 2 juta karakter tersimpan utuh dan
            // ikut termuat di papan kerja maupun halaman detail. (API-8 T8)
            'description' => ['required', 'string', 'max:5000'],
            'attachments.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', new GambarSungguhan],
        ], [
            'nevira_transaction_number.required_without' => 'Isi nomor nota NEVIRA, atau pilih alasan kenapa complaint ini tidak punya nota.',
            'nota_exemption.required_without' => 'Pilih alasan kenapa complaint ini tidak punya nomor nota.',
        ], [
            'nevira_transaction_number' => 'nomor nota',
            'nota_exemption' => 'alasan tanpa nota',
        ]);

        // Nota terisi berarti tidak ada pengecualian yang berlaku.
        if (filled($data['nevira_transaction_number'] ?? null)) {
            $data['nota_exemption'] = null;
        }

        // Kasir hanya boleh mencatat untuk outletnya sendiri.
        if ($user->isKasir()) {
            $data['outlet_id'] = $user->outlet_id;
        }

        // Berkas ditulis SEBELUM transaksi supaya percobaan ulang di bawah
        // tidak menulis unggahan yang sama dua kali. Disk privat, bukan
        // 'public': foto bukti berisi barang dan kadang wajah pelanggan; di
        // disk publik siapa pun yang memegang URL bisa membukanya tanpa login.
        // Dikecilkan dan dibuang EXIF-nya lewat penyimpan yang sama dengan
        // foto catatan penanganan: foto pelanggan dari ponsel membawa
        // koordinat GPS di mana pun ia diunggah. (API-20)
        //
        // Ditulis SEBELUM transaksi, jadi nomor complaint-nya belum ada dan
        // berkasnya masuk ke folder intake. Itu disengaja: percobaan ulang
        // saat nomor tiket bentrok tidak boleh menulis unggahan yang sama
        // dua kali.
        [$berkas, $gagalFoto] = $this->simpanFoto($request->file('attachments', []), 'complaints/intake');

        $complaint = $this->simpanMeskiNomorBentrok(function () use ($data, $user, $berkas) {
            return DB::transaction(function () use ($data, $user, $berkas) {
                $complaint = new Complaint($data);
                $complaint->ticket_number = Complaint::nextTicketNumber();
                $complaint->status = 'baru';
                $complaint->created_by = $user->id;
                $complaint->created_at = now();
                $complaint->applySla();
                $complaint->save();

                ComplaintActivity::create([
                    'complaint_id' => $complaint->id,
                    'user_id' => $user->id,
                    'type' => 'created',
                    'to_status' => 'baru',
                    'note' => 'Complaint dibuat lewat '.$complaint->channelLabel(),
                ]);

                foreach ($berkas as $b) {
                    $complaint->attachments()->create($b);
                }

                return $complaint;
            });
        });

        // Tarik data order NEVIRA kalau ID diisi. Kegagalan tidak boleh
        // membatalkan complaint — dicatat, bisa dicoba lagi nanti. (API-8)
        if (filled($complaint->nevira_transaction_number)) {
            $this->syncNevira($complaint, $user);
        }

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
        $penggunaSistem = $user->canAssignResponsibility() ? $this->penggunaSistem() : collect();

        return view('complaints.show', [
            'complaint' => $complaint,
            'kembaran' => $complaint->kembaranNota($user),
            'handlers' => $penggunaSistem,
            // Kandidat pelaku disusun server: orang dari nota ini, karyawan
            // outletnya, lalu pengguna sistem. Kasir tidak pernah menerimanya
            // — daftar nama karyawan bukan konsumsinya. (API-19)
            'kandidat' => $user->canAssignResponsibility()
                ? KandidatPelaku::untuk($complaint, $this->karyawanOutlet($user, $complaint), $penggunaSistem)
                : null,
        ]);
    }

    /** Pengguna sistem complaint yang bisa ditugasi atau ditetapkan sebagai pelaku. */
    private function penggunaSistem()
    {
        return User::where('is_active', true)
            ->whereIn('role', ['kasir', 'customer_care', 'supervisor'])
            ->orderBy('name')->get();
    }

    /**
     * Karyawan outlet nota ini, lewat NeviraGate.
     *
     * Outlet complaint sudah ditentukan otomatis dari nota, jadi daftarnya
     * langsung tersaring — petugas tidak perlu memilih outlet dulu.
     */
    private function karyawanOutlet(User $user, Complaint $complaint): array
    {
        try {
            return $this->nevira->outletStaff($user, $complaint->neviraOutletId());
        } catch (NeviraAccessDenied) {
            return [];
        }
    }

    /** Ubah status, dengan pencatatan riwayat dan penegakan wewenang. */
    public function updateStatus(Request $request, Complaint $complaint)
    {
        $this->authorize('updateStatus', $complaint);

        $user = $request->user();

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(config('complaint.statuses')))],
            // Versi yang dilihat petugas saat halaman dibuka. Tanpa ini,
            // penyimpanan dari halaman basi menimpa keputusan orang lain
            // tanpa peringatan ke siapa pun. (API-8 T6)
            'lock_version' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
            'resolution' => ['nullable', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:2000'],
            'compensation_amount' => ['nullable', 'integer', 'min:0'],
        ], [
            'lock_version.required' => 'Muat ulang halaman complaint ini sebelum menyimpan.',
        ], ['lock_version' => 'penanda versi']);

        if ((int) $data['lock_version'] !== (int) $complaint->lock_version) {
            // withInput() penting: petugas sudah mengetik resolusi panjang,
            // dan menghukumnya dengan mengosongkan form membuat pengaman ini
            // dibenci lalu diakali.
            return back()->withInput()->withErrors([
                'lock_version' => 'Complaint ini sudah diubah orang lain sejak halaman ini dibuka — '
                    .'sekarang berstatus '.$complaint->statusLabel().'. Muat ulang halamannya, '
                    .'baca perubahannya, lalu simpan lagi kalau masih perlu.',
            ]);
        }

        $closing = in_array($data['status'], ['selesai', 'ditolak'], true);

        if ($closing && ! $user->canResolve()) {
            return back()->withErrors(['status' => 'Peranmu tidak berwenang menutup complaint.']);
        }

        // Batas wewenang kompensasi berlaku dua arah. Batas atas sudah
        // dijaga; yang tidak adalah penurunan — kasir bisa memangkas angka
        // yang sudah disetujui supervisor jadi 1. Yang menentukan bukan arah
        // perubahannya, tapi apakah KEDUA nilai ada di dalam wewenangnya.
        // (API-14 #10)
        $sekarang = (int) $complaint->compensation_amount;
        $compensation = $request->has('compensation_amount')
            ? (int) ($data['compensation_amount'] ?? 0)
            : $sekarang;
        $batas = $user->compensationLimit();

        if ($compensation !== $sekarang && $compensation > $batas) {
            return back()->withErrors([
                'compensation_amount' => 'Nilai kompensasi melebihi batas wewenang '.$user->roleLabel()
                    .' (Rp '.number_format($batas, 0, ',', '.').'). Naikkan ke supervisor.',
            ]);
        }

        if ($compensation !== $sekarang && $sekarang > $batas) {
            return back()->withErrors([
                'compensation_amount' => 'Kompensasi Rp '.number_format($sekarang, 0, ',', '.')
                    .' disetujui di atas batas wewenang '.$user->roleLabel()
                    .'. Hanya yang berwenang di angka itu yang boleh mengubahnya.',
            ]);
        }

        $from = $complaint->status;

        DB::transaction(function () use ($complaint, $data, $user, $from, $closing, $compensation, $sekarang) {
            $complaint->status = $data['status'];
            $complaint->lock_version = (int) $complaint->lock_version + 1;
            $complaint->resolution = $data['resolution'] ?? $complaint->resolution;
            $complaint->root_cause = $data['root_cause'] ?? $complaint->root_cause;

            // Nilai yang sama artinya tidak ada perubahan; 0 yang dikirim
            // sengaja memang mengosongkan kompensasi.
            $complaint->compensation_amount = $compensation;

            if ($complaint->first_response_at === null) {
                $complaint->first_response_at = now();
            }

            if ($closing && $complaint->resolved_at === null) {
                $complaint->resolved_at = now();
            }

            if (! $closing) {
                $complaint->resolved_at = null;
            }

            $complaint->save();

            ComplaintActivity::create([
                'complaint_id' => $complaint->id,
                'user_id' => $user->id,
                'type' => 'status_change',
                'from_status' => $from,
                'to_status' => $complaint->status,
                'note' => $data['note'] ?? null,
            ]);

            // Uang yang berpindah harus punya jejak sendiri. Riwayat dulu
            // hanya mencatat perubahan status, jadi nilai kompensasi bisa
            // bergerak tanpa siapa pun bisa menelusurinya. (API-14 #10)
            if ($compensation !== $sekarang) {
                ComplaintActivity::create([
                    'complaint_id' => $complaint->id,
                    'user_id' => $user->id,
                    'type' => 'note',
                    'note' => 'Kompensasi diubah dari Rp '.number_format($sekarang, 0, ',', '.')
                        .' ke Rp '.number_format($compensation, 0, ',', '.').'.',
                ]);
            }
        });

        return back()->with('status', 'Status diperbarui: '.$complaint->statusLabel());
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
                ->whereIn('role', ['kasir', 'customer_care', 'supervisor'])],
            'forwarded_division' => ['nullable', Rule::in(array_keys(config('complaint.divisions')))],
        ], [], ['assigned_to' => 'penanggung jawab']);

        $complaint->fill($data)->save();

        ComplaintActivity::create([
            'complaint_id' => $complaint->id,
            'user_id' => $user->id,
            'type' => filled($data['forwarded_division'] ?? null) ? 'forward' : 'assign',
            'note' => filled($data['forwarded_division'] ?? null)
                ? 'Diteruskan ke divisi '.config('complaint.divisions.'.$data['forwarded_division'])
                : 'Penanggung jawab diperbarui',
        ]);

        return back()->with('status', 'Penugasan diperbarui.');
    }

    /**
     * Tambah catatan penanganan, dengan foto bukti kalau ada. (API-20)
     *
     * Foto sering justru yang menentukan: noda yang tersisa setelah cuci
     * ulang, kondisi barang saat diserahkan kembali. Berkasnya dikecilkan
     * dan dibersihkan dari EXIF sebelum disimpan — lihat PenyimpanFoto.
     */
    public function addNote(Request $request, Complaint $complaint)
    {
        $this->authorize('addNote', $complaint);

        $user = $request->user();

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'photos' => ['array', 'max:'.self::FOTO_PER_CATATAN],
            // Isi berkas yang menentukan, bukan ekstensinya: aturan image
            // membaca berkasnya, dan PenyimpanFoto memeriksanya sekali lagi.
            // HEIC sengaja tidak diterima — gd tidak bisa membacanya, jadi
            // ia akan tersimpan apa adanya berikut EXIF-nya.
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::FOTO_MAKS_KB, new GambarSungguhan],
        ], [
            'photos.max' => 'Maksimal '.self::FOTO_PER_CATATAN.' foto per catatan.',
            'photos.*.image' => 'Berkas :position bukan gambar. Unggah foto (JPG, PNG, atau WebP).',
            'photos.*.mimes' => 'Berkas :position bukan gambar. Unggah foto (JPG, PNG, atau WebP).',
            'photos.*.max' => 'Foto :position lebih dari '.(int) (self::FOTO_MAKS_KB / 1024).' MB.',
        ], ['photos' => 'foto']);

        // Berkas ditulis sebelum barisnya dibuat, dan kegagalannya tidak
        // menjatuhkan catatan: petugas sudah mengetik temuannya, dan
        // membuangnya karena satu foto gagal adalah kehilangan data.
        [$berkas, $gagal] = $this->simpanFoto($request->file('photos', []), 'complaints/'.$complaint->id);

        DB::transaction(function () use ($complaint, $data, $user, $berkas) {
            $activity = ComplaintActivity::create([
                'complaint_id' => $complaint->id,
                'user_id' => $user->id,
                'type' => 'note',
                'note' => $data['note'],
            ]);

            foreach ($berkas as $b) {
                $complaint->attachments()->create($b + ['complaint_activity_id' => $activity->id]);
            }
        });

        if ($complaint->first_response_at === null) {
            $complaint->forceFill(['first_response_at' => now()])->save();
        }

        $pesan = 'Catatan ditambahkan.';

        if ($gagal > 0) {
            return back()->with('status', $pesan)
                ->with('warning', $gagal.' foto gagal disimpan. Catatannya tetap tersimpan — coba unggah ulang fotonya.');
        }

        return back()->with('status', $pesan);
    }

    /**
     * Simpan sekumpulan foto, kembalikan atribut lampiran dan berapa yang gagal.
     *
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    private function simpanFoto(array $files, string $dir): array
    {
        $berkas = [];
        $gagal = 0;

        foreach (array_filter($files) as $file) {
            try {
                $berkas[] = $this->foto->simpan($file, $dir);
            } catch (\Throwable $e) {
                // Foto yang tidak bisa disimpan sama sekali dilewati, bukan
                // diam-diam: pemanggilnya memberi tahu petugas.
                report($e);
                $gagal++;
            }
        }

        return [$berkas, $gagal];
    }

    /**
     * Pasang atau perbaiki tautan ke order NEVIRA setelah complaint tersimpan.
     *
     * Form intake menjanjikan complaint boleh disimpan tanpa nomor order dan
     * ditautkan menyusul — janji itu perlu ada tempatnya. Ini juga jalan untuk
     * membetulkan nomor yang salah ketik.
     *
     * Isi complaint tidak pernah diubah diam-diam: setiap perubahan tautan
     * tercatat di riwayat beserta nomor lama dan barunya.
     */
    public function updateLink(Request $request, Complaint $complaint)
    {
        // Menautkan berarti menarik data pelanggan dari NEVIRA. Peran yang
        // tidak mencatat complaint tidak berkepentingan dengan itu.
        $this->authorize('link', $complaint);

        $user = $request->user();

        $data = $request->validate([
            'nevira_transaction_number' => ['nullable', 'string', 'max:64'],
            'nota_exemption' => ['nullable', Rule::in(array_keys(config('complaint.nota_exemptions')))],
        ], [], ['nevira_transaction_number' => 'nomor nota']);

        $new = trim((string) ($data['nevira_transaction_number'] ?? ''));
        $old = (string) $complaint->nevira_transaction_number;

        if ($new === $old) {
            return back()->with('status', 'Nomor order tidak berubah.');
        }

        $complaint->forceFill([
            'nevira_transaction_number' => $new !== '' ? $new : null,
            'nevira_transaction_id' => null,
            'nota_exemption' => $new !== '' ? null : ($data['nota_exemption'] ?? $complaint->nota_exemption),
            // Snapshot lama milik order lain — buang, jangan sampai tertinggal
            // dan menampilkan data order yang bukan miliknya.
            'nevira_snapshot' => null,
            'nevira_customer_id' => null,
            'nevira_synced_at' => null,
            'nevira_sync_error' => null,
        ])->save();

        ComplaintActivity::create([
            'complaint_id' => $complaint->id,
            'user_id' => $user->id,
            'type' => 'note',
            'note' => $old === ''
                ? 'Ditautkan ke order NEVIRA '.$new
                : ($new === ''
                    ? 'Tautan ke order NEVIRA '.$old.' dilepas'
                    : 'Tautan order NEVIRA diubah dari '.$old.' ke '.$new),
        ]);

        if ($new !== '') {
            $this->syncNevira($complaint, $user);

            return back()->with('status', $complaint->fresh()->nevira_sync_error
                ? 'Nomor order disimpan, tapi datanya belum bisa ditarik: '.$complaint->fresh()->nevira_sync_error
                : 'Complaint tertaut ke order '.$new.'.');
        }

        return back()->with('status', 'Tautan order dilepas.');
    }

    /**
     * Tetapkan satu atau beberapa orang sebagai pelaku complaint ini. (API-19)
     *
     * Sistem TIDAK menyimpulkan ini sendiri. NEVIRA hanya memberi tahu siapa
     * mengerjakan tahap apa; menautkan keluhan ke orang adalah penilaian, dan
     * penilaian harus punya nama pembuatnya serta alasannya.
     *
     * Yang dikirim browser hanya KUNCI kandidat — nama, NIP, dan id NEVIRA
     * dibaca server dari daftarnya sendiri. Itu yang membuat pengisian cukup
     * satu centang plus satu alasan, dan sekaligus menutup jalan menyuntikkan
     * nama karyawan sembarangan lewat form.
     */
    public function addResponsible(Request $request, Complaint $complaint)
    {
        $this->authorize('manageResponsible', $complaint);

        $user = $request->user();

        $peranSah = array_keys(config('complaint.responsible_roles'));

        $data = $request->validate([
            'pelaku' => ['required_without:manual_nama', 'array'],
            'pelaku.*' => ['string', 'max:190'],
            'peran' => ['array'],
            'peran.*' => [Rule::in($peranSah)],
            'manual_nama' => ['nullable', 'string', 'max:120'],
            'manual_nip' => ['nullable', 'string', 'max:40'],
            'manual_peran' => ['nullable', Rule::in($peranSah)],
            // Alasan wajib, tanpa pengecualian. Menunjuk orang tanpa alasan
            // tidak bisa ditinjau ulang dan menempel di catatan kerjanya.
            'alasan' => ['required', 'string', 'max:2000'],
        ], [
            'pelaku.required_without' => 'Pilih siapa yang terlibat, atau tulis namanya di isian bebas.',
            'alasan.required' => 'Tulis alasannya. Menunjuk orang tanpa alasan tidak bisa ditinjau ulang.',
        ], [
            'pelaku' => 'pelaku',
            'alasan' => 'alasan',
            'manual_nama' => 'nama karyawan',
        ]);

        $daftar = KandidatPelaku::untuk(
            $complaint,
            $this->karyawanOutlet($user, $complaint),
            $this->penggunaSistem()
        );

        $dipilih = [];

        foreach ($data['pelaku'] ?? [] as $kunci) {
            $kandidat = $daftar->find($kunci);

            if (! $kandidat) {
                // Daftar kandidat disusun ulang tiap permintaan; kalau NEVIRA
                // sedang mati, karyawan outlet tidak ada di dalamnya. Ditolak
                // dengan terang, bukan diam-diam dilewati — pelaku yang
                // dicentang lalu tidak tersimpan adalah kehilangan data.
                return back()->withInput()->withErrors([
                    'pelaku' => 'Ada pilihan yang tidak lagi dikenali — daftarnya mungkin berubah. '
                        .'Muat ulang halaman ini, lalu pilih lagi.',
                ]);
            }

            $dipilih[] = [
                'staff_id' => $kandidat['staff_id'],
                'name' => $kandidat['name'],
                'nip' => $kandidat['nip'],
                'role' => $data['peran'][$kunci] ?? $kandidat['role'],
                'stage' => $kandidat['stage'],
            ];
        }

        // Isian bebas tetap ada untuk orang yang tidak ada di daftar
        // (mis. kurir outlet lain) — tapi bukan jalur utamanya.
        if (filled($data['manual_nama'] ?? null)) {
            $dipilih[] = [
                'staff_id' => null,
                'name' => trim($data['manual_nama']),
                'nip' => $data['manual_nip'] ?? null,
                'role' => $data['manual_peran'] ?? 'lainnya',
                'stage' => null,
            ];
        }

        $sudahAda = $complaint->responsibles()->get()->map->identity()->all();
        $baru = [];

        foreach ($dipilih as $calon) {
            $identity = ComplaintResponsible::identityFor($calon['staff_id'], $calon['nip'], $calon['name']);

            if (in_array($identity, $sudahAda, true)) {
                continue;
            }

            $sudahAda[] = $identity;
            $baru[] = $calon;
        }

        if ($baru === []) {
            return back()->with('status', 'Semua yang dipilih sudah tercatat sebagai pelaku complaint ini.');
        }

        // Penetapan dan jejaknya harus jatuh bersama. Penetapan yang tersimpan
        // tanpa catatan riwayat adalah tuduhan tanpa asal-usul.
        DB::transaction(function () use ($complaint, $baru, $user, $data) {
            foreach ($baru as $calon) {
                $complaint->responsibles()->create([
                    'nevira_user_id' => $calon['staff_id'],
                    'staff_name' => $calon['name'],
                    'staff_nip' => $calon['nip'],
                    'role' => $calon['role'],
                    'stage' => $calon['stage'],
                    'reason' => $data['alasan'],
                    'set_by' => $user->id,
                    'set_at' => now(),
                ]);
            }

            ComplaintActivity::create([
                'complaint_id' => $complaint->id,
                'user_id' => $user->id,
                // Jenis tersendiri, bukan 'note': isinya nama karyawan, dan
                // riwayat complaint dibaca juga oleh kasir. Yang boleh
                // melihatnya disaring di tampilan. (API-19)
                'type' => 'responsible',
                'note' => 'Pelaku ditetapkan: '.$this->sebutPelaku($baru).'. Alasan: '.$data['alasan'],
            ]);
        });

        return back()->with('status', count($baru).' pelaku ditetapkan.');
    }

    /** Ubah peran atau alasan seorang pelaku. Perubahan ikut ke riwayat. */
    public function updateResponsible(Request $request, Complaint $complaint, ComplaintResponsible $responsible)
    {
        $this->authorize('manageResponsible', $complaint);
        // 404, bukan 403: ini keutuhan rute, bukan wewenang.
        abort_unless($responsible->complaint_id === $complaint->id, 404);

        $user = $request->user();

        $data = $request->validate([
            'peran' => ['required', Rule::in(array_keys(config('complaint.responsible_roles')))],
            'alasan' => ['required', 'string', 'max:2000'],
        ], [
            'alasan.required' => 'Tulis alasannya. Menunjuk orang tanpa alasan tidak bisa ditinjau ulang.',
        ], ['peran' => 'peran', 'alasan' => 'alasan']);

        DB::transaction(function () use ($complaint, $responsible, $data, $user) {
            $responsible->forceFill([
                'role' => $data['peran'],
                'reason' => $data['alasan'],
                'set_by' => $user->id,
                'set_at' => now(),
            ])->save();

            ComplaintActivity::create([
                'complaint_id' => $complaint->id,
                'user_id' => $user->id,
                'type' => 'responsible',
                'note' => 'Penetapan pelaku '.$responsible->staff_name.' diperbarui ('
                    .$responsible->roleLabel().'). Alasan: '.$data['alasan'],
            ]);
        });

        return back()->with('status', 'Penetapan '.$responsible->staff_name.' diperbarui.');
    }

    /** Cabut penetapan seorang pelaku. Pencabutan juga masuk riwayat. */
    public function destroyResponsible(Request $request, Complaint $complaint, ComplaintResponsible $responsible)
    {
        $this->authorize('manageResponsible', $complaint);
        // 404, bukan 403: ini keutuhan rute, bukan wewenang.
        abort_unless($responsible->complaint_id === $complaint->id, 404);

        $user = $request->user();

        $nama = $responsible->staff_name;

        DB::transaction(function () use ($complaint, $responsible, $user, $nama) {
            $responsible->delete();

            ComplaintActivity::create([
                'complaint_id' => $complaint->id,
                'user_id' => $user->id,
                'type' => 'responsible',
                'note' => 'Penetapan pelaku ('.$nama.') dicabut.',
            ]);
        });

        return back()->with('status', 'Penetapan '.$nama.' dicabut.');
    }

    private function sebutPelaku(array $pelaku): string
    {
        return collect($pelaku)->map(function ($p) {
            $peran = config('complaint.responsible_roles.'.$p['role'], $p['role']);

            return $p['name'].' ('.$peran.($p['stage'] ? ' · '.$p['stage'] : '').')';
        })->implode(', ');
    }

    /**
     * Sajikan foto bukti lewat pemeriksaan wewenang.
     *
     * Sebelumnya berkas ini duduk di disk publik: siapa pun yang memegang
     * URL-nya bisa membuka foto barang pelanggan tanpa login sama sekali.
     */
    public function attachment(Request $request, Complaint $complaint, ComplaintAttachment $attachment)
    {
        $this->authorize('viewAttachment', $complaint);
        abort_unless($attachment->complaint_id === $complaint->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, [
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    /**
     * Versi kecil untuk lini masa. (API-20)
     *
     * Wewenangnya diperiksa persis sama dengan berkas penuh — kalau tidak,
     * versi kecil jadi jalan memutar untuk melihat foto yang sama.
     */
    public function attachmentThumb(Request $request, Complaint $complaint, ComplaintAttachment $attachment)
    {
        $this->authorize('viewAttachment', $complaint);
        abort_unless($attachment->complaint_id === $complaint->id, 404);

        // Foto yang kompresinya gagal tidak punya versi kecil; yang disajikan
        // berkas penuhnya, bukan 404 yang membuat lini masa terlihat rusak.
        $path = $attachment->thumb_path ?: $attachment->path;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $attachment->original_name, [
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    /** Coba tautkan ulang ke NEVIRA (dipakai saat sinkron pertama gagal). */
    public function resync(Complaint $complaint)
    {
        $this->authorize('link', $complaint);

        $this->syncNevira($complaint, Auth::user());

        return back()->with('status', $complaint->nevira_sync_error
            ? 'Sinkron NEVIRA gagal: '.$complaint->nevira_sync_error
            : 'Data NEVIRA berhasil ditarik.');
    }

    /**
     * Isi identitas pelapor dari pelanggan pada nota — hanya kolom yang
     * masih kosong. Yang sudah diketik petugas tidak pernah ditimpa:
     * pelapor bisa saja bukan pemilik order, misalnya yang mengantarkan.
     */
    /**
     * Tentukan outlet complaint dari outlet pada nota.
     *
     * Kasir tetap terkunci ke outletnya sendiri (diputuskan di store), jadi
     * ini hanya mengisi yang belum ditentukan — biasanya complaint dari
     * Customer Care, yang tidak tahu outlet mana sebelum notanya dicek.
     */
    private function fillOutletFromOrder(Complaint $complaint, array $summary): void
    {
        if (filled($complaint->outlet_id)) {
            return;
        }

        $idNevira = $summary['outlet_id'] ?? null;

        if (blank($idNevira)) {
            return;
        }

        $outlet = Outlet::where('nevira_outlet_id', (string) $idNevira)->first();

        if ($outlet) {
            $complaint->forceFill(['outlet_id' => $outlet->id])->save();
        }
    }

    private function fillReporterFromOrder(Complaint $complaint, array $summary): void
    {
        $isi = [];

        if (blank($complaint->reporter_name) && filled($summary['customer_name'] ?? null)) {
            $isi['reporter_name'] = $summary['customer_name'];
        }

        if (blank($complaint->reporter_phone) && filled($summary['customer_phone'] ?? null)) {
            $isi['reporter_phone'] = $summary['customer_phone'];
        }

        if ($isi) {
            $complaint->forceFill($isi)->save();
        }
    }

    /**
     * Tarik data order NEVIRA lewat NeviraGate.
     *
     * Semua pemeriksaan akses ada di gate, bukan di sini — itu inti
     * perbaikan T1. Penolakan yang bersifat wewenang dilempar ke atas
     * (403); sisanya dicatat sebagai kegagalan sinkron supaya complaint
     * tetap hidup walau NEVIRA menolak atau mati. (API-8, API-10)
     */
    private function syncNevira(Complaint $complaint, User $user): void
    {
        try {
            $resolved = $this->nevira->resolve($user, $complaint->nevira_transaction_number);
            $summary = $resolved['summary'];

            // Perjalanan kurir ditarik terpisah: detail transaksi tidak
            // membawa nama kurirnya. Gagal di sini tidak membatalkan sinkron
            // order — data kurir sifatnya pelengkap.
            if (filled($summary['invoice'])) {
                $summary['deliveries'] = $this->nevira->deliveries($summary['invoice']);
            }

            $complaint->forceFill([
                // Id internal disimpan untuk panggilan API berikutnya, dan
                // tidak pernah dirender ke halaman mana pun.
                'nevira_transaction_id' => $resolved['id'],
                'nevira_transaction_number' => $resolved['number'] ?? $complaint->nevira_transaction_number,
                'nevira_snapshot' => $summary,
                'nevira_customer_id' => $summary['customer_id'] ?? null,
                'nevira_synced_at' => now(),
                'nevira_sync_error' => null,
            ])->save();

            $this->fillReporterFromOrder($complaint, $summary);
            $this->fillOutletFromOrder($complaint, $summary);
        } catch (NeviraAccessDenied $e) {
            abort(403);
        } catch (NeviraException $e) {
            $complaint->forceFill([
                'nevira_sync_error' => mb_substr($e->userMessage(), 0, 190),
            ])->save();
        }
    }
}
