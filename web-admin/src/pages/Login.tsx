import { useState } from 'react'
import { useNavigate, Navigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

export default function Login() {
  const { user, login } = useAuth()
  const nav = useNavigate()
  const [usuario, setUsuario] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [error, setError] = useState('')
  const [showForgot, setShowForgot] = useState(false)
  const [forgotEmail, setForgotEmail] = useState('')
  const [forgotMessage, setForgotMessage] = useState('')

  if (user) return <Navigate to="/" replace />

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    const res = await login(usuario, password, code)
    if (res.ok) nav('/')
    else setError(res.message || 'Error')
  }

  const submitForgot = async (e: React.FormEvent) => {
    e.preventDefault()
    const res = await fetch('/api/auth/forgot-password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: forgotEmail }),
    })
    const data = await res.json()
    setForgotMessage(data.message || 'Revisa tu correo')
  }

  return (
    <div className="login">
      <form onSubmit={submit}>
        <h1>StockGo Web Admin</h1>
        <input placeholder="Usuario" value={usuario} onChange={(e) => setUsuario(e.target.value)} required />
        <input type="password" placeholder="Contraseña" value={password} onChange={(e) => setPassword(e.target.value)} required />
        <input placeholder="Código 2FA" value={code} onChange={(e) => setCode(e.target.value)} required />
        {error && <p className="error">{error}</p>}
        <button type="submit">Entrar</button>
      </form>
      <div style={{ marginTop: '1rem' }}>
        <a style={{ color: '#4b5563', cursor: 'pointer', textDecoration: 'underline' }} onClick={() => setShowForgot(!showForgot)}>¿Olvidaste tu contraseña?</a>
      </div>
      {showForgot && (
        <form onSubmit={submitForgot} style={{ marginTop: '1rem' }}>
          <p>Ingresa el correo con el que se registró el usuario</p>
          <input style={{ width: '100%', padding: '0.5rem', marginBottom: '0.5rem' }} type="email" placeholder="Correo" value={forgotEmail} onChange={(e) => setForgotEmail(e.target.value)} required />
          <button type="submit">Enviar token</button>
          {forgotMessage && <p style={{ marginTop: '0.5rem' }}>{forgotMessage}</p>}
        </form>
      )}
    </div>
  )
}
