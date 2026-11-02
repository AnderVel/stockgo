import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { extractPasswordResetCredentials } from '../auth/resetPasswordLink.mjs'
import { useToast } from '../components/Toast'

type RecoveryStep = 'request' | 'code' | 'password' | 'done'

export default function ResetPassword() {
  const [params] = useSearchParams()
  const { showToast } = useToast()
  const legacyCredentials = extractPasswordResetCredentials(
    window.location.search,
    window.location.hash,
  )
  const [legacyEmail, setLegacyEmail] = useState(
    legacyCredentials.email || params.get('email') || '',
  )
  const [legacyToken, setLegacyToken] = useState(
    legacyCredentials.token || params.get('token') || '',
  )
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [step, setStep] = useState<RecoveryStep>(
    legacyCredentials.token ? 'password' : 'request',
  )
  const [legacyDone, setLegacyDone] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (window.location.search || window.location.hash) {
      window.history.replaceState({}, document.title, window.location.pathname)
    }
  }, [])

  const parseResponse = async (response: Response) => {
    const data = await response.json().catch(() => ({}))

    if (!response.ok) {
      const passwordError = Array.isArray(data.errors)
        ? data.errors
        : data.errors?.password
      throw new Error(
        Array.isArray(passwordError)
          ? passwordError.join(' ')
          : data.message || data.mensaje || 'No fue posible completar la solicitud.',
      )
    }

    return data
  }

  const submitRequest = async (event: React.FormEvent) => {
    event.preventDefault()
    setLoading(true)
    setError('')

    try {
      const response = await fetch('/api/auth/password-reset/request-code', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email }),
      })
      const data = await parseResponse(response)
      setStep('code')
      showToast(data.message, 'success')
    } catch (caught: unknown) {
      const message = caught instanceof Error ? caught.message : 'No fue posible solicitar el código.'
      setError(message)
    } finally {
      setLoading(false)
    }
  }

  const submitCode = async (event: React.FormEvent) => {
    event.preventDefault()
    setLoading(true)
    setError('')

    try {
      const response = await fetch('/api/auth/password-reset/verify-code', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, code }),
      })
      await parseResponse(response)
      setStep('password')
    } catch (caught: unknown) {
      setError(caught instanceof Error ? caught.message : 'El código no es válido o expiró.')
    } finally {
      setLoading(false)
    }
  }

  const submitComplete = async (event: React.FormEvent) => {
    event.preventDefault()

    if (password !== confirmation) {
      setError('Las contraseñas no coinciden.')
      return
    }

    setLoading(true)
    setError('')

    try {
      const response = await fetch('/api/auth/password-reset/complete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          email,
          code,
          password,
          password_confirmation: confirmation,
        }),
      })
      const data = await parseResponse(response)
      setStep('done')
      showToast(data.message, 'success')
    } catch (caught: unknown) {
      setError(caught instanceof Error ? caught.message : 'No fue posible actualizar la contraseña.')
    } finally {
      setLoading(false)
    }
  }

  const submitLegacyReset = async (event: React.FormEvent) => {
    event.preventDefault()

    if (password !== confirmation) {
      setError('Las contraseñas no coinciden.')
      return
    }

    setLoading(true)
    setError('')

    try {
      const response = await fetch('/api/auth/reset-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          email: legacyEmail,
          token: legacyToken,
          password,
          password_confirmation: confirmation,
        }),
      })
      await parseResponse(response)
      setLegacyDone(true)
      showToast('Contraseña actualizada. Inicia sesión nuevamente.', 'success')
    } catch (caught: unknown) {
      setError(caught instanceof Error ? caught.message : 'No se pudo restablecer la contraseña.')
    } finally {
      setLoading(false)
    }
  }

  const title = legacyCredentials.token
    ? 'Restablecer contraseña'
    : step === 'request'
      ? 'Recuperar contraseña'
      : step === 'code'
        ? 'Código de recuperación'
        : step === 'password'
          ? 'Nueva contraseña'
          : 'Contraseña actualizada'

  return (
    <main className="auth-page auth-page-simple">
      <div className="auth-panel">
        <div className="auth-form-wrap">
          <div className="auth-brand compact">
            <span className="brand-mark">S<span /></span>
            <strong>StockGo</strong>
          </div>
          <p className="eyebrow">Seguridad de cuenta</p>
          <h2>{title}</h2>

          {legacyCredentials.token ? (
            legacyDone ? (
              <>
                <p className="page-description">Tu contraseña se actualizó. Inicia sesión nuevamente; la autenticación 2FA seguirá activa.</p>
                <Link to="/login" className="button-primary button-wide link-button">Volver al inicio de sesión</Link>
              </>
            ) : (
              <form onSubmit={submitLegacyReset} className="auth-form">
                <label>Correo<input type="email" value={legacyEmail} onChange={(event) => setLegacyEmail(event.target.value)} required /></label>
                <label>Token de recuperación<input value={legacyToken} onChange={(event) => setLegacyToken(event.target.value)} required /></label>
                <PasswordFields password={password} confirmation={confirmation} setPassword={setPassword} setConfirmation={setConfirmation} />
                {error && <div className="inline-error" role="alert">{error}</div>}
                <button className="button-primary button-wide" disabled={loading}>{loading ? 'Actualizando…' : 'Actualizar contraseña'}</button>
                <Link to="/login" className="reset-link">Volver al inicio de sesión</Link>
              </form>
            )
          ) : step === 'request' ? (
            <form onSubmit={submitRequest} className="auth-form">
              <p className="page-description">Te enviaremos un código de recuperación al correo registrado. Este código no es tu código TOTP de 2FA y no sirve para iniciar sesión.</p>
              <label>Correo de tu cuenta<input type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label>
              {error && <div className="inline-error" role="alert">{error}</div>}
              <button className="button-primary button-wide" disabled={loading}>{loading ? 'Solicitando…' : 'Enviar código'}</button>
              <Link to="/login" className="reset-link">Volver al inicio de sesión</Link>
            </form>
          ) : step === 'code' ? (
            <form onSubmit={submitCode} className="auth-form">
              <p className="page-description" role="status">Si el correo corresponde a una cuenta, recibirás un código de recuperación de 8 dígitos. No uses aquí el código TOTP de tu aplicación autenticadora.</p>
              <label>Código de recuperación<input type="text" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{8}" maxLength={8} value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 8))} required /></label>
              {error && <div className="inline-error" role="alert">{error}</div>}
              <button className="button-primary button-wide" disabled={loading}>{loading ? 'Verificando…' : 'Verificar código'}</button>
              <button type="button" className="text-button" disabled={loading} onClick={() => { setStep('request'); setCode(''); setError('') }}>Solicitar otro código</button>
              <Link to="/login" className="reset-link">Volver al inicio de sesión</Link>
            </form>
          ) : step === 'password' ? (
            <form onSubmit={submitComplete} className="auth-form">
              <p className="page-description">El código fue verificado. El cambio volverá a validar el código y no desactivará 2FA ni iniciará sesión automáticamente.</p>
              <PasswordFields password={password} confirmation={confirmation} setPassword={setPassword} setConfirmation={setConfirmation} />
              {error && <div className="inline-error" role="alert">{error}</div>}
              <button className="button-primary button-wide" disabled={loading}>{loading ? 'Actualizando…' : 'Actualizar contraseña'}</button>
              <Link to="/login" className="reset-link">Cancelar y volver al inicio de sesión</Link>
            </form>
          ) : (
            <>
              <p className="page-description">Tu contraseña fue actualizada y las sesiones anteriores se revocaron. Inicia sesión nuevamente; si tienes 2FA, completa el desafío TOTP habitual.</p>
              <Link to="/login" className="button-primary button-wide link-button">Ir al inicio de sesión</Link>
            </>
          )}
        </div>
      </div>
    </main>
  )
}

function PasswordFields({
  password,
  confirmation,
  setPassword,
  setConfirmation,
}: {
  password: string
  confirmation: string
  setPassword: (value: string) => void
  setConfirmation: (value: string) => void
}) {
  return (
    <>
      <label>Nueva contraseña<input type="password" autoComplete="new-password" minLength={12} maxLength={255} value={password} onChange={(event) => setPassword(event.target.value)} required /></label>
      <label>Confirmar nueva contraseña<input type="password" autoComplete="new-password" minLength={12} maxLength={255} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} required /></label>
    </>
  )
}
