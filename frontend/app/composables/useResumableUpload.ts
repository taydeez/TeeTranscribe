import type { CompletedUpload, MultipartSession, MultipartStatus, PartTicket, SavedUpload } from '~/types/upload'
import { fileFingerprint, forgetUpload, savedUploads, saveUpload } from '~/utils/uploadState'
import { sendUploadPart, uploadDelay } from '~/utils/uploadPart'

export function useResumableUpload() {
  const auth = useAuthStore()
  const progress = ref(0)
  const paused = ref(false)
  const active = ref(false)
  const cancelling = ref(false)
  const finishing = ref(false)
  const notice = ref('')
  const unfinished = ref<SavedUpload[]>([])
  let current: SavedUpload | null = null
  let controller: AbortController | null = null

  async function refreshSaved() {
    if (!auth.user) { unfinished.value = []; return }
    try { unfinished.value = await savedUploads(auth.user.id) }
    catch { notice.value = 'Browser storage is unavailable. Resumable uploads require browser storage.' }
  }

  async function request<T>(path: string, body: Record<string, unknown> = {}, signal?: AbortSignal): Promise<T> {
    return useAuthenticatedFetch<T>(`/api/uploads/multipart${path}`, { method: 'POST', body, retry: 0, signal })
  }

  async function persist(record: SavedUpload) {
    record.updatedAt = Date.now()
    await saveUpload(record)
    current = record
    await refreshSaved()
  }

  function pause() {
    paused.value = true
    notice.value = 'Paused. Select the same file after a refresh to resume.'
    controller?.abort()
  }

  async function cancel() {
    if (!current || active.value || cancelling.value) return
    const record = current
    cancelling.value = true
    try {
      if (!record.sessionId) {
        const recovered = await request<MultipartSession>('', {
          client_key: record.clientKey, filename: record.filename, size: record.size,
          content_type: record.contentType, fingerprint: record.fingerprint,
        })
        record.sessionId = recovered.id
      }
      if (record.sessionId) await request(`/${record.sessionId}/abort`)
      await forgetUpload(record.key)
      current = null
      paused.value = false
      progress.value = 0
      notice.value = 'Upload cancelled.'
      await refreshSaved()
    } catch (failure: unknown) {
      const issue = failure as { statusCode?: number; status?: number }
      if ((issue.statusCode ?? issue.status) !== 410) throw failure
      await forgetUpload(record.key)
      current = null
      paused.value = false
      progress.value = 0
      notice.value = 'Expired upload removed.'
      await refreshSaved()
    } finally { cancelling.value = false }
  }

  async function discard(record: SavedUpload) {
    if (active.value || cancelling.value) return
    current = record
    await cancel()
  }

  async function upload(file: File, contentType: string): Promise<CompletedUpload> {
    if (!auth.user || active.value || cancelling.value) throw new Error('Sign in and wait for the current upload operation to finish.')
    active.value = true
    paused.value = false
    finishing.value = false
    notice.value = 'Checking saved upload…'
    controller = new AbortController()
    const signal = controller.signal
    try {
      const userId = auth.user.id
      const fingerprint = await fileFingerprint(file)
      if (signal.aborted) throw new DOMException('Upload paused.', 'AbortError')
      const records = await savedUploads(userId)
      let record = records.find(item => item.fingerprint === fingerprint)
      if (!record) record = {
        key: `${userId}:${fingerprint}`, userId, fingerprint, filename: file.name,
        contentType, size: file.size, clientKey: crypto.randomUUID(), updatedAt: Date.now(),
      }
      await persist(record)
      const session = await request<MultipartSession>('', {
        client_key: record.clientKey, filename: file.name, size: file.size, content_type: contentType, fingerprint,
      }, signal)
      record.sessionId = session.id
      record.expiresAt = session.expires_at
      await persist(record)
      const status = await request<MultipartStatus>(`/${session.id}/status`, {}, signal)
      const validParts = new Set<number>()
      let confirmed = 0
      for (const part of status.parts) {
        const expected = Math.min(session.part_size, file.size - (part.number - 1) * session.part_size)
        if (part.number >= 1 && part.number <= session.part_count && part.size === expected && part.etag) {
          validParts.add(part.number)
          confirmed += part.size
        }
      }
      progress.value = status.status === 'completed' ? 100 : Math.floor(confirmed / file.size * 100)
      notice.value = confirmed ? 'Resuming from uploaded parts…' : 'Uploading directly to storage…'
      if (status.status !== 'completed') {
        for (let number = 1; number <= session.part_count; number++) {
          if (validParts.has(number)) continue
          const body = file.slice((number - 1) * session.part_size, Math.min(number * session.part_size, file.size))
          for (let attempt = 0; ; attempt++) {
            if (signal.aborted) throw new DOMException('Upload paused.', 'AbortError')
            try {
              const ticket = await request<PartTicket>(`/${session.id}/part`, { part_number: number }, signal)
              await sendUploadPart(ticket, body, signal, (loaded) => {
                progress.value = Math.min(99, Math.floor((confirmed + loaded) / file.size * 100))
              })
              break
            } catch (failure: unknown) {
              progress.value = Math.floor(confirmed / file.size * 100)
              const issue = failure as { statusCode?: number; status?: number }
              const code = issue.statusCode ?? issue.status
              if (signal.aborted || attempt >= 3 || [401, 403, 404, 410, 422].includes(code ?? 0)) throw failure
              notice.value = `Retrying part ${number} (${attempt + 1}/3)…`
              await uploadDelay(1000 * 2 ** attempt, signal)
            }
          }
          confirmed += body.size
          progress.value = Math.floor(confirmed / file.size * 100)
          notice.value = `Uploaded ${number} of ${session.part_count} parts.`
        }
      }
      if (signal.aborted) throw new DOMException('Upload paused.', 'AbortError')
      finishing.value = true
      notice.value = 'Verifying the uploaded file…'
      const result = await request<CompletedUpload>(`/${session.id}/complete`, {}, signal)
      progress.value = 100
      notice.value = 'Upload complete.'
      return result
    } catch (failure: unknown) {
      if (signal.aborted) {
        paused.value = true
        throw new DOMException('Upload paused. Your completed parts are saved.', 'AbortError')
      }
      const issue = failure as { statusCode?: number; status?: number }
      if ((issue.statusCode ?? issue.status) === 410 && current) {
        await forgetUpload(current.key)
        current = null
        await refreshSaved()
        notice.value = 'The saved upload expired. Submit again to start a new upload.'
        throw new Error(notice.value, { cause: failure })
      }
      notice.value = 'Upload progress saved. Submit again to resume.'
      throw failure
    } finally {
      active.value = false
      finishing.value = false
      controller = null
    }
  }

  async function acknowledge() {
    if (current) {
      await forgetUpload(current.key)
      current = null
      await refreshSaved()
    }
    notice.value = ''
  }

  function offline() { if (active.value) pause() }
  function unload(event: BeforeUnloadEvent) {
    if (active.value) { event.preventDefault(); event.returnValue = '' }
  }
  onMounted(() => {
    refreshSaved()
    window.addEventListener('offline', offline)
    window.addEventListener('beforeunload', unload)
  })
  onBeforeUnmount(() => {
    controller?.abort()
    window.removeEventListener('offline', offline)
    window.removeEventListener('beforeunload', unload)
  })
  watch(() => auth.user?.id, () => {
    controller?.abort()
    current = null
    unfinished.value = []
    refreshSaved()
  })

  return { progress, paused, active, finishing, cancelling, notice, unfinished, upload, pause, cancel, discard, acknowledge }
}
