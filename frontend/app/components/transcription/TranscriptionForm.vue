<script setup lang="ts">
import AudioRecorder from './AudioRecorder.vue'
import { transcriptionLanguages } from '~/config/languages'

withDefaults(defineProps<{ heading?: string; eyebrow?: string; dashboard?: boolean }>(), {
  heading: 'Upload media', eyebrow: 'TRANSCRIBE', dashboard: false,
})
const emit = defineEmits<{ submitted: [id: string] }>()
const auth = useAuthStore()
const form = useTranscriptionForm()
const picker = ref<HTMLInputElement | null>(null)
const dragging = ref(false)
const hydrated = ref(false)

function choose(event: Event) {
  const input = event.target as HTMLInputElement
  form.selectFile(input.files?.[0])
  input.value = ''
}

function drop(event: DragEvent) {
  dragging.value = false
  if (event.dataTransfer?.files.length === 1) form.selectFile(event.dataTransfer.files[0])
}

async function submit() {
  const id = await form.submit()
  if (id) emit('submitted', id)
}

onMounted(() => {
  hydrated.value = true
  if (auth.isAuthenticated) form.loadFolders()
})
</script>

<template>
  <section class="upload-card" :class="dashboard && 'dashboard-upload-card'">
    <div class="card-heading"><h2>{{ heading }}</h2><span>{{ eyebrow }}</span></div>
    <form @submit.prevent="submit">
      <div class="mb-4 grid grid-cols-3 rounded-xl bg-slate-100 p-1" aria-label="Audio source">
        <button v-for="item in [{ value: 'file', label: 'Upload file' }, { value: 'record', label: 'Record audio' }, { value: 'url', label: 'Paste URL' }]" :key="item.value" class="min-h-10 rounded-lg px-2 text-xs font-bold transition" :class="form.source.value === item.value ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-800'" type="button" :disabled="form.busy.value" @click="form.chooseSource(item.value as 'file' | 'record' | 'url')">{{ item.label }}</button>
      </div>

      <input ref="picker" class="hidden" type="file" accept=".mp3,.wav,.m4a,.mp4,.ogg,.oga,.flac,.webm,.aac" :disabled="form.busy.value" @change="choose">
      <button v-if="form.source.value === 'file'" class="dropzone" :class="{ dragging, selected: form.file.value }" type="button" :disabled="form.busy.value" @click="picker?.click()" @dragover.prevent="dragging = !form.busy.value" @dragleave.prevent="dragging = false" @drop.prevent="drop">
        <span class="feature-icon"><UiAppIcon name="upload" :size="24" /></span>
        <template v-if="form.file.value"><strong>{{ form.file.value.name }}</strong><span>{{ form.sizeLabel.value }} · Click to replace</span></template>
        <template v-else><strong>Drag audio or video here</strong><span>or <u>browse files</u></span></template>
        <small>MP3, WAV, MP4 and more · Up to 100 MB</small>
      </button>

      <AudioRecorder v-else-if="form.source.value === 'record'" @recorded="form.selectFile" @cleared="form.clearFile" />

      <div v-else class="rounded-2xl border border-indigo-100 bg-indigo-50/50 p-5">
        <label class="block text-xs font-bold text-slate-700" for="audio-url">Public audio URL</label>
        <p class="mt-1 text-xs text-slate-500">Paste a direct HTTP or HTTPS link the provider can access.</p>
        <input id="audio-url" v-model.trim="form.pastedUrl.value" class="mt-4 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10" type="url" placeholder="https://example.com/recording.mp3" :disabled="form.busy.value">
      </div>

      <div class="language-row">
        <div><label for="language">Spoken language</label><p>The language in your recording.</p></div>
        <select id="language" v-model="form.language.value" :disabled="form.busy.value">
          <optgroup v-for="item in transcriptionLanguages" :key="item.name" :label="item.name">
            <option v-for="code in item.codes" :key="code" :value="code">{{ item.name }} ({{ code }})</option>
          </optgroup>
        </select>
      </div>

      <div v-if="hydrated && auth.isAuthenticated" class="language-row border-t border-slate-100">
        <div><label for="folder">Folder</label><p>Choose where this transcription should be saved.</p></div>
        <select id="folder" v-model="form.folderId.value" :disabled="form.busy.value">
          <option value="">{{ form.foldersLoading.value ? 'Loading folders…' : 'Today’s folder (automatic)' }}</option>
          <option v-for="folder in form.folders.value" :key="folder.id" :value="folder.id">{{ folder.name }}</option>
        </select>
      </div>

      <p v-if="form.error.value" class="error" role="alert">{{ form.error.value }}</p>
      <div v-if="form.stage.value === 'done'" class="success" role="status">
        <strong>Uploaded. Over to transcription.</strong><p>Your recording was submitted successfully.</p><NuxtLink class="mt-2 inline-flex items-center gap-2 font-semibold text-emerald-700" to="/dashboard/transcriptions">Open transcriptions <UiAppIcon name="arrow" :size="16" /></NuxtLink>
      </div>
      <p v-if="form.source.value !== 'url' && form.duration.value && form.stage.value !== 'done'" class="cost-notice">Your audio is {{ form.durationLabel.value }} long. It would cost you <strong>50 credits</strong>.</p>
      <button class="submit" type="submit" :disabled="form.busy.value || (form.stage.value !== 'done' && !form.canSubmit.value)"><span>{{ form.submitLabel.value }}</span><span aria-hidden="true">↗</span></button>
      <p class="footnote">{{ form.source.value === 'url' ? 'The URL is sent directly for transcription.' : 'Your audio uploads directly from your browser.' }}</p>
    </form>
  </section>
