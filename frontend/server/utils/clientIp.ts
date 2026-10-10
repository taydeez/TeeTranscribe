import type { H3Event } from 'h3'

export function clientIp(event: H3Event): string | undefined {
  const config = useRuntimeConfig(event)
  const direct = getRequestIP(event)
  const trusted = String(config.trustedProxyIps ?? '').split(',').map(value => value.trim()).filter(Boolean)
  return direct && trusted.includes(direct) ? getRequestIP(event, { xForwardedFor: true }) : direct
}
