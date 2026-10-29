import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import ts from 'typescript'

const source = await readFile(new URL('../../server/utils/backendFailure.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { describeBackendFailure } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

test('HTML access denials are identified without logging response bodies or URL credentials', () => {
  const failure = describeBackendFailure({ response: { status: 403, headers: new Headers({ 'content-type': 'text/html', server: 'cloudflare', 'cf-ray': 'test-ray' }) }, data: '<html>private upstream content</html>' }, 'https://user:secret@api.example.com/api/v1?key=private', 'auth/login')
  assert.equal(failure.status, 403)
  assert.equal(failure.shouldLog, true)
  assert.equal(failure.diagnostics.reason, 'upstream_html_response')
  assert.equal(failure.diagnostics.backend_origin, 'https://api.example.com')
  assert.equal(failure.diagnostics.cloudflare_ray, 'test-ray')
  assert.ok(!JSON.stringify(failure).includes('secret'))
  assert.ok(!JSON.stringify(failure).includes('private'))
})

test('Cloudflare challenge responses are distinguished from application rejection', () => {
  const failure = describeBackendFailure({ statusCode: 403, response: { headers: new Headers({ 'cf-mitigated': 'challenge' }) } }, 'https://api.example.com/api/v1', 'auth/login')
  assert.equal(failure.diagnostics.reason, 'cloudflare_challenge')
  assert.match(failure.message, /Sign-in is temporarily unavailable/)
})

test('application authentication messages retain their status without noisy failure logs', () => {
  const failure = describeBackendFailure({ statusCode: 401, data: { message: 'Invalid credentials.' } }, 'https://api.example.com/api/v1', 'auth/login')
  assert.equal(failure.status, 401)
  assert.equal(failure.message, 'Invalid credentials.')
  assert.equal(failure.shouldLog, false)
})

test('connection errors and timeouts become gateway errors without exposing exception contents', () => {
  const connection = describeBackendFailure(new Error('sensitive diagnostic'), 'invalid URL', 'auth/login')
  assert.equal(connection.status, 502)
  assert.equal(connection.diagnostics.backend_origin, '[invalid API URL]')
  assert.ok(!connection.message.includes('sensitive'))
  const timeout = describeBackendFailure({ cause: { name: 'TimeoutError' } }, 'https://api.example.com', 'auth/admin/login')
  assert.equal(timeout.status, 504)
  assert.equal(timeout.diagnostics.reason, 'upstream_timeout')
})
