<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Código de recuperación</title>
</head>
<body style="margin:0; padding:24px; background:#f5f5f5; font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif; color:#24213a;">
  <div style="max-width:520px; margin:0 auto; background:#fff; border:1px solid #e7e5f0; border-radius:14px; padding:28px;">
    <h1 style="margin:0 0 6px; font-size:20px; color:#4d42b9;">StockGo</h1>
    <p style="margin:0 0 18px; font-size:14px;">Recibimos una solicitud para restablecer tu contraseña.</p>

    <div style="text-align:center; background:#f6f4ff; border:1px solid #e3e1ee; border-radius:10px; padding:16px; margin:0 0 18px;">
      <span style="font-size:11px; color:#77758d; letter-spacing:.08em;">TU CÓDIGO</span>
      <div style="font-size:30px; font-weight:800; letter-spacing:.18em; color:#24213a; margin-top:6px;">{{ $code }}</div>
    </div>

    <p style="margin:0 0 8px; font-size:13px;">El código vence en <strong>{{ $minutes }} minutos</strong> y solo puede usarse una vez.</p>
    <p style="margin:0; font-size:12px; color:#77758d;">Si no solicitaste este cambio, ignora este correo: tu contraseña actual seguirá activa.</p>
  </div>
</body>
</html>
