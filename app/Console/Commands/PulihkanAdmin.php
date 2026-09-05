<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Jalan pulang saat pengelolaan pengguna terkunci. (API-14 #1)
 *
 * Pengaman di UserController mencegah admin aktif terakhir hilang, tapi
 * pengaman saja tidak cukup: basis data bisa terlanjur sampai ke keadaan itu
 * lewat seeder, migrasi data, atau perbaikan manual yang meleset. Tanpa
 * perintah ini satu-satunya jalan keluar adalah menulis langsung ke basis
 * data produksi.
 *
 * Dijalankan dari shell server, jadi tidak melewati pemeriksaan peran HTTP —
 * yang memegang shell memang sudah memegang basis datanya.
 */
class PulihkanAdmin extends Command
{
    protected $signature = 'lessworry:pulihkan-admin
                            {email : email akun yang mau diangkat}
                            {--reset-password : setel ulang password jadi sementara}';

    protected $description = 'Angkat satu akun jadi admin aktif — jalan pulih saat semua admin terkunci.';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Akun dengan email itu tidak ada.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => 'admin', 'is_active' => true]);

        if ($this->option('reset-password')) {
            $temporary = Str::password(12, symbols: false);
            $user->forceFill(['password' => $temporary, 'must_change_password' => true]);
        }

        $user->save();

        $this->info($user->name.' ('.$user->email.') sekarang admin aktif.');

        if (isset($temporary)) {
            // Hanya ke layar orang yang menjalankan perintah. Tidak ke log,
            // tidak tersimpan terbaca.
            $this->warn('Password sementara: '.$temporary);
            $this->line('Wajib diganti saat login pertama. Sampaikan lewat jalur pribadi, jangan grup.');
        }

        $this->line('Admin aktif sekarang: '.User::where('role', 'admin')->where('is_active', true)->count());

        return self::SUCCESS;
    }
}
