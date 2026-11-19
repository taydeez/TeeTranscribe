import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import ts from 'typescript'

const productSource = await readFile(new URL('../../shared/config/productPages.ts', import.meta.url), 'utf8')
const productCode = ts.transpileModule(productSource, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const productUrl = `data:text/javascript;base64,${Buffer.from(productCode).toString('base64')}`
const source = await readFile(new URL('../../shared/utils/siteSeo.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText.replace("'../config/productPages'", JSON.stringify(productUrl))
const { canonicalPageUrl, isProductionSeoHost, publicSeoPage, sitemapXml, canonicalHostRedirect } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

test('public canonical URLs use the production domain and exclude query strings and fragments', () => {
  assert.equal(canonicalPageUrl('/?login=1#token=secret'), 'https://teescriber.com/')
  assert.equal(canonicalPageUrl('/about/?utm_source=email'), 'https://teescriber.com/about')
  assert.equal(canonicalPageUrl('/faq'), 'https://teescriber.com/faq')
})

test('workspace, authentication, callbacks, admin and unknown routes are never public SEO pages', () => {
  for (const path of ['/dashboard', '/dashboard/transcriptions/private-id', '/auth/reset-password?token=secret', '/payment/callback', '/taydeez', '/taydeez/login', '/api/auth/user', '/missing', '/constructor', '/toString']) {
    assert.equal(publicSeoPage(path), null, path)
    assert.equal(canonicalPageUrl(path), null, path)
  }
})

test('production indexing does not include preview domains or lookalike hosts', () => {
  assert.equal(isProductionSeoHost('teescriber.com'), true)
  assert.equal(isProductionSeoHost('WWW.TEESCRIBER.COM'), true)
  for (const host of ['localhost', '127.0.0.1', 'staging.teescriber.com', 'teescriber.pages.dev', 'teescriber.com.attacker.example']) assert.equal(isProductionSeoHost(host), false, host)
})

test('sitemap contains only public canonical pages without invented modification dates', () => {
  const xml = sitemapXml()
  const urls = Array.from(xml.matchAll(/<loc>(.*?)<\/loc>/g), match => match[1])
  assert.deepEqual(urls, ['https://teescriber.com/', 'https://teescriber.com/about', 'https://teescriber.com/faq', 'https://teescriber.com/contact', 'https://teescriber.com/privacy-policy', 'https://teescriber.com/audio-to-text', 'https://teescriber.com/video-to-text', 'https://teescriber.com/video-dubbing', 'https://teescriber.com/audio-dubbing', 'https://teescriber.com/add-subtitles-to-video', 'https://teescriber.com/text-translation', 'https://teescriber.com/pricing'])
  assert.ok(xml.startsWith('<?xml version="1.0" encoding="UTF-8"?>'))
  assert.ok(xml.includes('xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'))
  assert.ok(!xml.includes('<lastmod>'))
})


test('disabled product pages are omitted from the sitemap while pricing stays public', () => {
  const xml = sitemapXml({ transcription: true, translation: false, video_dubbing: false, audio_dubbing: false, subtitles: false })
  assert.ok(xml.includes('/audio-to-text</loc>'))
  assert.ok(xml.includes('/video-to-text</loc>'))
  assert.ok(xml.includes('/pricing</loc>'))
  for (const path of ['video-dubbing', 'audio-dubbing', 'add-subtitles-to-video', 'text-translation']) assert.ok(!xml.includes(`/${path}</loc>`))
})

test('www canonical redirects preserve paths and queries and ignore unrelated hosts', () => {
  assert.equal(canonicalHostRedirect('https://www.teescriber.com/audio-to-text?utm_source=test'), 'https://teescriber.com/audio-to-text?utm_source=test')
  assert.equal(canonicalHostRedirect('https://teescriber.com/'), null)
  assert.equal(canonicalHostRedirect('https://www.teescriber.com.attacker.example/'), null)
})


test('homepage metadata reflects enabled tools without advertising disabled activities', () => {
  const page = publicSeoPage('/', { transcription: true, translation: false, video_dubbing: false, audio_dubbing: false, subtitles: false })
  assert.equal(page.title, 'Audio Transcription | TeeScriber')
  assert.ok(!page.description.includes('Dubbing'))
})
