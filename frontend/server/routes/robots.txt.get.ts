import { isProductionSeoHost, siteOrigin } from '../../shared/utils/siteSeo'

export default defineEventHandler((event) => {
  setResponseHeader(event, 'Content-Type', 'text/plain; charset=utf-8')
  return isProductionSeoHost(getRequestURL(event).hostname)
    ? `User-agent: *\nAllow: /\nDisallow: /api/\nSitemap: ${siteOrigin}/sitemap.xml\n`
    : 'User-agent: *\nDisallow: /\n'
})
