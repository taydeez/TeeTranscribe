import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import ts from 'typescript'

const source = await readFile(new URL('../../app/utils/adminIdleSession.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { createAdminIdleSession, ADMIN_IDLE_MS } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

function setup(heartbeat) {
  let now = 0, expired = 0, requests = 0
  const session = createAdminIdleSession({ now: () => now, expire: () => { expired++ },
    heartbeat: async () => { requests++; await heartbeat?.() } })
  return { session, advance: ms => { now += ms }, expired: () => expired, requests: () => requests }
}

test('polling never counts as activity and expires at five minutes once', async () => {
  const h = setup()
  h.advance(ADMIN_IDLE_MS - 1)
  await h.session.tick()
  assert.equal(h.expired(), 0)
  assert.equal(h.requests(), 0)
  h.advance(1)
  await h.session.tick()
  await h.session.tick()
  h.session.interact()
  assert.equal(h.expired(), 1)
  assert.equal(h.requests(), 0)
})

test('interaction renews idle time and heartbeat calls are throttled', async () => {
  const h = setup()
  h.session.interact()
  await h.session.tick()
  for (let i = 0; i < 20; i++) h.session.interact()
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(h.requests(), 1)
  h.advance(15_000)
  await h.session.tick()
  assert.equal(h.requests(), 2)
  h.advance(60_000)
  await h.session.tick()
  assert.equal(h.requests(), 2)
  h.session.interact()
  await h.session.tick()
  assert.equal(h.requests(), 3)
  h.advance(ADMIN_IDLE_MS)
  await h.session.tick()
  assert.equal(h.expired(), 1)
})

test('sleeping tabs and reloaded sessions cannot revive an expired session with interaction', async () => {
  let expired = 0, requests = 0
  const session = createAdminIdleSession({ now: () => ADMIN_IDLE_MS + 1, initialActivity: 0,
    expire: () => { expired++ }, heartbeat: async () => { requests++ } })
  session.interact()
  await session.tick()
  assert.equal(expired, 1)
  assert.equal(requests, 0)
})

test('activity in another tab keeps this tab open without sending background heartbeats', async () => {
  const h = setup()
  h.advance(ADMIN_IDLE_MS - 1)
  h.session.synchronize(ADMIN_IDLE_MS - 1)
  h.advance(1)
  await h.session.tick()
  assert.equal(h.expired(), 0)
  assert.equal(h.requests(), 0)
  h.advance(ADMIN_IDLE_MS)
  await h.session.tick()
  assert.equal(h.expired(), 1)
})

test('stopping the session removes timers from its behavior and heartbeat failures can retry', async () => {
  let failing = true
  const h = setup(async () => { if (failing) throw new Error('offline') })
  h.session.interact()
  await h.session.tick()
  await Promise.resolve()
  failing = false
  h.advance(15_000)
  await h.session.tick()
  assert.equal(h.requests(), 2)
  h.session.stop()
  h.advance(ADMIN_IDLE_MS)
  h.session.interact()
  await h.session.tick()
  assert.equal(h.requests(), 2)
  assert.equal(h.expired(), 0)
})