</template>

<style scoped>
.upload-card { background:var(--surface); padding:30px; border:1px solid var(--line); border-radius:16px; box-shadow:0 1px 3px #0f172a05; }
.dashboard-upload-card { box-shadow:0 8px 30px #47556910; }
.card-heading { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; }
.card-heading h2 { margin:0; font-size:21px; font-weight:700; color:var(--text); }
.card-heading>span { font-size:12px; font-weight:800; letter-spacing:1.5px; color:#6366f1; background:#eef2ff; border-radius:999px; padding:6px 9px; }
.dropzone { width:100%; border:1.5px dashed #c7d2fe; border-radius:16px; padding:30px 18px 24px; background:var(--page); color:var(--text); display:flex; flex-direction:column; align-items:center; transition:.2s; }
.dropzone:hover:not(:disabled),.dropzone.dragging { transform:translateY(-2px); background:#eef2ff; border-color:#6366f1; }.dropzone.selected{border-style:solid;border-color:#818cf8}.dropzone strong{font-size:15px;margin-top:16px}.dropzone>span:not(.audio-symbol){font-size:13px;color:#667085;margin-top:6px}.dropzone small{font-size:12px;color:var(--muted);margin-top:20px}
.audio-symbol{display:flex;gap:4px;height:38px;align-items:center}.audio-symbol i{width:4px;height:14px;border-radius:4px;background:linear-gradient(#6366f1,#22d3ee)}.audio-symbol i:nth-child(2),.audio-symbol i:nth-child(4){height:26px}.audio-symbol i:nth-child(3){height:38px}
.language-row{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:25px 0}.language-row label{font-size:13px;font-weight:700;color:var(--text)}.language-row p{font-size:12px;color:var(--muted);margin:5px 0 0}.language-row select{border:1px solid #d0d5dd;border-radius:10px;padding:11px 30px 11px 13px;color:#344054;background:var(--surface);font-size:12px;max-width:60%}
.submit{width:100%;display:flex;align-items:center;justify-content:space-between;min-height:50px;padding:14px 18px;border:0;border-radius:12px;background:#4f46e5;color:#fff;font-weight:700}.submit:disabled{opacity:.5}.footnote{text-align:center;color:var(--muted);font-size:12px;margin:15px 0 0}.cost-notice{margin:0 0 16px;padding:14px;border:1px solid #c7d2fe;border-radius:10px;background:#eef2ff;color:#4338ca;font-size:13px}.error{background:var(--surface)1f2;border:1px solid #fecdd3;border-radius:10px;color:#be123c;padding:12px;font-size:13px}.success{background:#ecfdf3;border:1px solid #a7f3d0;border-radius:10px;padding:15px;margin-bottom:20px;font-size:13px}
@media(max-width:520px){.upload-card{padding:22px 18px}.language-row{align-items:flex-start;flex-direction:column}.language-row select{max-width:100%;width:100%}}
</style>
