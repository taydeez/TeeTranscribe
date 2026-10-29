<script setup lang="ts">
import type { OperationalLog } from '~/types/adminLog'
defineProps<{ log: OperationalLog; loading: boolean; error: string }>()
const emit = defineEmits<{ close: []; follow: []; retry: [] }>()
const dialog = ref<HTMLDialogElement | null>(null)
onMounted(() => { dialog.value?.showModal() })
onBeforeUnmount(() => { dialog.value?.close() })
</script>
<template>
  <Teleport to="body">
    <dialog ref="dialog" class="m-auto max-h-[85dvh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-6 text-[var(--ink)] shadow-xl backdrop:bg-slate-950/50" aria-labelledby="log-detail-heading" @cancel.prevent="emit('close')" @click.self="emit('close')">
      <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase text-indigo-600">{{ log.type }} · {{ log.level }}</p><h2 id="log-detail-heading" class="mt-2 text-xl font-semibold">{{ log.message }}</h2></div><button class="icon-button shrink-0" aria-label="Close details" @click="emit('close')"><UiAppIcon name="close" /></button></div>
      <dl class="mt-6 grid gap-4 break-all text-sm sm:grid-cols-2"><div><dt class="text-xs text-[var(--muted)]">Operation</dt><dd>{{ log.operation_type ?? '—' }} {{ log.operation_id }}</dd></div><div><dt class="text-xs text-[var(--muted)]">Provider request</dt><dd>{{ log.provider_request_id ?? '—' }}</dd></div><div><dt class="text-xs text-[var(--muted)]">Elapsed</dt><dd>{{ log.elapsed_ms === null ? 'Not measured' : `${log.elapsed_ms} ms` }}</dd></div><div><dt class="text-xs text-[var(--muted)]">Recorded</dt><dd>{{ new Date(log.created_at).toLocaleString() }}</dd></div></dl>
      <p class="mt-5 text-xs text-[var(--muted)]">Lifecycle completion time includes queue waiting, provider processing and exports. HTTP time measures one request; callback time measures provider turnaround.</p>
      <p v-if="loading" class="mt-6 text-sm" role="status">Loading details…</p><div v-else-if="error" class="mt-6"><p class="text-sm text-rose-600" role="alert">{{ error }}</p><button class="button-secondary mt-3" @click="emit('retry')">Retry</button></div><pre v-else class="mt-6 overflow-auto rounded-xl bg-[var(--page)] p-4 text-xs leading-6">{{ JSON.stringify(log.context, null, 2) }}</pre>
      <button v-if="log.operation_id || log.job_id" class="button-primary mt-6" @click="emit('follow')">View operation timeline</button>
    </dialog>
  </Teleport>
</template>
