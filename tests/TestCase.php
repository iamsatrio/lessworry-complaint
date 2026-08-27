<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Berganti pengguna di tengah test berarti berganti PERANGKAT, jadi
     * sesinya juga baru.
     *
     * Sejak middleware auth.session terpasang (API-14 #2), sesi menyimpan
     * hash password pemiliknya dan permintaan berikutnya dicocokkan dengan
     * hash yang tersimpan di basis data. Tanpa membersihkan sesi di sini,
     * setiap test yang memakai dua akun akan membawa hash akun pertama ke
     * permintaan akun kedua dan dilempar ke /login — kegagalan palsu yang
     * tidak ada hubungannya dengan yang sedang diuji.
     *
     * Test yang memang ingin meniru sesi basi tetap bisa: panggil
     * withSession(['password_hash_web' => ...]) setelah actingAs().
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->flushSession();

        return parent::actingAs($user, $guard);
    }
}
