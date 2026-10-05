import type { PartTicket } from '~/types/upload'

export function sendUploadPart(ticket: PartTicket, body: Blob, signal: AbortSignal, progress: (loaded: number) => void): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    const abort = () => xhr.abort()
    const cleanup = () => signal.removeEventListener('abort', abort)
    xhr.open('PUT', ticket.upload_url)
    xhr.timeout = 10 * 60 * 1000
    Object.entries(ticket.headers).forEach(([name, value]) => xhr.setRequestHeader(name, value))
    xhr.upload.onprogress = event => progress(event.loaded)
    xhr.onload = () => {
      cleanup()
      if (xhr.status >= 200 && xhr.status < 300) resolve()
      else reject(new Error(`Storage rejected this part (${xhr.status}). Retrying is safe.`))
    }
    xhr.onerror = () => { cleanup(); reject(new Error('Connection interrupted. Uploaded parts are saved.')) }
    xhr.ontimeout = () => { cleanup(); reject(new Error('This part timed out. Uploaded parts are saved.')) }
    xhr.onabort = () => { cleanup(); reject(new DOMException('Upload paused.', 'AbortError')) }
    signal.addEventListener('abort', abort, { once: true })
    if (signal.aborted) { cleanup(); reject(new DOMException('Upload paused.', 'AbortError')); return }
    xhr.send(body)
  })
}

export function uploadDelay(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const abort = () => { clearTimeout(timer); reject(new DOMException('Upload paused.', 'AbortError')) }
    const timer = setTimeout(() => { signal.removeEventListener('abort', abort); resolve() }, ms)
    signal.addEventListener('abort', abort, { once: true })
    if (signal.aborted) { signal.removeEventListener('abort', abort); abort() }
  })
}
