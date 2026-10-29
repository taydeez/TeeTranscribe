import { sitemapXml } from '../../shared/utils/siteSeo'

export default defineEventHandler((event) => {
  setResponseHeader(event, 'Content-Type', 'application/xml; charset=utf-8')
  return sitemapXml()
})
