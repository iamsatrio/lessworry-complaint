<?php

namespace App\Http\Requests;

use App\Models\Outlet;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Menambah atau mengubah satu tagihan. (API-73)
 *
 * Wewenangnya sendiri — `dashboard.manage_tagihan` — ditegakkan middleware di
 * rutenya, yang sudah berjalan sebelum request ini dibentuk. Yang dijawab di
 * sini: bentuk isiannya benar, dan outlet yang dipilih memang boleh dipilih
 * orang ini.
 */
class TagihanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->input('outlet_id');

        // Kosong = tagihan tingkat jaringan. Boleh bagi siapa pun yang sudah
        // lolos gerbang pengelolaan.
        if ($id === null || $id === '') {
            return true;
        }

        // Bentuk yang salah dijawab rules() sebagai 422, bukan 403 di sini.
        if (! is_numeric($id)) {
            return true;
        }

        $outlet = Outlet::find((int) $id);

        /** @var User|null $user */
        $user = $this->user();

        // Outlet yang tidak ada dan outlet di luar cakupan dijawab sama —
        // alasannya sama dengan MenyaringOutlet: selisih kode jawaban cukup
        // untuk memetakan jaringan.
        return $outlet !== null && $user !== null && $user->can('view', $outlet);
    }

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:120'],

            // Boleh kosong: tidak semua tagihan tetap nominalnya. Rupiah
            // bulat, dan batas atasnya ada supaya salah ketik nol berlebih
            // tidak tersimpan sebagai angka yang tidak berarti.
            'jumlah' => ['nullable', 'integer', 'min:0', 'max:999999999999'],

            'pengulangan' => ['required', 'in:bulanan,tahunan'],
            'jatuh_tempo_hari' => ['required', 'integer', 'min:1', 'max:31'],

            // Hanya tagihan tahunan yang punya bulan. Untuk yang bulanan
            // nilainya dibuang di data(), bukan ditolak — mengganti
            // pengulangan di form tidak boleh gagal hanya karena select bulan
            // masih terisi dari pilihan sebelumnya.
            'jatuh_tempo_bulan' => ['nullable', 'integer', 'min:1', 'max:12', 'required_if:pengulangan,tahunan'],

            'outlet_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nama' => 'nama tagihan',
            'jumlah' => 'jumlah',
            'pengulangan' => 'pengulangan',
            'jatuh_tempo_hari' => 'tanggal jatuh tempo',
            'jatuh_tempo_bulan' => 'bulan jatuh tempo',
            'outlet_id' => 'outlet',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Nama yang sama untuk outlet yang sama berarti dua baris yang
            // tidak bisa dibedakan di kartu alarm — dan dua pengingat untuk
            // satu tagihan membuat orang mengabaikan keduanya. Tagihan
            // nonaktif tidak ikut: nama boleh dipakai ulang setelah yang lama
            // dipensiunkan.
            $bentrok = Tagihan::query()
                ->where('nama', (string) $this->input('nama'))
                ->where('is_active', true)
                ->when(
                    $this->input('outlet_id') === null || $this->input('outlet_id') === '',
                    fn ($q) => $q->whereNull('outlet_id'),
                    fn ($q) => $q->where('outlet_id', (int) $this->input('outlet_id')),
                )
                ->when($this->route('tagihan') !== null, fn ($q) => $q->whereKeyNot($this->route('tagihan')->id))
                ->exists();

            if ($bentrok) {
                $validator->errors()->add('nama', 'Sudah ada tagihan aktif dengan nama ini untuk outlet yang sama.');
            }
        });
    }

    /**
     * Isian yang siap disimpan.
     *
     * @return array<string, mixed>
     */
    public function tersimpan(): array
    {
        $tahunan = $this->input('pengulangan') === 'tahunan';
        $outletId = $this->input('outlet_id');
        $jumlah = $this->input('jumlah');

        return [
            'nama' => trim((string) $this->input('nama')),
            'jumlah' => $jumlah === null || $jumlah === '' ? null : (int) $jumlah,
            'pengulangan' => $tahunan ? 'tahunan' : 'bulanan',
            'jatuh_tempo_hari' => (int) $this->input('jatuh_tempo_hari'),
            // Bulan hanya berarti untuk tagihan tahunan. Dikosongkan, bukan
            // dibiarkan: bulan yang tertinggal di baris bulanan akan terbaca
            // sebagai tagihan tahunan kalau kelak pengulangannya disimpulkan
            // dari kolom ini.
            'jatuh_tempo_bulan' => $tahunan ? (int) $this->input('jatuh_tempo_bulan') : null,
            'outlet_id' => $outletId === null || $outletId === '' ? null : (int) $outletId,
        ];
    }
}
