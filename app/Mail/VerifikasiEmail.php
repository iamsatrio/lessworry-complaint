<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Surat berisi tautan verifikasi. (API-35 bagian 2)
 *
 * Isinya bahasa Indonesia, menyebut nama orangnya, dan menyatakan dengan
 * jelas apa yang harus dilakukan kalau dia tidak merasa meminta surat ini —
 * itu satu-satunya cara pemilik akun tahu password sementaranya bocor.
 */
class VerifikasiEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $tautan,
        public int $umurMenit,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verifikasi akun Complaint Less Worry');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verifikasi');
    }
}
