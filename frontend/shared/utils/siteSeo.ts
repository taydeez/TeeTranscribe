export const siteOrigin = 'https://teescriber.com'
export const siteName = 'TeeScriber'
export const siteImage = `${siteOrigin}/logo.png`

export const publicSeoPages: Record<string, { title: string; description: string }> = {
  '/': { title: 'Audio Transcription & Video Dubbing | TeeScriber', description: 'Turn audio into editable transcripts and dub videos into other languages with TeeScriber. Create an account to start with 10 free credits. Sign in to use.' },
  '/about': { title: 'About TeeScriber | Transcription, Translation & Dubbing', description: 'Meet TeeScriber: a workspace for audio transcription, text translation, and video dubbing, built for people working across languages and audiences worldwide.' },
  '/faq': { title: 'TeeScriber FAQ | Uploads, Transcripts, Dubbing & Credits', description: 'Find answers about TeeScriber audio uploads, transcription, video dubbing, downloads, credits, and file privacy. Learn how to get started in your workspace.' },
  '/contact': { title: 'Contact TeeScriber | Workspace Help & Enquiries', description: 'Find contact information for TeeScriber workspace help, privacy questions, and business enquiries about audio transcription, translation, and video dubbing.' },
  '/privacy-policy': { title: 'Privacy Policy | Your Files & Account Data | TeeScriber', description: 'Read how TeeScriber handles your account, audio, video, transcripts, payments, and generated files, including your file retention settings and deletion choices.' },
}

export function publicSeoPage(path: string) {
  const pathname = path.split(/[?#]/, 1)[0] ?? '/'
  const normalized = pathname === '/' ? '/' : pathname.replace(/\/$/, '')
  return Object.hasOwn(publicSeoPages, normalized) ? { path: normalized, ...publicSeoPages[normalized]! } : null
}

export function isProductionSeoHost(hostname: string) {
  return ['teescriber.com', 'www.teescriber.com'].includes(hostname.toLowerCase())
}

export function canonicalPageUrl(path: string) {
  const page = publicSeoPage(path)
  return page ? `${siteOrigin}${page.path === '/' ? '/' : page.path}` : null
}

export function sitemapXml() {
  const urls = Object.keys(publicSeoPages).map(path => `<url><loc>${canonicalPageUrl(path)}</loc></url>`).join('')
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${urls}</urlset>`
}
