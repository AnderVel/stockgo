<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $token,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Código de verificación de correo – StockGo',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verification-code',
            with: [
                'token' => $this->token,
                'minutes' => $this->minutes,
            ],
        );
    }
}
