import { canonicalHostRedirect, isProductionSeoHost, publicSeoPage } from '../../shared/utils/siteSeo'

export default defineEventHandler((event) => {
  const url = getRequestURL(event)
  const redirect = canonicalHostRedirect(url.toString())
  if (redirect) return sendRedirect(event, redirect, 308)
  const asset = url.pathname.startsWith('/_nuxt/') || ['/logo.png', '/favicon.png', '/social-card.png', '/robots.txt', '/sitemap.xml'].includes(url.pathname)
  if (!isProductionSeoHost(url.hostname) || (!publicSeoPage(url.pathname) && !asset)) {
    setResponseHeader(event, 'X-Robots-Tag', 'noindex, nofollow')
  }
})
