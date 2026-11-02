export function extractPasswordResetCredentials(
  search: string,
  hash: string,
): { email: string; token: string }
