<script setup lang="ts">
const props = defineProps<{ open: boolean; busy: boolean; disabled: boolean; name: string; title: string; consequence: string; notes?: string[]; error: string }>()
const emit = defineEmits<{ confirm: []; cancel: [] }>()
const dialog = ref<HTMLDialogElement | null>(null)
const headingId = useId()
const descriptionId = useId()
let previousOverflow: string | null = null
function restoreScroll() {
  if (previousOverflow !== null) {
    document.body.style.overflow = previousOverflow
    previousOverflow = null
  }
}
function cancel() { if (!props.busy) emit('cancel') }
watch([() => props.open, dialog], ([open, element]) => {
  if (!element) return
  if (open && !element.open) {
    element.showModal()
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
  } else if (!open && element.open) {
    element.close()
    restoreScroll()
  }
}, { flush: 'post' })
onBeforeUnmount(() => { dialog.value?.close(); restoreScroll() })
</script>

<template>
  <Teleport to="body">
    <dialog ref="dialog" class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 text-left text-slate-900 shadow-2xl backdrop:bg-slate-950/55 sm:p-7" :aria-labelledby="headingId" :aria-describedby="descriptionId" :aria-busy="busy" @cancel.prevent="cancel" @click.stop @keydown.enter.stop>
      <div class="flex items-start justify-between gap-4">
        <h2 :id="headingId" class="text-xl font-semibold">{{ title }}</h2>
        <button type="button" class="inline-flex size-11 shrink-0 items-center justify-center rounded-lg text-slate-600 hover:bg-rose-50 hover:text-rose-600 disabled:opacity-50" aria-label="Close confirmation" :disabled="busy" @click="cancel"><UiAppIcon name="close" :size="18" /></button>
      </div>
      <p class="mt-2 break-words font-semibold text-slate-700">{{ name }}</p>
      <p :id="descriptionId" class="mt-3 text-sm leading-relaxed text-slate-600">{{ consequence }}</p>
      <p v-for="note in notes" :key="note" class="mt-3 text-xs leading-relaxed text-slate-500">{{ note }}</p>
      <p class="mt-3 text-sm font-medium text-rose-600">This cannot be undone.</p>
      <p v-if="error" role="alert" class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ error }}</p>
      <div class="mt-6 flex flex-wrap justify-end gap-3">
        <button type="button" autofocus class="min-h-11 rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 disabled:opacity-50" :disabled="busy" @click="cancel">Cancel</button>
        <button type="button" class="min-h-11 rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50" :disabled="busy || disabled" @click="emit('confirm')">{{ busy ? 'Deleting…' : 'Permanently delete' }}</button>
      </div>
    </dialog>
  </Teleport>
</template>
