<?php

namespace App\Mail;

use App\Models\Pedido;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PedidoEnviadoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Pedido $pedido,
        public readonly string $destinatario,
    ) {}

    public function build(): self
    {
        $pedido = $this->pedido->loadMissing([
            'cliente',
            'detalles.producto',
        ]);

        return $this
            ->from(
                config('mail.from.address'),
                config('mail.from.name')
            )
            ->subject('Tu pedido #' . $pedido->id_pedido . ' está listo')
            ->view('emails.pedido-enviado', [
                'pedido' => $pedido,
                'destinatario' => $this->destinatario,
            ]);
    }
}
