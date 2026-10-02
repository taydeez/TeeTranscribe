<script setup lang="ts">
const emit = defineEmits<{ logout: []; newTranscription: []; folderCreated: [] }>()
const active = ref('Overview')
const menuOpen = ref(false)
type Folder = { id: string; name: string; transcriptionIds: string[]; createdAt: string | null }
type FolderPage = { data: Folder[]; meta: { currentPage: number; lastPage: number; perPage: number; total: number } }
type TranscriptionExport = { id: string; format: 'pdf' | 'txt'; status: 'pending' | 'failed' | 'completed'; downloadUrl: string | null }
type FolderTranscription = { id: string; name: string; fileName: string; status: string; transcript: string | null; duration: number | null; createdAt: string | null; exports: TranscriptionExport[] }
type FolderDetails = Folder & { transcriptions: FolderTranscription[] }
const folders = ref<Folder[]>([])
const folderPage = ref({ currentPage: 1, lastPage: 1, perPage: 10, total: 0 })
const folderSearch = ref('')
const folderSort = ref('created_at:desc')
const foldersLoading = ref(false)
const foldersLoaded = ref(false)
const foldersError = ref('')
const createFolderOpen = ref(false)
const folderName = ref('')
const creatingFolder = ref(false)
const selectedFolder = ref<FolderDetails | null>(null)
const selectedTranscription = ref<FolderTranscription | null>(null)
const transcriptDraft = ref('')
const transcriptSaving = ref(false)
const transcriptError = ref('')
const transcriptSaved = ref(false)
const folderDetailsLoading = ref(false)

const menu = [
  { label: 'Overview', mark: '⌂' },
  { label: 'My Transcriptions', mark: '≡' },
  { label: 'Translations', mark: '文' },
  { label: 'Credits and billing', mark: '◎' },
  { label: 'Settings', mark: '⚙' },
]

function choose(label: string) {
  active.value = label
  menuOpen.value = false
  if (label === 'My Transcriptions') {
    selectedFolder.value = null
    selectedTranscription.value = null
    if (!foldersLoaded.value) loadFolders()
  }
}

async function loadFolders(page = 1) {
  foldersLoading.value = true
  foldersError.value = ''
  try {
    const [sort, direction] = folderSort.value.split(':')
    const response = await useAuthenticatedFetch<FolderPage>('/api/folders', {
      query: { page, per_page: folderPage.value.perPage, search: folderSearch.value || undefined, sort, direction },
    })
    folders.value = response.data
    folderPage.value = response.meta
    foldersLoaded.value = true
  } catch (failure: any) {
    foldersError.value = failure.data?.message ?? 'Could not load your folders.'
  } finally {
    foldersLoading.value = false
  }
}

async function createFolder() {
  const name = folderName.value.trim()
  if (!name || creatingFolder.value) return
  creatingFolder.value = true
  foldersError.value = ''
  try {
    await useAuthenticatedFetch<Folder>('/api/folders', {
      method: 'POST',
      body: { name },
    })
    folderName.value = ''
    createFolderOpen.value = false
    await loadFolders(1)
    emit('folderCreated')
  } catch (failure: any) {
    foldersError.value = failure.data?.message ?? 'Could not create the folder.'
  } finally {
    creatingFolder.value = false
  }
}

async function openFolder(folder: Folder) {
  folderDetailsLoading.value = true
  foldersError.value = ''
  selectedTranscription.value = null
  try {
    selectedFolder.value = await useAuthenticatedFetch<FolderDetails>(`/api/folders/${folder.id}`)
  } catch (failure: any) {
    foldersError.value = failure.data?.message ?? 'Could not load this folder.'
  } finally {
    folderDetailsLoading.value = false
  }
}

function closeFolder() {
  selectedFolder.value = null
  selectedTranscription.value = null
}

function openTranscription(transcription: FolderTranscription) {
  selectedTranscription.value = transcription
  transcriptDraft.value = transcription.transcript ?? ''
  transcriptError.value = ''
  transcriptSaved.value = false
}

