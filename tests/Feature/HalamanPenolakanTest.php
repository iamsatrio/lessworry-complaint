<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kasir yang membuka /users mendapat halaman putih bertuliskan "403
 * Forbidden": tanpa header, tanpa navigasi, tanpa bahasa Indonesia, dan tanpa
 * jalan kembali selain tombol back peramban.
 *
 * Pembatasannya sendiri benar dan tidak disentuh — yang diperbaiki hanya cara
 * menolaknya. (API-38 #9)
 */
class HalamanPenolakanTest extends TestCase
{
    use RefreshDatabase;

    private function kasir(): User
    {
        $outlet = Outlet::create(['name' => 'Outlet Uji', 'is_active' => true]);

        return User::create([
            'name' => 'Kasir', 'email' => 'kasir'.uniqid().'@lessworry.id',
            'password' => 'secret123', 'role' => 'kasir', 'outlet_id' => $outlet->id,
        ]);
    }

    public function test_penolakan_memakai_layout_aplikasi_dan_bahasa_indonesia(): void
    {
        $this->actingAs($this->kasir())
            ->get('/users')
            ->assertStatus(403)
            ->assertSee('Halaman ini tidak terbuka untuk peranmu')
            ->assertSee('Kembali ke Papan Kerja')
            ->assertSee('Less Worry')
            ->assertDontSee('Forbidden');
    }

    /** Yang diperbaiki tampilannya, bukan wewenangnya. */
    public function test_kasir_tetap_ditolak(): void
    {
        $this->actingAs($this->kasir())->get('/users')->assertStatus(403);
    }

    /**
     * Pesan bawaan exception sengaja tidak dicetak: policy Laravel membalas
     * "This action is unauthorized." dan pesan abort() lain bisa menyebut
     * nama rute atau kolom.
     */
    public function test_pesan_internal_tidak_bocor_ke_layar(): void
    {
        $this->actingAs($this->kasir())
            ->get('/users')
            ->assertStatus(403)
            ->assertDontSee('unauthorized');
    }
}
