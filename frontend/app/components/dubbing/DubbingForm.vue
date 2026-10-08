<script setup lang="ts">
import TranslationLanguageSelect from '~/components/translation/TranslationLanguageSelect.vue'
import { formatCredits } from '~/utils/credits'
defineProps<{ workflow: ReturnType<typeof useDubbingWorkflow> }>()
const name = defineModel<string>('name', { required: true })
const source = defineModel<string>('source', { required: true })
const target = defineModel<string>('target', { required: true })
</script>
<template>
  <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
    <div class="mb-6"><h2 class="text-xl font-semibold text-slate-900">Dub your video</h2><p class="mt-2 text-sm leading-relaxed text-slate-500">Translate speech while keeping the speakers’ voices and your original visuals.</p></div>
    <form class="space-y-5" @submit.prevent="workflow.checkPrice">
      <fieldset class="space-y-5" :disabled="workflow.busy.value">
        <label class="block rounded-2xl border-2 border-dashed border-indigo-200 bg-indigo-50/50 p-6 text-center transition hover:border-indigo-400">
          <UiAppIcon name="upload" class="mx-auto mb-3 size-8 text-indigo-500" />
          <span class="block text-sm font-semibold text-indigo-700">{{ workflow.file.value?.name ?? 'Choose your video' }}</span>
          <span class="mt-1 block text-xs text-slate-500">MP4 or WebM · up to 3 GiB · 180 minutes</span>
          <input class="mt-4 block w-full text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:text-indigo-700" type="file" accept=".mp4,.webm,video/mp4,video/webm" aria-label="Choose video" @change="workflow.selectFile">
        </label>
        <label class="block text-sm font-medium text-slate-700">Video name <span class="font-normal text-slate-400">(optional)</span><input v-model="name" maxlength="255" class="mt-2 min-h-11 w-full rounded-xl border border-slate-200 px-3" placeholder="Your video’s name"></label>
        <div class="grid gap-4 sm:grid-cols-2">
          <TranslationLanguageSelect id="dubbing-source" v-model="source" label="Spoken language" :languages="workflow.languages.value" auto />
          <TranslationLanguageSelect id="dubbing-target" v-model="target" label="Dub into" :languages="workflow.languages.value" />
        </div>
      </fieldset>
      <p v-if="workflow.loadingLanguages.value" class="text-sm text-slate-500">Loading languages…</p>
      <p v-else-if="!workflow.configured.value" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Dubbing is not configured yet.<button type="button" class="ml-2 underline" @click="workflow.loadLanguages">Refresh</button></p>
      <div v-if="workflow.uploader.active.value || workflow.uploader.paused.value" class="space-y-2">
        <div class="flex justify-between text-sm"><span>{{ workflow.uploader.paused.value ? 'Upload paused' : 'Uploading video' }}</span><span>{{ workflow.uploader.progress.value }}%</span></div>
        <progress class="h-2 w-full accent-indigo-600" :value="workflow.uploader.progress.value" max="100" />
        <button v-if="workflow.uploader.active.value && !workflow.uploader.finishing.value" type="button" class="text-sm text-indigo-600" @click="workflow.uploader.pause">Pause upload</button>
      </div>
      <p v-if="workflow.uploader.notice.value" class="text-xs text-slate-500">{{ workflow.uploader.notice.value }}</p>
      <div v-if="workflow.uploader.unfinished.value.some(item => /\.(mp4|webm)$/i.test(item.filename))" class="rounded-xl bg-slate-50 p-3 text-xs text-slate-500">
        <p class="font-medium">Saved video uploads</p><p v-for="item in workflow.uploader.unfinished.value.filter(item => /\.(mp4|webm)$/i.test(item.filename))" :key="item.key" class="mt-1">{{ item.filename }}</p><p class="mt-2">Select the same file to resume without uploading completed parts again.</p>
      </div>
      <p v-if="workflow.quote.value?.status === 'measuring'" class="flex items-center gap-2 text-sm text-indigo-600"><UiAppIcon name="loader" class="size-4 animate-spin" />Checking video length and credit cost…</p>
      <p v-if="workflow.quote.value?.status === 'failed'" role="alert" class="text-sm text-rose-600">{{ workflow.quote.value.failure_reason }}</p>
      <div v-if="workflow.quote.value?.status === 'ready'" class="rounded-xl bg-indigo-50 p-4 text-sm">
        <p class="font-semibold text-indigo-900">Your video is {{ Math.ceil((workflow.quote.value.quantity ?? 0) / 1000) }} seconds long. Dubbing costs {{ formatCredits(workflow.quote.value.credit_units ?? 0) }} credits.</p>
        <p v-if="!workflow.quote.value.enough_credits" class="mt-2 text-rose-700">You need more credits. <NuxtLink to="/dashboard/billing" class="underline">Add credits</NuxtLink></p>
        <button type="button" class="button-primary mt-4 w-full" :disabled="workflow.busy.value || !workflow.quote.value.enough_credits" @click="workflow.submit">{{ workflow.busy.value ? 'Starting…' : 'Confirm and dub video' }}</button>
      </div>
      <button v-else type="submit" class="button-primary w-full" :disabled="workflow.busy.value || !workflow.file.value || !workflow.configured.value || workflow.quote.value?.status === 'measuring'">{{ workflow.busy.value ? 'Preparing…' : workflow.uploader.paused.value ? 'Resume and check price' : 'Upload and check price' }}</button>
      <p v-if="workflow.error.value" role="alert" class="text-sm text-rose-600">{{ workflow.error.value }}</p>
    </form>
  </section>
</template>