function closeTranscription() {
  selectedTranscription.value = null
  transcriptDraft.value = ''
  transcriptError.value = ''
  transcriptSaved.value = false
}

async function saveTranscript() {
  const transcription = selectedTranscription.value
  const transcript = transcriptDraft.value.trim()
  if (!transcription || !transcript || transcriptSaving.value || transcript === transcription.transcript) return

  transcriptSaving.value = true
  transcriptError.value = ''
  transcriptSaved.value = false
  try {
    const updated = await useAuthenticatedFetch<{ id: string; transcript: string; status: string }>(`/api/transcriptions/${transcription.id}`, {
      method: 'PATCH',
      body: { transcript },
    })
    transcription.transcript = updated.transcript
    transcription.status = updated.status
    transcription.exports = transcription.exports.map(item => ({ ...item, status: 'pending', downloadUrl: null }))
    transcriptDraft.value = updated.transcript
    transcriptSaved.value = true
  } catch (failure: any) {
    transcriptError.value = failure.data?.message ?? 'Could not save this transcript.'
  } finally {
    transcriptSaving.value = false
  }
}

function formatDuration(value: number | null): string {
  if (value === null) return '—'
  const seconds = Math.max(0, Math.round(value))
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const remainder = seconds % 60
  return [hours ? `${hours}h` : '', minutes ? `${minutes}m` : '', `${remainder}s`].filter(Boolean).join(' ')
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(folderSearch, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => loadFolders(1), 300)
})
watch(folderSort, () => loadFolders(1))
onBeforeUnmount(() => clearTimeout(searchTimer))

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value))
}

