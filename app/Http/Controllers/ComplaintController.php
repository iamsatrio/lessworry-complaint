<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\ComplaintActivity;
use App\Models\ComplaintAttachment;
use App\Models\Outlet;
use App\Models\User;
use App\Exceptions\NeviraAccessDenied;
use App\Exceptions\NeviraException;
use App\Services\NeviraGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ComplaintController extends Controller
{
    public function __construct(private NeviraGate $nevira) {}

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
            'outlets'    => Outlet::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        abort_unless(Auth::user()->canCreateComplaint(), 403);

        return view('complaints.create', [
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canCreateComplaint(), 403);

        $data = $request->validate([
            'channel'               => ['required', Rule::in(array_keys(config('complaint.channels')))],
            'reporter_name'         => ['required', 'string', 'max:120'],
            'reporter_phone'        => ['nullable', 'string', 'max:30'],
            'nevira_transaction_number' => ['required_without:nota_exemption', 'nullable', 'string', 'max:64'],
            'nota_exemption'            => ['required_without:nevira_transaction_number', 'nullable', Rule::in(array_keys(config('complaint.nota_exemptions')))],
            'outlet_id'             => ['nullable', 'exists:outlets,id'],
            'category'              => ['required', Rule::in(array_keys(config('complaint.categories')))],
            'sub_category'          => ['nullable', 'string', 'max:120'],
            'priority'              => ['required', Rule::in(array_keys(config('complaint.priorities')))],
            'description'           => ['required', 'string'],
            'attachments.*'         => ['nullable', 'image', 'max:5120'],
        ], [
            'nevira_transaction_number.required_without' => 'Isi nomor nota NEVIRA, atau pilih alasan kenapa complaint ini tidak punya nota.',
            'nota_exemption.required_without'            => 'Pilih alasan kenapa complaint ini tidak punya nomor nota.',
        ], [
            'nevira_transaction_number' => 'nomor nota',
            'nota_exemption'            => 'alasan tanpa nota',
        ]);

        // Nota terisi berarti tidak ada pengecualian yang berlaku.
        if (filled($data['nevira_transaction_number'] ?? null)) {
            $data['nota_exemption'] = null;
        }

        // Kasir hanya boleh mencatat untuk outletnya sendiri.
        if ($user->isKasir()) {
            $data['outlet_id'] = $user->outlet_id;
        }

        $complaint = DB::transaction(function () use ($data, $user, $request) {
            $complaint = new Complaint($data);
            $complaint->ticket_number = Complaint::nextTicketNumber();
            $complaint->status = 'baru';
            $complaint->created_by = $user->id;
            $complaint->created_at = now();
            $complaint->applySla();
            $complaint->save();

            ComplaintActivity::create([
                'complaint_id' => $complaint->id,
                'user_id'      => $user->id,
                'type'         => 'created',
                'to_status'    => 'baru',
                'note'         => 'Complaint dibuat lewat '.$complaint->channelLabel(),
            ]);

            foreach ($request->file('attachments', []) as $file) {
                // Disk privat, bukan 'public'. Foto bukti berisi barang dan
                // kadang wajah pelanggan; di disk publik siapa pun yang
                // memegang URL bisa membukanya tanpa login.
                $path = $file->store('complaints/'.$complaint->id, 'local');
                $complaint->attachments()->create([
                    'path'          => $path,
                    'original_name' => $file->getClientOriginalName(),
                ]);
            }

            return $complaint;
        });

        // Tarik data order NEVIRA kalau ID diisi. Kegagalan tidak boleh
        // membatalkan complaint — dicatat, bisa dicoba lagi nanti. (API-8)
        if (filled($complaint->nevira_transaction_number)) {
            $this->syncNevira($complaint, $user);
        }

        return redirect()
            ->route('complaints.show', $complaint)
            ->with('status', 'Complaint '.$complaint->ticket_number.' tercatat.');
    }

    public function show(Complaint $complaint)
    {
        abort_unless(Auth::user()->canView($complaint), 403);

        $complaint->load(['outlet', 'assignee', 'creator', 'activities.user', 'attachments']);

        return view('complaints.show', [
            'complaint' => $complaint,
            // Daftar pegawai hanya untuk peran yang memang menetapkan
            // penanggung jawab. Sebelumnya setiap kasir menerima nama dan
            // peran seluruh pegawai perusahaan, termasuk outlet lain.
            // (API-14 #6)
            'handlers'  => Auth::user()->canAssignResponsibility()
                ? User::where('is_active', true)
                    ->whereIn('role', ['kasir', 'customer_care', 'supervisor'])
                    ->orderBy('name')->get()
                : collect(),
        ]);
    }

    /** Ubah status, dengan pencatatan riwayat dan penegakan wewenang. */
    public function updateStatus(Request $request, Complaint $complaint)
    {
        $user = $request->user();
        abort_unless($user->canView($complaint), 403);

        $data = $request->validate([
            'status'              => ['required', Rule::in(array_keys(config('complaint.statuses')))],
            'note'                => ['nullable', 'string'],
            'resolution'          => ['nullable', 'string'],
            'root_cause'          => ['nullable', 'string'],
            'compensation_amount' => ['nullable', 'integer', 'min:0'],
        ]);

        $closing = in_array($data['status'], ['selesai', 'ditolak'], true);

        if ($closing && ! $user->canResolve()) {
            return back()->withErrors(['status' => 'Peranmu tidak berwenang menutup complaint.']);
        }

        $compensation = (int) ($data['compensation_amount'] ?? 0);
        if ($compensation > $user->compensationLimit()) {
            return back()->withErrors([
                'compensation_amount' => 'Nilai kompensasi melebihi batas wewenang '.$user->roleLabel()
                    .' (Rp '.number_format($user->compensationLimit(), 0, ',', '.').'). Naikkan ke supervisor.',
            ]);
        }

        $from = $complaint->status;

        DB::transaction(function () use ($complaint, $data, $user, $from, $closing, $compensation) {
            $complaint->status = $data['status'];
            $complaint->resolution = $data['resolution'] ?? $complaint->resolution;
            $complaint->root_cause = $data['root_cause'] ?? $complaint->root_cause;

            if ($compensation > 0) {
                $complaint->compensation_amount = $compensation;
            }

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
                'user_id'      => $user->id,
                'type'         => 'status_change',
                'from_status'  => $from,
                'to_status'    => $complaint->status,
                'note'         => $data['note'] ?? null,
            ]);
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
     * semua orang. Wewenangnya sama dengan /responsibility. (API-14 #3)
     */
    public function assign(Request $request, Complaint $complaint)
    {
        $user = $request->user();
        abort_unless($user->canView($complaint), 403);
        abort_unless($user->canAssignResponsibility(), 403);

        $data = $request->validate([
            // Hanya ke akun aktif yang memang menangani complaint. Sebelumnya
            // exists:users,id saja, jadi penugasan bisa diarahkan ke akun
            // nonaktif atau ke pengguna divisi yang tidak pernah muncul di
            // dropdown — dan complaint itu tidak pernah tersentuh siapa pun.
            'assigned_to'        => ['nullable', Rule::exists('users', 'id')
                ->where('is_active', true)
                ->whereIn('role', ['kasir', 'customer_care', 'supervisor'])],
            'forwarded_division' => ['nullable', Rule::in(array_keys(config('complaint.divisions')))],
        ], [], ['assigned_to' => 'penanggung jawab']);

        $complaint->fill($data)->save();

        ComplaintActivity::create([
            'complaint_id' => $complaint->id,
            'user_id'      => $user->id,
            'type'         => filled($data['forwarded_division'] ?? null) ? 'forward' : 'assign',
            'note'         => filled($data['forwarded_division'] ?? null)
                ? 'Diteruskan ke divisi '.config('complaint.divisions.'.$data['forwarded_division'])
                : 'Penanggung jawab diperbarui',
        ]);

        return back()->with('status', 'Penugasan diperbarui.');
    }

    public function addNote(Request $request, Complaint $complaint)
    {
        abort_unless($request->user()->canView($complaint), 403);

        $data = $request->validate(['note' => ['required', 'string']]);

        ComplaintActivity::create([
            'complaint_id' => $complaint->id,
            'user_id'      => $request->user()->id,
            'type'         => 'note',
            'note'         => $data['note'],
        ]);

        if ($complaint->first_response_at === null) {
            $complaint->forceFill(['first_response_at' => now()])->save();
        }

        return back()->with('status', 'Catatan ditambahkan.');
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
        $user = $request->user();
        abort_unless($user->canView($complaint), 403);
        // Menautkan berarti menarik data pelanggan dari NEVIRA. Peran yang
        // tidak mencatat complaint tidak berkepentingan dengan itu.
        abort_unless($user->canCreateComplaint(), 403);

        $data = $request->validate([
            'nevira_transaction_number' => ['nullable', 'string', 'max:64'],
            'nota_exemption'            => ['nullable', Rule::in(array_keys(config('complaint.nota_exemptions')))],
        ], [], ['nevira_transaction_number' => 'nomor nota']);

        $new = trim((string) ($data['nevira_transaction_number'] ?? ''));
        $old = (string) $complaint->nevira_transaction_number;

        if ($new === $old) {
            return back()->with('status', 'Nomor order tidak berubah.');
        }

        $complaint->forceFill([
            'nevira_transaction_number' => $new !== '' ? $new : null,
            'nevira_transaction_id'     => null,
            'nota_exemption'            => $new !== '' ? null : ($data['nota_exemption'] ?? $complaint->nota_exemption),
            // Snapshot lama milik order lain — buang, jangan sampai tertinggal
            // dan menampilkan data order yang bukan miliknya.
            'nevira_snapshot'    => null,
            'nevira_customer_id' => null,
            'nevira_synced_at'   => null,
            'nevira_sync_error'  => null,
        ])->save();

        ComplaintActivity::create([
            'complaint_id' => $complaint->id,
            'user_id'      => $user->id,
            'type'         => 'note',
            'note'         => $old === ''
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
     * Tetapkan siapa yang bertanggung jawab atas akar masalah complaint.
     *
     * Sistem TIDAK menyimpulkan ini sendiri. NEVIRA hanya memberi tahu siapa
     * mengerjakan tahap apa; menautkan keluhan ke satu orang adalah penilaian,
     * dan penilaian harus punya nama pembuatnya.
     *
     * Karena itu setiap penetapan menyimpan siapa yang menetapkan, kapan, dan
     * alasannya — lalu ikut tercatat di riwayat complaint.
     */
    public function setResponsibility(Request $request, Complaint $complaint)
    {
        $user = $request->user();
        abort_unless($user->canView($complaint), 403);
        abort_unless($user->canAssignResponsibility(), 403);

        $data = $request->validate([
            'responsible_staff_name' => ['nullable', 'string', 'max:120'],
            'responsible_staff_nip'  => ['nullable', 'string', 'max:40'],
            'responsible_staff_id'   => ['nullable', 'integer'],
            'responsible_stage'      => ['nullable', 'string', 'max:80'],
            'responsibility_note'    => ['required_with:responsible_staff_name', 'nullable', 'string'],
        ], [
            'responsibility_note.required_with' => 'Tulis alasannya. Menunjuk orang tanpa alasan tidak bisa ditinjau ulang.',
        ], [
            'responsible_staff_name' => 'nama karyawan',
            'responsibility_note'    => 'alasan',
        ]);

        $name = trim((string) ($data['responsible_staff_name'] ?? ''));
        $previous = $complaint->responsible_staff_name;

        if ($name === '') {
            DB::transaction(function () use ($complaint, $user, $previous) {
                $complaint->forceFill([
                    'responsible_staff_id'   => null,
                    'responsible_staff_name' => null,
                    'responsible_staff_nip'  => null,
                    'responsible_stage'      => null,
                    'responsibility_note'    => null,
                    'responsibility_set_by'  => null,
                    'responsibility_set_at'  => null,
                ])->save();

                if ($previous) {
                    ComplaintActivity::create([
                        'complaint_id' => $complaint->id,
                        'user_id'      => $user->id,
                        'type'         => 'note',
                        'note'         => 'Penetapan penanggung jawab ('.$previous.') dicabut.',
                    ]);
                }
            });

            return back()->with('status', 'Penetapan penanggung jawab dicabut.');
        }

        $stage  = $data['responsible_stage'] ?? null;
        $reason = $data['responsibility_note'] ?? null;

        // Penetapan dan jejaknya harus jatuh bersama. Penetapan yang tersimpan
        // tanpa catatan riwayat adalah tuduhan tanpa asal-usul.
        DB::transaction(function () use ($complaint, $data, $user, $name, $previous, $stage, $reason) {
            $complaint->forceFill([
                'responsible_staff_id'   => $data['responsible_staff_id'] ?? null,
                'responsible_staff_name' => $name,
                'responsible_staff_nip'  => $data['responsible_staff_nip'] ?? null,
                'responsible_stage'      => $stage,
                'responsibility_note'    => $reason,
                'responsibility_set_by'  => $user->id,
                'responsibility_set_at'  => now(),
            ])->save();

            ComplaintActivity::create([
                'complaint_id' => $complaint->id,
                'user_id'      => $user->id,
                'type'         => 'note',
                'note'         => ($previous && $previous !== $name)
                    ? 'Penanggung jawab diubah dari '.$previous.' ke '.$name.'. Alasan: '.$reason
                    : 'Penanggung jawab ditetapkan: '.$name
                        .($stage ? ' (tahap '.$stage.')' : '')
                        .'. Alasan: '.$reason,
            ]);
        });

        return back()->with('status', 'Penanggung jawab ditetapkan: '.$name.'.');
    }

    /**
     * Sajikan foto bukti lewat pemeriksaan wewenang.
     *
     * Sebelumnya berkas ini duduk di disk publik: siapa pun yang memegang
     * URL-nya bisa membuka foto barang pelanggan tanpa login sama sekali.
     */
    public function attachment(Request $request, Complaint $complaint, ComplaintAttachment $attachment)
    {
        abort_unless($request->user()->canView($complaint), 403);
        abort_unless($attachment->complaint_id === $complaint->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, [
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    /** Coba tautkan ulang ke NEVIRA (dipakai saat sinkron pertama gagal). */
    public function resync(Complaint $complaint)
    {
        $user = Auth::user();
        abort_unless($user->canView($complaint), 403);
        abort_unless($user->canCreateComplaint(), 403);

        $this->syncNevira($complaint, $user);

        return back()->with('status', $complaint->nevira_sync_error
            ? 'Sinkron NEVIRA gagal: '.$complaint->nevira_sync_error
            : 'Data NEVIRA berhasil ditarik.');
    }

    /**
     * Isi identitas pelapor dari pelanggan pada nota — hanya kolom yang
     * masih kosong. Yang sudah diketik petugas tidak pernah ditimpa:
     * pelapor bisa saja bukan pemilik order, misalnya yang mengantarkan.
     */
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
            $summary  = $resolved['summary'];

            // Perjalanan kurir ditarik terpisah: detail transaksi tidak
            // membawa nama kurirnya. Gagal di sini tidak membatalkan sinkron
            // order — data kurir sifatnya pelengkap.
            if (filled($summary['invoice'])) {
                $summary['deliveries'] = $this->nevira->deliveries($summary['invoice']);
            }

            $complaint->forceFill([
                // Id internal disimpan untuk panggilan API berikutnya, dan
                // tidak pernah dirender ke halaman mana pun.
                'nevira_transaction_id'     => $resolved['id'],
                'nevira_transaction_number' => $resolved['number'] ?? $complaint->nevira_transaction_number,
                'nevira_snapshot'    => $summary,
                'nevira_customer_id' => $summary['customer_id'] ?? null,
                'nevira_synced_at'   => now(),
                'nevira_sync_error'  => null,
            ])->save();

            $this->fillReporterFromOrder($complaint, $summary);
        } catch (NeviraAccessDenied $e) {
            abort(403);
        } catch (NeviraException $e) {
            $complaint->forceFill([
                'nevira_sync_error' => mb_substr($e->userMessage(), 0, 190),
            ])->save();
        }
    }
}
