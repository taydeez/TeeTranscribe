<script setup lang="ts">
import { logLevels, logProviders, logTypes } from '~/config/adminLogs'
const { canView, canInspect, filters, result, selected, loading, detailLoading, error, detailError, load, apply, reset, page, follow, inspect, close } = useAdminLogs()
const field = 'mt-1 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 py-2.5 text-sm'
function date(value: string) { return new Date(value).toLocaleString() }
function time(ms: number | null) { return ms === null ? '—' : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(2)} s` }
</script>

<template>
  <section class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-semibold tracking-widest text-indigo-600 uppercase">Operations</p><h1 class="mt-2 text-3xl font-semibold">Internal logs</h1><p class="mt-2 text-sm text-[var(--muted)]">Trace provider calls, job failures and processing time.</p></div><button v-if="canView" class="button-secondary" type="button" :disabled="loading" @click="load"><UiAppIcon :name="loading ? 'loader' : 'clock'" :class="{ 'animate-spin': loading }" :size="16" />Refresh</button></header>
    <p v-if="!canView" class="surface p-6 text-sm">You do not have permission to view operational logs.</p>
    <template v-else>
      <form class="surface grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4" @submit.prevent="apply">
        <label class="text-xs font-medium">Category<select v-model="filters.type" :class="field"><option value="">All categories</option><option v-for="type in logTypes" :key="type" :value="type">{{ type }}</option></select></label>
        <label class="text-xs font-medium">Level<select v-model="filters.level" :class="field"><option value="">All levels</option><option v-for="level in logLevels" :key="level" :value="level">{{ level }}</option></select></label>
        <label class="text-xs font-medium">Provider<select v-model="filters.provider" :class="field"><option value="">All providers</option><option v-for="provider in logProviders" :key="provider" :value="provider">{{ provider }}</option></select></label>
        <label class="text-xs font-medium">Sort<select v-model="filters.sort" :class="field"><option value="">Newest first</option><option value="oldest">Oldest first</option></select></label>
        <label class="text-xs font-medium">Operation ID<input v-model="filters.operation_id" :class="field" maxlength="100" placeholder="Transcription, dubbing…"></label>
        <label class="text-xs font-medium">Provider request ID<input v-model="filters.provider_request_id" :class="field" maxlength="255"></label>
        <label class="text-xs font-medium">From (UTC)<input v-model="filters.from" :class="field" type="date" :max="filters.to || undefined"></label>
        <label class="text-xs font-medium">To (UTC)<input v-model="filters.to" :class="field" type="date" :min="filters.from || undefined"></label>
        <label class="text-xs font-medium">Job ID<input v-model="filters.job_id" :class="field" maxlength="100"></label>
        <label class="text-xs font-medium sm:col-span-2">Search messages<input v-model="filters.search" :class="field" maxlength="200" placeholder="Failure reason or processing stage"></label>
        <div class="flex items-end gap-3"><button class="button-primary" type="submit" :disabled="loading">Apply filters</button><button class="button-secondary" type="button" @click="reset">Reset</button></div>
      </form>
      <div class="surface overflow-hidden">
        <p v-if="loading" class="p-10 text-center text-sm text-[var(--muted)]" role="status">Loading logs…</p>
        <div v-else-if="error" class="p-8 text-center"><p class="text-sm text-rose-600" role="alert">{{ error }}</p><button class="button-secondary mt-4" @click="load">Try again</button></div>
        <p v-else-if="result && !result.data.length" class="p-12 text-center text-sm text-[var(--muted)]">No logs match these filters. New events appear here after processing runs.</p>
        <div v-else-if="result" class="overflow-x-auto">
          <table class="w-full min-w-[900px] text-left text-sm"><caption class="sr-only">Operational events and timings</caption><thead class="bg-[var(--page)] text-xs text-[var(--muted)]"><tr><th class="p-4">Time</th><th class="p-4">Category / level</th><th class="p-4">Message</th><th class="p-4">Provider</th><th class="p-4">Elapsed</th><th class="p-4">Trace</th></tr></thead>
            <tbody class="divide-y divide-[var(--line)]"><tr v-for="log in result.data" :key="log.id" class="align-top transition-colors hover:bg-[var(--page)]">
              <td class="whitespace-nowrap p-4 text-xs text-[var(--muted)]">{{ date(log.created_at) }}</td>
              <td class="p-4"><p class="font-medium capitalize">{{ log.type }}</p><span class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs" :class="['error', 'critical', 'alert', 'emergency'].includes(log.level) ? 'bg-rose-50 text-rose-700' : log.level === 'warning' ? 'bg-amber-50 text-amber-700' : 'bg-indigo-50 text-indigo-700'">{{ log.level }}</span></td>
              <td class="max-w-sm p-4"><button v-if="canInspect" class="text-left font-medium text-indigo-600 hover:underline" @click="inspect(log)">{{ log.message }}</button><span v-else>{{ log.message }}</span><p v-if="log.operation_type" class="mt-1 text-xs text-[var(--muted)]">{{ log.operation_type }}</p></td>
              <td class="p-4 text-[var(--muted)]">{{ log.provider ?? '—' }}</td><td class="whitespace-nowrap p-4 font-mono text-xs">{{ time(log.elapsed_ms) }}</td>
              <td class="p-4"><button v-if="log.operation_id || log.job_id" class="max-w-40 truncate text-xs text-indigo-600 hover:underline" :title="log.operation_id ?? log.job_id ?? ''" @click="follow(log)">{{ log.operation_id ?? log.job_id }}</button><span v-else>—</span></td>
            </tr></tbody>
          </table>
        </div>
        <footer v-if="result" class="flex flex-wrap items-center justify-between gap-4 border-t border-[var(--line)] p-4 text-xs text-[var(--muted)]"><p>{{ result.meta.total }} events · Page {{ result.meta.currentPage }} of {{ result.meta.lastPage }}</p><nav class="flex gap-2" aria-label="Log pagination"><button class="button-secondary" :disabled="loading || result.meta.currentPage <= 1" @click="page(result.meta.currentPage - 1)">Previous</button><button class="button-secondary" :disabled="loading || result.meta.currentPage >= result.meta.lastPage" @click="page(result.meta.currentPage + 1)">Next</button></nav></footer>
      </div>
    </template>
    <AdminLogDetail v-if="selected" :log="selected" :loading="detailLoading" :error="detailError" @close="close" @retry="inspect(selected)" @follow="follow(selected)" />
  </section>
</template>
