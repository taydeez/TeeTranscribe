import { productPages } from '../config/productPages'
import type { FeatureFlags } from '../../app/types/features'

export const siteOrigin = 'https://teescriber.com'
export const siteName = 'TeeScriber'
export const siteImage = `${siteOrigin}/social-card.png`
export const siteLogo = `${siteOrigin}/logo.png`

const informationPages: Record<string, { title: string; description: string }> = {
  '/': { title: 'Audio Transcription & Video Dubbing | TeeScriber', description: 'Turn audio into editable transcripts and dub videos into other languages with TeeScriber. Create an account to start with 10 free credits. Sign in to use.' },
  '/about': { title: 'About TeeScriber | Transcription, Translation & Dubbing', description: 'Meet TeeScriber: a workspace for audio transcription, text translation, and video dubbing, built for people working across languages and audiences worldwide.' },
  '/faq': { title: 'TeeScriber FAQ | Uploads, Transcripts, Dubbing & Credits', description: 'Find answers about TeeScriber audio uploads, transcription, video dubbing, downloads, credits, and file privacy. Learn how to get started in your workspace.' },
  '/contact': { title: 'Contact TeeScriber | Workspace Help & Enquiries', description: 'Find contact information for TeeScriber workspace help, privacy questions, and business enquiries about audio transcription, translation, and video dubbing.' },
  '/privacy-policy': { title: 'Privacy Policy | Your Files & Account Data | TeeScriber', description: 'Read how TeeScriber handles your account, audio, video, transcripts, payments, and generated files, including your file retention settings and deletion choices.' },
}

export const publicSeoPages: Record<string, { title: string; description: string }> = {
  ...informationPages,
  ...Object.fromEntries(Object.entries(productPages).map(([path, page]) => [path, { title: page.title, description: page.description }])),
  '/pricing': { title: 'Credit Packages & Pricing | TeeScriber', description: 'Explore TeeScriber credit packages in US dollars. Review example activity rates and see how quotes, reserved credits and payment invoices work.' },
}

export function publicSeoPage(path: string, features?: FeatureFlags) {
  const pathname = path.split(/[?#]/, 1)[0] ?? '/'
  const normalized = pathname === '/' ? '/' : pathname.replace(/\/$/, '')
  const page = Object.hasOwn(publicSeoPages, normalized) ? { path: normalized, ...publicSeoPages[normalized]! } : null
  if (page?.path === '/' && features) {
    const labels = [features.transcription && 'Audio Transcription', features.video_dubbing && 'Video Dubbing', features.translation && 'Text Translation', features.audio_dubbing && 'Audio Dubbing', features.subtitles && 'Video Subtitles'].filter(Boolean).slice(0, 2)
    return { ...page, title: labels.length ? `${labels.join(' & ')} | ${siteName}` : `${siteName} | Your Creative Workspace`, description: labels.length ? `${labels.join(' and ')} in one workspace. Review credit quotes before starting, download your results, and keep your projects organised with TeeScriber.` : 'Keep your projects, files, downloads and account together in your TeeScriber workspace.' }
  }
  return page
}

export function isProductionSeoHost(hostname: string) {
  return ['teescriber.com', 'www.teescriber.com'].includes(hostname.toLowerCase())
}

export function canonicalPageUrl(path: string) {
  const page = publicSeoPage(path)
  return page ? `${siteOrigin}${page.path === '/' ? '/' : page.path}` : null
}

export function sitemapXml(features?: FeatureFlags) {
  const urls = Object.keys(publicSeoPages).filter(path => !productPages[path] || !features || features[productPages[path]!.feature]).map(path => `<url><loc>${canonicalPageUrl(path)}</loc></url>`).join('')
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${urls}</urlset>`
}


export function canonicalHostRedirect(input: string): string | null {
  const url = new URL(input)
  return url.hostname.toLowerCase() === 'www.teescriber.com' ? `${siteOrigin}${url.pathname}${url.search}` : null
}
