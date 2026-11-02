import { useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { userService } from '../api/services'
import { useToast } from '../components/Toast'

type SetupData = {
  secret: string
  otpauth_url: string
}

export default function Login() {
  const { user, login } = useAuth()
  const nav = useNavigate()
  const { showToast } = useToast()

  const [usuario, setUsuario] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const [canSetup, setCanSetup] = useState(false)
  const [setup, setSetup] = useState<SetupData | null>(null)
  const [setupCode, setSetupCode] = useState('')
  const [setupLoading, setSetupLoading] = useState(false)

  if (user) return <Navigate to="/" replace />

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()

    if (!/^\d{6}$/.test(code)) {
      setError('Ingresa el código de 6 dígitos de tu aplicación autenticadora.')
      return
    }

    setLoading(true)
    setError('')
    setCanSetup(false)

    try {
      const result = await login(usuario.trim(), password, code)

      if (result.ok) {
        // AuthContext debe guardar el token y el usuario antes de navegar.
        nav('/', { replace: true })
        return
      }

      setError(
        result.message ||
          `No fue posible iniciar sesión (HTTP ${result.status ?? 'desconocido'}).`,
      )

      setCanSetup(
        result.status === 403 &&
          result.message?.toLowerCase().includes('configurar') === true,
      )
    } catch (caught: unknown) {
      const message =
        caught instanceof Error
          ? caught.message
          : 'Ocurrió un error inesperado al iniciar sesión.'

      setError(message)
    } finally {
      setLoading(false)
    }
  }

  const startSetup = async () => {
    setSetupLoading(true)
    setError('')

    try {
      const data = (await userService.setup2fa(
        usuario.trim(),
        password,
      )) as SetupData

      setSetup(data)
      setCanSetup(false)

      showToast(
        'Configuración 2FA preparada. Registra la cuenta antes de confirmar.',
        'success',
      )
    } catch (caught: unknown) {
      setError(
        caught instanceof Error
          ? caught.message
          : 'No fue posible preparar el 2FA.',
      )
    } finally {
      setSetupLoading(false)
    }
  }

  const confirmSetup = async (event: React.FormEvent) => {
    event.preventDefault()
    setSetupLoading(true)
    setError('')

    try {
      await userService.confirm2fa(usuario.trim(), password, setupCode)

      showToast('2FA activado. Iniciando sesión…', 'success')

      const result = await login(usuario.trim(), password, setupCode)

      if (result.ok) {
        nav('/', { replace: true })
      } else {
        setError(
          result.message ||
            '2FA activado. Vuelve a iniciar sesión con el código actual.',
        )
      }
    } catch (caught: unknown) {
      setError(
        caught instanceof Error
          ? caught.message
          : 'El código 2FA no es válido.',
      )
    } finally {
      setSetupLoading(false)
    }
  }

  return (
    <main className="auth-page">
      <div className="auth-art">
        <div className="auth-brand">
          <span className="brand-mark">
            S<span />
          </span>
          <strong>StockGo</strong>
        </div>

        <div>
          <p className="eyebrow">Control inteligente</p>
          <h1>
            Todo tu inventario,
            <br />
            <em>en movimiento.</em>
          </h1>
          <p>
            Una vista clara para saber qué entra, qué sale y qué necesita
            atención.
          </p>
        </div>

        <div className="auth-art-footer">
          <span>
            <i /> API segura
          </span>
          <span>
            <i /> Datos centralizados
          </span>
        </div>
      </div>

      <div className="auth-panel">
        <div className="auth-form-wrap">
          <p className="eyebrow">Web Admin</p>
          <h2>Bienvenido de nuevo</h2>
          <p className="page-description">
            Ingresa con tu cuenta de Administrador.
          </p>

          <form onSubmit={submit} className="auth-form">
            <label>
              Usuario
              <input
                autoComplete="username"
                value={usuario}
                onChange={(event) => setUsuario(event.target.value)}
                required
              />
            </label>

            <label>
              Contraseña
              <input
                type="password"
                autoComplete="current-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
              />
            </label>

            <label>
              Código 2FA
              <input
                type="text"
                inputMode="numeric"
                autoComplete="one-time-code"
                pattern="[0-9]{6}"
                maxLength={6}
                placeholder="000000"
                value={code}
                onChange={(event) =>
                  setCode(event.target.value.replace(/\D/g, '').slice(0, 6))
                }
                required
              />
            </label>

            {error && (
              <div className="inline-error" role="alert">
                {error}
              </div>
            )}

            <button
              type="submit"
              className="button-primary button-wide"
              disabled={loading}
            >
              {loading ? 'Verificando…' : 'Entrar al panel'}
            </button>
          </form>

          {canSetup && !setup && (
            <button
              type="button"
              className="button-secondary button-wide setup-button"
              onClick={startSetup}
              disabled={setupLoading}
            >
              {setupLoading ? 'Preparando 2FA…' : 'Configurar 2FA Web'}
            </button>
          )}

          {setup && (
            <form onSubmit={confirmSetup} className="setup-card">
              <strong>Configura tu aplicación TOTP</strong>

              <p>
                Agrega esta cuenta en Google Authenticator o una aplicación
                compatible. Si tu aplicación no puede leer el QR, usa la clave
                manual.
              </p>

              <code className="totp-secret">{setup.secret}</code>

              <details>
                <summary>Ver URI de configuración</summary>
                <code className="totp-uri">{setup.otpauth_url}</code>
              </details>

              <label>
                Código de confirmación
                <input
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  pattern="[0-9]{6}"
                  maxLength={6}
                  value={setupCode}
                  onChange={(event) =>
                    setSetupCode(
                      event.target.value.replace(/\D/g, '').slice(0, 6),
                    )
                  }
                  required
                />
              </label>

              <button
                type="submit"
                className="button-primary button-wide"
                disabled={setupLoading}
              >
                {setupLoading ? 'Confirmando…' : 'Activar 2FA e ingresar'}
              </button>
            </form>
          )}

          <Link to="/reset-password" className="text-button">
            ¿Olvidaste tu contraseña?
          </Link>
        </div>
      </div>
    </main>
  )
}
