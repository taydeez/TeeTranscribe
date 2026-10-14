import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import ts from 'typescript'

const source = await readFile(new URL('../../shared/utils/siteSeo.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { canonicalPageUrl, isProductionSeoHost, publicSeoPage, sitemapXml } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

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
  assert.deepEqual(urls, ['https://teescriber.com/', 'https://teescriber.com/about', 'https://teescriber.com/faq', 'https://teescriber.com/contact', 'https://teescriber.com/privacy-policy'])
  assert.ok(xml.startsWith('<?xml version="1.0" encoding="UTF-8"?>'))
  assert.ok(xml.includes('xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'))
  assert.ok(!xml.includes('<lastmod>'))
})
