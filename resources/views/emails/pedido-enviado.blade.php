<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pedido #{{ $pedido->id_pedido }}</title>
</head>
<body style="margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color: #222; font-size: 14px;">
  <div style="max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #e3e3e3; border-radius: 8px; overflow: hidden;">
    <div style="background: #2d2b55; padding: 24px 28px;">
      <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #ffffff; letter-spacing: -0.02em;">StockGo</h1>
      <p style="margin: 4px 0 0; color: #b8b4e8; font-size: 11px; letter-spacing: 0.04em;">PEDIDO LISTO PARA ENTREGA</p>
    </div>

    <div style="padding: 24px 28px;">
      <p style="margin: 0 0 18px;">
        Hola, <strong>{{ $pedido->cliente?->nombre ?? 'Cliente' }}</strong>:
      </p>

      <p style="margin: 0 0 18px;">
        Te escribimos para confirmar que tu pedido <strong>#{{ $pedido->id_pedido }}</strong> ya está listo.
        A continuación encontrarás el resumen del pedido.
      </p>

      <div style="background: #faf8ff; border-left: 4px solid #6659d9; border-radius: 6px; padding: 12px 14px; margin: 0 0 18px; font-size: 12px; color: #4a4860;">
        <div><strong>Fecha del pedido:</strong> {{ $pedido->fecha_pedido?->format('d/m/Y') ?? '—' }}</div>
        <div><strong>Estado:</strong> Enviado</div>
        @if ($pedido->cliente?->direccion)
          <div><strong>Dirección:</strong> {{ $pedido->cliente->direccion }}</div>
        @endif
      </div>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 0 0 16px;">
        <thead>
          <tr>
            <th style="text-align: left; padding: 8px 10px; border-bottom: 2px solid #ececec; font-size: 11px; color: #77758d;">Producto</th>
            <th style="text-align: center; padding: 8px 10px; border-bottom: 2px solid #ececec; font-size: 11px; color: #77758d;">Cant.</th>
            <th style="text-align: right; padding: 8px 10px; border-bottom: 2px solid #ececec; font-size: 11px; color: #77758d;">Precio</th>
            <th style="text-align: right; padding: 8px 10px; border-bottom: 2px solid #ececec; font-size: 11px; color: #77758d;">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($pedido->detalles as $detalle)
            <tr>
              <td style="padding: 10px; border-bottom: 1px solid #f0f0f4;">
                <div style="font-weight: 600;">{{ $detalle->producto?->nombre ?? 'Producto' }}</div>
                <div style="color: #8a879f; font-size: 10px;">{{ $detalle->producto?->codigo_barras ?? '' }}</div>
              </td>
              <td style="padding: 10px; border-bottom: 1px solid #f0f0f4; text-align: center;">
                {{ $detalle->cantidad }}
              </td>
              <td style="padding: 10px; border-bottom: 1px solid #f0f0f4; text-align: right; white-space: nowrap;">
                ${{ number_format((float) $detalle->precio_unitario, 2) }}
              </td>
              <td style="padding: 10px; border-bottom: 1px solid #f0f0f4; text-align: right; font-weight: 700; white-space: nowrap;">
                ${{ number_format((float) $detalle->subtotal, 2) }}
              </td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" style="padding: 14px 10px 4px; text-align: right; color: #77758d; font-size: 12px;">
              Total
            </td>
            <td style="padding: 14px 10px 4px; text-align: right; font-size: 20px; font-weight: 800; color: #4d42b9; white-space: nowrap;">
              ${{ number_format((float) $pedido->total, 2) }}
            </td>
          </tr>
        </tfoot>
      </table>

      @if ($pedido->observaciones)
        <div style="background: #faf8ff; border: 1px solid #eceaf3; border-radius: 6px; padding: 12px 14px; margin: 0 0 18px; font-size: 12px; color: #4a4860;">
          <strong style="display: block; margin-bottom: 6px; color: #77758d; font-size: 10px; letter-spacing: 0.04em;">OBSERVACIONES</strong>
          {{ $pedido->observaciones }}
        </div>
      @endif

      <div style="border-top: 1px solid #ececec; padding-top: 14px; color: #8a879f; font-size: 11px; line-height: 1.5;">
        <p style="margin: 0 0 6px;">📦 Armado por StockGo — Control de inventario</p>
        <p style="margin: 0;">Si tienes dudas sobre el pedido, responde este correo o contacta a tu vendedor.</p>
      </div>

      <p style="margin: 18px 0 0; color: #aaa7b8; font-size: 10px;">
        Este es un correo automático. No respondas directamente.
      </p>
    </div>
  </div>
</body>
</html>