async function startTranscription() {
  active.value = 'Overview'
  menuOpen.value = false
  await nextTick()
  document.getElementById('dashboard-transcribe-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  emit('newTranscription')
}
</script>

<template>
  <div class="min-h-dvh bg-[#f7f8fc] font-sans text-[#172033]">
    <div class="mx-auto flex min-h-dvh max-w-[1600px]">
      <aside class="fixed inset-y-0 left-0 z-40 flex w-[272px] -translate-x-full flex-col border-r border-[#e6e8f0] bg-white/95 px-5 py-6 shadow-2xl shadow-slate-900/5 backdrop-blur-xl transition-transform duration-300 lg:sticky lg:translate-x-0 lg:shadow-none" :class="menuOpen && 'translate-x-0'">
        <a class="flex items-center gap-3 px-2" href="/">
          <span class="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-xl font-extrabold text-white shadow-lg shadow-indigo-500/20">t.</span>
          <span class="text-lg font-extrabold tracking-[-.04em] text-slate-900">TeeTranscribe</span>
        </a>
        <nav class="mt-14 grid gap-1" aria-label="Dashboard navigation">
          <button v-for="item in menu" :key="item.label" type="button" class="group flex min-h-11 items-center gap-3 rounded-xl px-3 text-left text-sm transition duration-200" :class="active === item.label ? 'bg-indigo-50 font-bold text-indigo-700' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'" @click="choose(item.label)">
            <span class="grid size-8 place-items-center rounded-lg text-sm transition" :class="active === item.label ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-400 group-hover:bg-white'">{{ item.mark }}</span>
            {{ item.label }}
          </button>
        </nav>
        <div class="mt-auto rounded-2xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-cyan-50 p-4">
          <div class="flex items-center justify-between"><p class="text-[10px] font-extrabold tracking-[.16em] text-indigo-700">MONTHLY CREDITS</p><span class="rounded-full bg-white px-2 py-1 text-[9px] font-bold text-indigo-600 shadow-sm">FREE</span></div>
          <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-white"><div class="h-full w-[18%] rounded-full bg-gradient-to-r from-indigo-500 to-cyan-400" /></div>
          <p class="mt-3 text-xs text-slate-600">250 credits available</p>
        </div>
        <button class="mt-3 flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-semibold text-slate-500 transition hover:bg-rose-50 hover:text-rose-600" type="button" @click="emit('logout')"><span>↪</span> Sign out</button>
      </aside>

      <button v-if="menuOpen" class="fixed inset-0 z-30 bg-slate-950/35 backdrop-blur-sm lg:hidden" aria-label="Close menu" @click="menuOpen = false" />

      <main class="min-w-0 flex-1 px-5 pb-16 pt-5 sm:px-8 lg:px-12 lg:pt-8 xl:px-16">
        <header class="flex items-center justify-between border-b border-[#e6e8f0] pb-5">
          <div class="flex items-center gap-3">
            <button class="grid size-11 place-items-center rounded-xl border border-slate-200 bg-white shadow-sm lg:hidden" type="button" aria-label="Open menu" @click="menuOpen = true">☰</button>
            <div><p class="text-[10px] font-extrabold tracking-[.18em] text-indigo-600">WORKSPACE</p><h1 class="text-2xl font-extrabold tracking-[-.035em] text-slate-900 sm:text-3xl">{{ active }}</h1></div>
          </div>
          <button class="group flex min-h-11 items-center gap-3 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 py-2 pl-4 pr-2 text-sm font-bold text-white shadow-lg shadow-indigo-500/20 transition duration-200 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-indigo-500/25" type="button" @click="startTranscription">
            <span class="hidden sm:inline">New transcription</span><span class="grid size-8 place-items-center rounded-full bg-white/15 text-xl">+</span>
          </button>
        </header>

        <template v-if="active === 'Overview'">
        <section class="dashboard-enter relative mt-8 overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 px-6 py-8 text-white shadow-xl shadow-indigo-500/15 sm:px-9 lg:flex lg:items-end lg:justify-between lg:px-10 lg:py-9">
          <div class="absolute -right-16 -top-24 size-64 rounded-full bg-cyan-300/30 blur-3xl" />
          <div class="relative"><p class="text-[10px] font-extrabold tracking-[.2em] text-indigo-100">WELCOME BACK</p><h2 class="mt-3 max-w-xl text-3xl font-extrabold leading-[1.08] tracking-[-.045em] sm:text-5xl">Turn every recording<br><span class="text-cyan-200">into useful text.</span></h2></div>
          <div class="relative mt-8 grid max-w-sm grid-cols-3 gap-2 text-center text-[10px] font-bold text-indigo-50 lg:mt-0"><span class="rounded-xl bg-white/10 px-3 py-3 backdrop-blur">1. Add audio</span><span class="rounded-xl bg-white/10 px-3 py-3 backdrop-blur">2. Transcribe</span><span class="rounded-xl bg-white/10 px-3 py-3 backdrop-blur">3. Export</span></div>
        </section>

        <section id="dashboard-transcribe-form" class="dashboard-card mt-5" style="animation-delay: 80ms">
          <slot name="transcription-form" />
        </section>

        <section class="mt-9 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <button v-for="(card, index) in [
            ['My Transcriptions', 'No transcripts yet', 'Your completed recordings will appear here.', '01'],
            ['Translations', 'Ready when you are', 'Translations of your transcripts will live here.', '02'],
            ['Credits and billing', '250 credits left', 'Your current monthly credit balance.', '03'],
            ['Settings', 'Workspace preferences', 'Manage your profile and defaults.', '04'],
          ]" :key="card[0]" type="button" class="dashboard-card group min-h-48 rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition duration-200 hover:-translate-y-1 hover:border-indigo-200 hover:shadow-xl hover:shadow-indigo-950/5" :style="{ animationDelay: `${120 + index * 70}ms` }" @click="choose(card[0])">
            <div class="flex items-center justify-between"><span class="grid size-9 place-items-center rounded-xl bg-indigo-50 text-xs font-extrabold text-indigo-600">{{ card[3] }}</span><span class="text-indigo-400 transition group-hover:translate-x-1">↗</span></div>
            <h3 class="mt-7 text-sm font-bold text-slate-900">{{ card[0] }}</h3><p class="mt-2 text-lg font-bold tracking-tight text-slate-700">{{ card[1] }}</p><p class="mt-2 text-xs leading-5 text-slate-500">{{ card[2] }}</p>
          </button>
        </section>

        </template>

        <section v-else-if="active === 'My Transcriptions'" class="dashboard-enter mt-8 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div class="flex flex-col gap-5 border-b border-slate-200 bg-gradient-to-r from-white to-indigo-50/60 p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8">
            <div class="flex items-center gap-4">
              <button v-if="selectedFolder" class="grid size-11 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-lg text-slate-600 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700" type="button" aria-label="Back to folders" @click="closeFolder">←</button>
              <div><p v-if="selectedFolder" class="text-[10px] font-extrabold tracking-[.16em] text-indigo-600">FOLDER</p><h2 class="text-3xl font-extrabold tracking-[-.04em] text-slate-900 sm:text-4xl">{{ selectedFolder?.name ?? 'Transcriptions' }}</h2></div>
            </div>
            <button v-if="!selectedFolder" class="flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-bold text-white shadow-lg shadow-indigo-500/20 transition hover:-translate-y-0.5 hover:bg-indigo-700 sm:w-auto" type="button" @click="createFolderOpen = true">
              <span class="text-lg leading-none">+</span> Create folder
            </button>
          </div>

          <div v-if="!selectedFolder" class="grid gap-3 border-b border-slate-200 p-5 sm:grid-cols-[minmax(0,1fr)_220px] sm:p-6">
            <label class="relative block">
              <span class="sr-only">Search folders</span>
              <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-slate-400">⌕</span>
              <input v-model="folderSearch" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-11 pr-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10" type="search" placeholder="Search folders by name">
            </label>
            <label>
              <span class="sr-only">Sort folders</span>
              <select v-model="folderSort" class="min-h-11 w-full max-w-none rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10">
                <option value="created_at:desc">Newest first</option>
                <option value="created_at:asc">Oldest first</option>
                <option value="name:asc">Name A–Z</option>
                <option value="name:desc">Name Z–A</option>
              </select>
            </label>
          </div>

          <p v-if="foldersError" class="mx-6 mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 sm:mx-8" role="alert">{{ foldersError }}</p>

          <div v-if="folderDetailsLoading" class="grid min-h-64 place-items-center px-6 text-sm text-slate-500">
            <span class="folder-loader">Loading transcriptions…</span>
          </div>

          <template v-else-if="selectedFolder">
            <div v-if="selectedFolder.transcriptions.length" class="divide-y divide-slate-100 sm:hidden">
              <button v-for="transcription in selectedFolder.transcriptions" :key="transcription.id" class="flex w-full items-start gap-3 p-5 text-left transition hover:bg-indigo-50/40" type="button" @click="openTranscription(transcription)">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-violet-50 text-lg text-violet-600">≡</span>
                <span class="min-w-0 flex-1"><strong class="block truncate text-sm text-slate-900">{{ transcription.name }}</strong><small class="mt-1 block truncate text-xs text-slate-500">{{ transcription.fileName }} · {{ formatDuration(transcription.duration) }}</small></span>
                <span class="text-xs capitalize text-slate-400">{{ transcription.status }}</span>
              </button>
            </div>

            <div v-if="selectedFolder.transcriptions.length" class="hidden overflow-x-auto sm:block">
              <table class="w-full min-w-[720px] border-collapse text-left">
                <thead><tr class="border-b border-slate-200 bg-slate-50/80 text-[10px] font-extrabold tracking-[.14em] text-slate-500"><th class="px-8 py-4">TRANSCRIPTION</th><th class="px-6 py-4">STATUS</th><th class="px-6 py-4">LENGTH</th><th class="px-8 py-4 text-right">CREATED</th></tr></thead>
                <tbody>
                  <tr v-for="transcription in selectedFolder.transcriptions" :key="transcription.id" class="group cursor-pointer border-b border-slate-100 transition last:border-0 hover:bg-indigo-50/40" tabindex="0" @click="openTranscription(transcription)" @keydown.enter="openTranscription(transcription)">
                    <td class="px-8 py-5"><div class="flex items-center gap-3"><span class="grid size-10 place-items-center rounded-xl bg-violet-50 text-violet-600">≡</span><div class="min-w-0"><p class="truncate text-sm font-bold text-slate-800">{{ transcription.name }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ transcription.fileName }}</p></div></div></td>
                    <td class="px-6 py-5"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-600">{{ transcription.status.replaceAll('_', ' ') }}</span></td>
                    <td class="px-6 py-5 text-sm text-slate-600">{{ formatDuration(transcription.duration) }}</td>
                    <td class="px-8 py-5 text-right text-sm text-slate-500">{{ formatDate(transcription.createdAt) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-else class="grid min-h-72 place-items-center px-6 py-12 text-center"><div><span class="mx-auto grid size-14 place-items-center rounded-2xl bg-violet-50 text-2xl text-violet-600">≡</span><h3 class="mt-5 text-2xl font-extrabold text-slate-900">This folder is empty</h3><p class="mt-2 text-sm text-slate-500">Choose this folder when starting a transcription.</p></div></div>
          </template>

          <div v-else-if="foldersLoading" class="grid min-h-64 place-items-center px-6 text-sm text-slate-500">
            <span class="folder-loader">Loading folders…</span>
          </div>

          <template v-else-if="folders.length">
          <div class="divide-y divide-slate-100 sm:hidden">
            <button v-for="folder in folders" :key="folder.id" class="block w-full p-5 text-left transition hover:bg-indigo-50/40" type="button" @click="openFolder(folder)">
              <div class="flex items-start gap-3"><span class="grid size-11 shrink-0 place-items-center rounded-xl bg-indigo-50 text-lg text-indigo-600">⌑</span><div class="min-w-0 flex-1"><h3 class="truncate text-sm font-bold text-slate-900">{{ folder.name }}</h3><p class="mt-1 text-xs text-slate-500">{{ folder.transcriptionIds.length }} transcriptions</p></div><span class="text-xs text-slate-400">{{ formatDate(folder.createdAt) }}</span></div>
            </button>
          </div>

          <div class="hidden overflow-x-auto sm:block">
            <table class="w-full min-w-[620px] border-collapse text-left">
              <thead>
                <tr class="border-b border-slate-200 bg-slate-50/80 text-[10px] font-extrabold tracking-[.14em] text-slate-500">
                  <th class="px-8 py-4">FOLDER</th>
                  <th class="px-6 py-4">TRANSCRIPTIONS</th>
                  <th class="px-8 py-4 text-right">CREATED</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="folder in folders" :key="folder.id" class="group cursor-pointer border-b border-slate-100 transition last:border-0 hover:bg-indigo-50/40" tabindex="0" @click="openFolder(folder)" @keydown.enter="openFolder(folder)">
                  <td class="px-8 py-5">
                    <div class="flex items-center gap-3">
                      <span class="grid size-10 place-items-center rounded-xl bg-indigo-50 text-lg text-indigo-600 transition group-hover:bg-indigo-100">⌑</span>
                      <p class="text-sm font-bold text-slate-800">{{ folder.name }}</p>
                    </div>
                  </td>
                  <td class="px-6 py-5 text-sm text-slate-600">{{ folder.transcriptionIds.length }}</td>
                  <td class="px-8 py-5 text-right text-sm text-slate-500">{{ formatDate(folder.createdAt) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          </template>

          <div v-else class="grid min-h-72 place-items-center px-6 py-12 text-center">
            <div><span class="mx-auto grid size-14 place-items-center rounded-2xl bg-indigo-50 text-2xl text-indigo-600">⌑</span><h3 class="mt-5 text-2xl font-extrabold text-slate-900">{{ folderSearch ? 'No matching folders' : 'No folders yet' }}</h3><p class="mt-2 text-sm text-slate-500">{{ folderSearch ? 'Try another search term.' : 'Create your first folder to organize your transcriptions.' }}</p><button v-if="!folderSearch" class="mt-6 text-sm font-bold text-indigo-600 hover:text-violet-700" type="button" @click="createFolderOpen = true">Create a folder →</button></div>
          </div>

          <div v-if="!selectedFolder && folderPage.lastPage > 1" class="flex flex-col gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <p class="text-xs text-slate-500">Page {{ folderPage.currentPage }} of {{ folderPage.lastPage }} · {{ folderPage.total }} folders</p>
            <div class="flex gap-2">
              <button class="min-h-10 rounded-lg border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 transition hover:border-indigo-300 hover:text-indigo-700 disabled:cursor-not-allowed disabled:opacity-40" type="button" :disabled="folderPage.currentPage <= 1 || foldersLoading" @click="loadFolders(folderPage.currentPage - 1)">Previous</button>
              <button class="min-h-10 rounded-lg border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 transition hover:border-indigo-300 hover:text-indigo-700 disabled:cursor-not-allowed disabled:opacity-40" type="button" :disabled="folderPage.currentPage >= folderPage.lastPage || foldersLoading" @click="loadFolders(folderPage.currentPage + 1)">Next</button>
            </div>
          </div>
        </section>

        <section v-else class="dashboard-enter mt-8 rounded-3xl border border-slate-200 bg-white p-7 shadow-sm sm:p-10">
          <p class="text-[10px] font-extrabold tracking-[.2em] text-indigo-600">{{ active.toUpperCase() }}</p>
          <h2 class="mt-4 text-3xl font-extrabold tracking-[-.04em] text-slate-900 sm:text-4xl">{{ active }}</h2>
          <p class="mt-3 max-w-xl text-sm leading-6 text-slate-500">This workspace will be available here as TeeTranscribe grows.</p>
        </section>
      </main>
    </div>

    <Teleport to="body">
      <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
        <div v-if="createFolderOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/55 p-4 backdrop-blur-sm" @mousedown.self="createFolderOpen = false">
          <section class="w-full max-w-md rounded-3xl border border-white/70 bg-white p-7 shadow-2xl sm:p-8" role="dialog" aria-modal="true" aria-labelledby="create-folder-title">
            <div class="flex items-start justify-between gap-5"><div><span class="mb-4 grid size-11 place-items-center rounded-xl bg-indigo-50 text-xl text-indigo-600">⌑</span><p class="text-[10px] font-extrabold tracking-[.2em] text-indigo-600">NEW COLLECTION</p><h2 id="create-folder-title" class="mt-2 text-3xl font-extrabold tracking-[-.04em] text-slate-900">Create folder</h2></div><button class="grid size-10 place-items-center rounded-full border border-slate-200 text-lg text-slate-500 hover:bg-slate-50" type="button" aria-label="Close" @click="createFolderOpen = false">×</button></div>
            <form class="mt-7" @submit.prevent="createFolder">
              <label class="text-xs font-bold text-slate-700" for="folder-name">Folder name</label>
              <input id="folder-name" v-model="folderName" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10" maxlength="255" required autofocus placeholder="e.g. Client interviews">
              <p v-if="foldersError" class="mt-3 text-sm text-rose-600" role="alert">{{ foldersError }}</p>
              <div class="mt-6 flex justify-end gap-3"><button class="min-h-11 rounded-xl px-5 text-sm font-bold text-slate-600 hover:bg-slate-50" type="button" @click="createFolderOpen = false">Cancel</button><button class="min-h-11 rounded-xl bg-indigo-600 px-5 text-sm font-bold text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-700 disabled:opacity-50" :disabled="creatingFolder || !folderName.trim()">{{ creatingFolder ? 'Creating…' : 'Create folder' }}</button></div>
            </form>
          </section>
        </div>
      </Transition>
      <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
        <div v-if="selectedTranscription" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/55 p-4 backdrop-blur-sm" @mousedown.self="closeTranscription">
          <section class="max-h-[92dvh] w-full max-w-3xl overflow-y-auto rounded-3xl border border-white/70 bg-white p-7 shadow-2xl sm:p-8" role="dialog" aria-modal="true" aria-labelledby="transcription-download-title">
            <div class="flex items-start justify-between gap-5"><div class="min-w-0"><p class="text-[10px] font-extrabold tracking-[.2em] text-indigo-600">TRANSCRIPT</p><h2 id="transcription-download-title" class="mt-2 truncate text-3xl font-extrabold tracking-[-.04em] text-slate-900">{{ selectedTranscription.name }}</h2><p class="mt-2 truncate text-sm text-slate-500">{{ selectedTranscription.fileName }}</p></div><button class="grid size-10 shrink-0 place-items-center rounded-full border border-slate-200 text-lg text-slate-500 hover:bg-slate-50" type="button" aria-label="Close" @click="closeTranscription">×</button></div>
            <form class="mt-7" @submit.prevent="saveTranscript">
              <div class="flex items-end justify-between gap-4"><label class="text-xs font-bold text-slate-700" for="transcript-editor">Transcript text</label><span class="text-[11px] text-slate-400">{{ transcriptDraft.length.toLocaleString() }} characters</span></div>
              <textarea id="transcript-editor" v-model="transcriptDraft" class="mt-2 min-h-72 w-full resize-y rounded-2xl border border-slate-300 bg-slate-50/60 px-4 py-4 text-sm leading-7 text-slate-800 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-500/10" required spellcheck="true" />
              <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p v-if="transcriptError" class="text-sm text-rose-600" role="alert">{{ transcriptError }}</p>
                <p v-else-if="transcriptSaved" class="text-sm font-semibold text-emerald-600" role="status">Saved. New exports are being generated.</p>
                <p v-else class="text-xs leading-5 text-slate-400">Saving regenerates the TXT and PDF downloads.</p>
                <button class="min-h-11 shrink-0 rounded-xl bg-indigo-600 px-5 text-sm font-bold text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50" type="submit" :disabled="transcriptSaving || !transcriptDraft.trim() || transcriptDraft.trim() === selectedTranscription.transcript">{{ transcriptSaving ? 'Saving…' : 'Save changes' }}</button>
              </div>
            </form>
            <div class="mt-8 border-t border-slate-200 pt-6"><p class="text-[10px] font-extrabold tracking-[.2em] text-slate-500">EXPORTS</p></div>
            <div v-if="selectedTranscription.exports.length" class="mt-4 grid gap-3 sm:grid-cols-2">
              <template v-for="item in selectedTranscription.exports" :key="item.id">
                <a v-if="item.downloadUrl" class="flex min-h-24 items-center justify-between rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 transition hover:-translate-y-0.5 hover:border-indigo-300 hover:bg-indigo-50" :href="item.downloadUrl" target="_blank" rel="noopener noreferrer"><span><strong class="block text-sm uppercase text-slate-900">{{ item.format }}</strong><small class="mt-1 block text-xs text-slate-500">Ready to download</small></span><span class="grid size-9 place-items-center rounded-xl bg-white text-indigo-600 shadow-sm">↓</span></a>
                <div v-else class="flex min-h-24 items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 p-4"><span><strong class="block text-sm uppercase text-slate-700">{{ item.format }}</strong><small class="mt-1 block text-xs capitalize text-slate-500">{{ item.status }}</small></span><span class="text-slate-400">…</span></div>
              </template>
            </div>
            <div v-else class="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-5 py-8 text-center"><p class="text-sm font-bold text-slate-700">Exports are not available yet</p><p class="mt-2 text-xs text-slate-500">PDF and TXT links will appear after export generation completes.</p></div>
            <p class="mt-5 text-[11px] leading-5 text-slate-400">Download links are temporary. Reopen this transcription to generate fresh links.</p>
          </section>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
@keyframes dashboard-in { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
.dashboard-enter, .dashboard-card { animation: dashboard-in .55s cubic-bezier(.22, 1, .36, 1) both; }
.folder-loader { animation: folder-pulse 1.2s ease-in-out infinite; }
@keyframes folder-pulse { 50% { opacity: .45; } }
@media (prefers-reduced-motion: reduce) { .dashboard-enter, .dashboard-card { animation: none; } }
</style>
