<script setup lang="ts">
import { privacyCategoryLabel } from '~/config/privacy'
const props = defineProps<{ version: number }>()
const emit = defineEmits<{ settled: [] }>()
const { records, loading, error, retrying, load, retry } = usePrivacyDeletionHistory(() => emit('settled'))
watch(() => props.version, () => { void load() })
</script>
<template>
  <section class="surface overflow-hidden" :aria-busy="loading" aria-labelledby="deletion-history-heading">
    <div class="flex items-center justify-between gap-3 border-b border-slate-100 p-5 sm:p-7"><div><h2 id="deletion-history-heading" class="text-xl font-semibold">Recent deletions</h2><p class="mt-2 text-sm text-slate-500">Cleanup continues after you leave this page.</p></div><button type="button" class="min-h-11 px-2 text-sm text-indigo-600" :disabled="loading" @click="load()">Refresh</button></div>
    <p v-if="loading" class="p-5 text-sm text-slate-500" role="status">Loading deletion progress…</p><p v-if="error" class="p-5 text-sm text-rose-700" role="alert">{{ error }} <button type="button" class="underline" @click="load()">Retry</button></p>
    <div v-if="records.length" class="divide-y divide-slate-100"><div v-for="item in records" :key="item.id" class="flex flex-wrap items-start justify-between gap-3 p-5 sm:px-7"><div class="min-w-0 flex-1"><h3 class="text-sm font-semibold capitalize">{{ item.resourceType }} · {{ item.scope === 'project' ? 'Entire project' : item.scope === 'source' ? 'Source file' : privacyCategoryLabel(item.category) }}</h3><p class="mt-1 text-xs text-slate-400">{{ new Date(item.createdAt).toLocaleString() }}</p><p v-if="item.failureReason" class="mt-2 break-words text-sm text-rose-700" role="alert">{{ item.failureReason }}</p></div><div class="flex items-center gap-2"><span v-if="['pending', 'processing'].includes(item.status)" class="size-4 rounded-full border-2 border-indigo-200 border-t-indigo-600 motion-safe:animate-spin" aria-hidden="true" /><span class="text-sm capitalize" :class="item.status === 'failed' ? 'text-rose-700' : item.status === 'completed' ? 'text-emerald-700' : 'text-indigo-700'">{{ item.status }}</span><button v-if="item.status === 'failed'" type="button" class="button-secondary ml-2 text-xs disabled:opacity-50" :disabled="Boolean(retrying)" @click="retry(item.id)">{{ retrying === item.id ? 'Retrying…' : 'Retry' }}</button></div></div></div>
    <p v-else-if="!loading && !error" class="p-7 text-center text-sm text-slate-500">No deletion requests yet.</p>
  </section>
</template>
