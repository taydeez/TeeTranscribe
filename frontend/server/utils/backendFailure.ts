type UpstreamFailure = {
  statusCode?: number
  cause?: { name?: string }
  response?: { status?: number; headers?: { get: (name: string) => string | null } }
  data?: unknown
}

export function describeBackendFailure(error: unknown, backendUrl: string, path: string) {
  const failure = error as UpstreamFailure
  const timedOut = ['TimeoutError', 'AbortError'].includes(failure.cause?.name ?? '')
  const status = failure.statusCode ?? failure.response?.status ?? (timedOut ? 504 : 502)
  const headers = failure.response?.headers
  const contentType = headers?.get('content-type') ?? null
  const challenged = headers?.get('cf-mitigated') === 'challenge'
  const data = failure.data
  const upstreamMessage = data && typeof data === 'object' && 'message' in data && typeof data.message === 'string' ? data.message : null
  const html = contentType?.includes('text/html') || (typeof data === 'string' && data.trimStart().startsWith('<'))
  const signIn = ['auth/login', 'auth/admin/login', 'auth/admin/verify'].includes(path)
  let origin = '[invalid API URL]'
  try { origin = new URL(backendUrl).origin } catch { /* Report configuration failures without including credentials or query strings. */ }

  return {
    status,
    message: status >= 500 || challenged || html
      ? signIn ? 'Sign-in is temporarily unavailable. Please try again shortly.' : 'The server is temporarily unavailable. Please try again shortly.'
      : upstreamMessage ?? 'Your request could not be processed.',
    shouldLog: status >= 500 || !upstreamMessage || challenged || Boolean(html),
    diagnostics: {
      backend_origin: origin,
      endpoint: path,
      upstream_status: status,
      response_content_type: contentType,
      upstream_server: headers?.get('server') ?? null,
      cloudflare_ray: headers?.get('cf-ray') ?? null,
      reason: challenged ? 'cloudflare_challenge' : html ? 'upstream_html_response' : timedOut ? 'upstream_timeout' : failure.response ? 'upstream_http_error' : 'upstream_connection_error',
    },
  }
}
