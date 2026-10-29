<script setup lang="ts">
import type { FolderProject } from '~/types/folder'
import type { PrivacyDeletion } from '~/types/privacy'
import PrivacyDeleteAction from '~/components/privacy/PrivacyDeleteAction.vue'
defineProps<{ items: FolderProject[]; folderId: string }>()
const emit = defineEmits<{ deletion: [record: PrivacyDeletion] }>()
function label(item: FolderProject) {
  return item.type === 'translation' ? 'Translation' : item.type === 'subtitles' ? 'Subtitled video' : item.mediaType === 'audio' ? 'Dubbed audio' : 'Dubbed video'
}
function link(item: FolderProject, folder: string) {
  return { path: item.type === 'translation' ? '/dashboard/translations' : '/dashboard/dubbing', query: { [item.type === 'translation' ? 'translation' : 'dubbing']: item.id, folder } }
}
function date(value: string | null) {
  return value ? new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value)) : '—'
}
</script>
<template>
  <div>
    <div class="border-t border-slate-200 px-6 py-5 sm:px-8"><h3 class="text-lg font-semibold">Translations &amp; media</h3><p class="mt-1 text-sm text-slate-500">Open a project to edit, preview or download its files.</p></div>
    <p v-if="!items.length" class="px-6 pb-8 text-sm text-slate-500 sm:px-8">No translations or media projects in this folder yet.</p>
    <div v-else class="divide-y divide-slate-100">
      <div v-for="item in items" :key="`${item.type}:${item.id}`" class="flex flex-wrap items-start gap-3 px-6 py-5 transition hover:bg-indigo-50/40 sm:px-8"><NuxtLink :to="link(item, folderId)" class="flex min-h-11 min-w-0 flex-1 items-center gap-3">
        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><UiAppIcon :name="item.type === 'translation' ? 'translate' : item.mediaType === 'audio' ? 'audio' : 'video'" :size="20" /></span>
        <div class="min-w-0 flex-1"><h4 class="truncate text-sm font-semibold">{{ item.name }}</h4><p class="mt-1 text-xs text-slate-500">{{ label(item) }} · {{ item.sourceLanguage ?? 'Auto' }} → {{ item.targetLanguage }}<span class="ml-2 hidden sm:inline">· {{ date(item.createdAt) }}</span></p></div>
        <span class="flex shrink-0 items-center gap-2 rounded-full px-3 py-1 text-xs font-medium capitalize" :class="item.status === 'failed' ? 'bg-rose-50 text-rose-700' : item.status === 'complete' ? 'bg-emerald-50 text-emerald-700' : 'bg-indigo-50 text-indigo-700'"><UiAppIcon v-if="['pending', 'processing'].includes(item.status)" name="loader" class="size-3 motion-safe:animate-spin" />{{ item.status }}</span>
        <UiAppIcon name="right" :size="16" class="hidden shrink-0 text-slate-400 sm:block" />
      </NuxtLink><PrivacyDeleteAction :resource-type="item.type === 'translation' ? 'translation' : 'dubbing'" :resource-id="item.id" scope="project" :name="item.name" label="Delete" @accepted="emit('deletion', $event)" /></div>
    </div>
  </div>
</template>
