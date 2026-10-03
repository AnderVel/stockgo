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

  if (user) return <Navigate to="/" replace />

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    const res = await login(usuario, password, code)
    if (res.ok) nav('/')
    else setError(res.message || 'Error')
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
    </div>
  )
}
