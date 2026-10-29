import type { FolderDetails } from '~/types/folder'

export function useFolderTranscriptions(folderId: () => string) {
  const folder = ref<FolderDetails | null>(null)
  const loading = ref(true)
  const error = ref('')
  let timer: ReturnType<typeof setTimeout> | undefined
  let controller: AbortController | undefined
  let mounted = false
  let disposed = false

  const hasWorkingItems = () => Boolean(folder.value?.transcriptions.some(item => ['pending', 'processing'].includes(item.status))
    || folder.value?.projects?.some(item => ['pending', 'processing'].includes(item.status)))

  function schedule() {
    if (disposed || !mounted || timer !== undefined) return
    if (hasWorkingItems()) {
      timer = setTimeout(() => { timer = undefined; void load(true) }, 15_000)
    }
  }

  function stop() {
    clearTimeout(timer)
    timer = undefined
    controller?.abort()
  }

  async function load(background = false) {
    clearTimeout(timer)
    timer = undefined
    const request = new AbortController()
    controller?.abort()
    controller = request
    if (!background) loading.value = true
    error.value = ''
    try {
      const result = await useAuthenticatedFetch<FolderDetails>(`/api/folders/${encodeURIComponent(folderId())}`, { signal: request.signal })
      if (!disposed && !request.signal.aborted) folder.value = result
    } catch (failure: unknown) {
      if (!disposed && !request.signal.aborted) {
        const response = failure as { data?: { message?: string } }
        error.value = response.data?.message ?? 'Could not load this folder.'
      }
    } finally {
      if (!disposed && !request.signal.aborted) {
        loading.value = false
        schedule()
      }
    }
  }

  onMounted(() => { mounted = true; void load() })
  watch(hasWorkingItems, (active) => {
    if (active) schedule()
    else { clearTimeout(timer); timer = undefined }
  })
  watch(folderId, () => {
    stop()
    folder.value = null
    if (mounted && !disposed) void load()
  })
  onBeforeUnmount(() => { disposed = true; stop() })
  return { folder, loading, error, load }
}
