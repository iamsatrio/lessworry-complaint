<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaporanFilterRequest;
use App\Services\EksporCsv;
use App\Services\NilaiBiaya;
use App\Services\RekapBiaya;
use App\Services\RekapKerugian;
use App\Services\SaringanLaporan;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman Kerugian. (API-43)
 *
 * MEMBACA, dan hanya membaca. Tidak ada satu pun rute di sini yang mengubah
 * nilai kompensasi — itu tetap hanya lewat ComplaintStatusController, tempat
 * batas wewenang per peran diperiksa. Halaman yang melaporkan uang tidak
 * boleh sekaligus jadi tempat mengubahnya.
 *
 * Saringannya — rentang tanggal dan outlet — sama persis dengan halaman
 * Laporan, lewat LaporanFilterRequest dan SaringanLaporan yang sama. Kasir
 * yang meminta outlet lain ditolak sebelum controller ini berjalan.
 */
class KerugianController extends Controller
{
    /**
     * Bawaan rentangnya 12 bulan, bukan 30 hari seperti halaman Laporan.
     * Pertanyaan yang dibawa orang ke halaman ini — "berapa yang keluar tahun
     * ini, dan dari mana" — tidak terjawab oleh sebulan data.
     */
    private const BAWAAN_BULAN = 12;

    public function index(LaporanFilterRequest $request)
    {
        $saringan = SaringanLaporan::dariPermintaan($request, now()->subMonthsNoOverflow(self::BAWAAN_BULAN));
        $rekap = new RekapKerugian($saringan->complaints(), $saringan->satuan);

        $kategori = $rekap->perKategori();
        $outletBaris = $rekap->perOutlet();
        $tindakLanjut = $rekap->perTindakLanjut();

        return view('reports.kerugian', [
            'from' => $saringan->dari,
            'to' => $saringan->sampai,
            'outlet' => $saringan->outlet,
            'pilihanOutlet' => $saringan->pilihanOutlet(),
            'satuan' => $saringan->satuan,
            'satuanDipilih' => $saringan->satuanDipilih,
            'pintasan' => $saringan->pintasan(),
            'rentangTerbaca' => $saringan->rentangTerbaca(),
            'rekap' => $rekap,
            'ringkasan' => $rekap->ringkasan(),
            // Total halaman ini TIDAK berubah oleh API-106 — yang ditambah
            // hanya kalimat yang menyebut bagian mana dari total itu yang
            // nilainya belum pasti.
            'belumPasti' => $rekap->belumPasti(),
            'golongan' => $rekap->golongan(),
            'kategori' => $kategori,
            'outletBaris' => $outletBaris,
            'tindakLanjut' => $tindakLanjut,
            'batangKategori' => $rekap->batang($kategori),
            'batangOutlet' => $rekap->batang($outletBaris),
            'batangTindakLanjut' => $rekap->batang($tindakLanjut),
            'perPeriode' => $rekap->perPeriode(),
            'titikBiaya' => $rekap->titikBiaya(),
            'titikCakupan' => $rekap->titikCakupan(),
            'periodeRendah' => $rekap->periodeRendah(),
            // Sepuluh kasus termahal. Lima teratas pada data nyata semuanya
            // Barang Rusak — dan daftar itulah yang mengubah "65% biaya dari
            // 22% kasus" dari angka jadi sesuatu yang bisa ditindaklanjuti.
            'termahal' => $rekap->complaintBerbiaya()->take(10),
            'ambangRendah' => NilaiBiaya::AMBANG_RENDAH,
        ]);
    }

    /**
     * Unduhan: satu baris per complaint berbiaya, supaya totalnya bisa
     * ditelusuri di luar sistem. Berangkat dari saringan yang sama dengan
     * halamannya — ekspor yang menyaring dengan aturan sendiri adalah ekspor
     * yang suatu hari memperlihatkan lebih banyak daripada layarnya.
     */
    public function export(LaporanFilterRequest $request): StreamedResponse
    {
        $saringan = SaringanLaporan::dariPermintaan($request, now()->subMonthsNoOverflow(self::BAWAAN_BULAN));
        $rekap = new RekapKerugian($saringan->complaints(['outlet']), $saringan->satuan);

        return (new EksporCsv(new RekapBiaya($rekap->complaintBerbiaya())))->unduh();
    }
}
