export function extractPasswordResetCredentials(search, hash) {
  const queryParameters = new URLSearchParams(search)
  const fragmentParameters = new URLSearchParams(hash.replace(/^#/, ''))

  return {
    email: fragmentParameters.get('email') || queryParameters.get('email') || '',
    token: fragmentParameters.get('token') || queryParameters.get('token') || '',
  }
}
