<?php

namespace App\Alarms;

use App\Models\PenandaAlarm;

/**
 * Satu alarm menyala beserta penandaan yang sedang berlaku untuknya.
 *
 * Tiga keadaan di alur 2 (API-71) — PADAM, MENYALA, DITANGANI — hanya dua yang
 * pernah sampai ke sini: yang padam tidak dibuatkan barisnya sama sekali.
 * DITANGANI bukan status ketiga yang tersimpan di mana-mana, melainkan MENYALA
 * yang kebetulan punya penanda hari ini. Itu yang membuat "besok menyala lagi"
 * tidak butuh satu pun pekerjaan terjadwal untuk mengembalikannya.
 */
final class DiPapan
{
    public function __construct(
        public readonly Alarm $alarm,
        public readonly Nyala $nyala,
        public readonly ?PenandaAlarm $penanda,
    ) {}

    public function ditangani(): bool
    {
        return $this->penanda !== null;
    }
}
