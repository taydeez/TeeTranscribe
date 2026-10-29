import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import ts from 'typescript'
const source = await readFile(new URL('../../server/utils/clientIp.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { clientIp } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)
test('signup proxy ignores forwarding headers from an untrusted direct connection', t => {
  const originals = { useRuntimeConfig: globalThis.useRuntimeConfig, getRequestIP: globalThis.getRequestIP }
  t.after(() => Object.assign(globalThis, originals))
  globalThis.useRuntimeConfig = () => ({ trustedProxyIps: '' })
  globalThis.getRequestIP = (_event, options) => options?.xForwardedFor ? '1.1.1.1' : '8.8.8.8'
  assert.equal(clientIp({}), '8.8.8.8')
})
test('signup proxy accepts client IP from explicitly configured reverse proxies', t => {
  const originals = { useRuntimeConfig: globalThis.useRuntimeConfig, getRequestIP: globalThis.getRequestIP }
  t.after(() => Object.assign(globalThis, originals))
  globalThis.useRuntimeConfig = () => ({ trustedProxyIps: '10.0.0.2' })
  globalThis.getRequestIP = (_event, options) => options?.xForwardedFor ? '8.8.8.8' : '10.0.0.2'
  assert.equal(clientIp({}), '8.8.8.8')
})
