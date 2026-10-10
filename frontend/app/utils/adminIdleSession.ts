export const ADMIN_IDLE_MS = 5 * 60 * 1000
const HEARTBEAT_MS = 15_000

type IdleOptions = {
  now: () => number
  heartbeat: () => Promise<unknown>
  expire: () => void
  initialActivity?: number
}

export function createAdminIdleSession(options: IdleOptions) {
  let lastActivity = options.initialActivity ?? options.now()
  let lastHeartbeat = Number.NEGATIVE_INFINITY
  let dirty = false
  let stopped = false
  let pending = false

  function expired() {
    if (stopped) return true
    if (options.now() - lastActivity < ADMIN_IDLE_MS) return false
    stopped = true
    options.expire()
    return true
  }

  async function tick() {
    if (expired() || !dirty || pending || options.now() - lastHeartbeat < HEARTBEAT_MS) return
    dirty = false
    pending = true
    lastHeartbeat = options.now()
    try { await options.heartbeat() }
    catch { dirty = true }
    finally { pending = false }
  }

  function interact() {
    if (expired()) return
    lastActivity = options.now()
    dirty = true
    void tick()
  }

  function synchronize(at: number) {
    if (!stopped && at > lastActivity && at <= options.now()) lastActivity = at
  }

  return { interact, synchronize, tick, stop: () => { stopped = true } }
}
