import { canonicalPageUrl, isProductionSeoHost, publicSeoPage, siteImage, siteName, siteOrigin } from '#shared/utils/siteSeo'
import { faqGroups } from '~/config/publicPages'

export default defineNuxtPlugin(() => {
  const route = useRoute()
  const hostname = useRequestURL().hostname
  const page = computed(() => publicSeoPage(route.path))
  const canonical = computed(() => canonicalPageUrl(route.path))

  useSeoMeta({
    title: () => page.value?.title ?? siteName,
    description: () => page.value?.description ?? 'Your TeeScriber workspace.',
    robots: () => page.value && isProductionSeoHost(hostname) ? 'index, follow, max-image-preview:large' : 'noindex, nofollow',
    ogTitle: () => page.value?.title ?? siteName,
    ogDescription: () => page.value?.description,
    ogUrl: () => canonical.value ?? undefined,
    ogType: 'website',
    ogSiteName: siteName,
    ogLocale: 'en_US',
    ogImage: siteImage,
    ogImageType: 'image/png',
    ogImageWidth: 1254,
    ogImageHeight: 1254,
    ogImageAlt: 'TeeScriber — audio transcription and video dubbing',
    twitterCard: 'summary',
    twitterTitle: () => page.value?.title ?? siteName,
    twitterDescription: () => page.value?.description,
    twitterImage: siteImage,
    twitterImageAlt: 'TeeScriber logo',
  })

  useHead(() => {
    const url = canonical.value
    const current = page.value
    if (!current || !url) return { link: [], script: [] }
    const websiteId = `${siteOrigin}/#website`
    const organizationId = `${siteOrigin}/#organization`
    const pageSchema = {
      '@type': current.path === '/faq' ? 'FAQPage' : current.path === '/about' ? 'AboutPage' : current.path === '/contact' ? 'ContactPage' : 'WebPage',
      '@id': `${url}#webpage`, url, name: current.title, description: current.description,
      inLanguage: 'en', isPartOf: { '@id': websiteId },
      ...(current.path === '/faq' ? { mainEntity: faqGroups.flatMap(group => group.items.map(item => ({ '@type': 'Question', name: item.question, acceptedAnswer: { '@type': 'Answer', text: item.answer } }))) } : {}),
    }
    const graph = [
      { '@type': 'Organization', '@id': organizationId, name: siteName, url: siteOrigin, logo: { '@type': 'ImageObject', url: siteImage } },
      { '@type': 'WebSite', '@id': websiteId, url: siteOrigin, name: siteName, inLanguage: 'en', publisher: { '@id': organizationId } },
      pageSchema,
    ]
    return {
      link: [{ key: 'canonical', rel: 'canonical', href: url }],
      script: [{ key: 'site-schema', type: 'application/ld+json', textContent: JSON.stringify({ '@context': 'https://schema.org', '@graph': graph }).replace(/</g, '\\u003c') }],
    }
  })
})
