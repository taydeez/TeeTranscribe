<script setup lang="ts">
import type { ActivityCancellation, ActivityType } from '~/types/activity'
import DeletionConfirmation from '~/components/privacy/DeletionConfirmation.vue'
const props = defineProps<{ type: ActivityType; id: string; name: string; disabled?: boolean }>()
const emit = defineEmits<{ changed: [result: ActivityCancellation] }>()
const { confirming, busy, error, confirm } = useActivityCancellation(() => ({ type: props.type, id: props.id }), result => emit('changed', result))
</script>

<template>
  <div class="inline-block" @click.stop @keydown.enter.stop>
    <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-rose-50 hover:text-rose-600 disabled:opacity-50" :disabled="disabled || busy" @click="confirming = true"><UiAppIcon name="close" :size="16" />Cancel task</button>
    <DeletionConfirmation :open="confirming" :busy="busy" :disabled="Boolean(disabled)" :name="name" title="Cancel this task?" consequence="Stops this task in your workspace and frees its processing slot. Credits still reserved for this task will be returned. Completed work already charged is not refunded." :notes="['Your source file and saved content remain. Work already running externally may continue, but late results will not restart this task.']" :error="error" confirm-label="Cancel task" pending-label="Cancelling…" dismiss-label="Keep task" @confirm="confirm" @cancel="confirming = false" />
  </div>
</template>
