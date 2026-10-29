<script setup lang="ts">
import type { PrivacyCategory, PrivacyDeletion, PrivacyResource, PrivacyScope } from '~/types/privacy'
import DeletionConfirmation from './DeletionConfirmation.vue'
import { privacyCategoryLabel } from '~/config/privacy'

const props = withDefaults(defineProps<{ resourceType: PrivacyResource; resourceId: string; scope: PrivacyScope; category?: PrivacyCategory; name: string; label?: string; disabled?: boolean; placement?: 'inline' | 'corner'; showLabel?: boolean }>(), { label: 'Delete', disabled: false, category: undefined, placement: 'inline', showLabel: false })
const emit = defineEmits<{ accepted: [record: PrivacyDeletion]; completed: [record: PrivacyDeletion] }>()
const workflow = usePrivacyDeletion(() => ({ resource_type: props.resourceType, resource_id: props.resourceId, scope: props.scope, ...(props.scope === 'generated' && props.category ? { category: props.category } : {}) }), result => emit('accepted', result), result => emit('completed', result))
const { confirming, busy, error, record, working } = workflow
const consequence = computed(() => props.resourceType === 'folder' ? 'Permanently removes this folder and all transcriptions, translations, dubbed media, subtitles and downloads inside it. Source files used only by these projects are also removed. Sources still used by other projects are kept.' : props.scope === 'project' ? 'Permanently removes this project, its saved text, notes and files from your workspace.' : props.scope === 'source' ? 'Permanently removes the uploaded source file from your workspace. Audio playback and tasks that need this file will become unavailable.' : `Permanently removes ${props.category ? privacyCategoryLabel(props.category).toLowerCase() : 'generated files'} stored for this project.`)
const notes = computed(() => props.scope === 'source'
  ? ['If processing is in progress, cleanup waits until it can safely finish.', 'Other projects using this same source will also lose playback. Their saved text and downloads remain.']
  : props.scope === 'project' && props.resourceType !== 'folder'
    ? ['Shared sources and separately saved uploads remain available. You can remove uploads in Settings.']
    : [])
</script>

<template>
  <div :class="placement === 'corner' ? 'contents' : undefined" @click.stop @keydown.enter.stop>
    <button v-if="!record" type="button" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 rounded-lg text-sm font-medium transition-colors hover:bg-rose-50 hover:text-rose-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 disabled:opacity-50" :class="[placement === 'corner' ? 'absolute right-1 top-1 text-slate-600' : 'text-rose-600', showLabel ? 'px-3' : 'size-11']" :aria-label="`${label}: ${name}`" :title="label" :disabled="disabled || busy" @click="workflow.open"><UiAppIcon name="close" :size="18" /><span v-if="showLabel">{{ label }}</span></button>
    <DeletionConfirmation :open="confirming" :busy="busy" :disabled="disabled" :name="name" :title="`${label}?`" :consequence="consequence" :notes="notes" :error="error" @confirm="workflow.confirm" @cancel="confirming = false" />
    <p v-if="working" class="mt-2 flex items-center gap-2 text-xs text-slate-600" role="status"><UiAppIcon name="loader" :size="14" class="shrink-0 motion-safe:animate-spin" />Deletion requested. Cleanup is in progress.</p>
    <p v-if="record?.status === 'completed'" class="mt-2 text-xs text-emerald-700" role="status">Deletion complete.</p>
    <p v-if="record?.status === 'failed'" class="mt-2 text-xs text-rose-700" role="alert">{{ record.failureReason ?? 'Cleanup could not finish.' }} <button type="button" class="min-h-11 px-2 font-semibold underline" :disabled="busy" @click="workflow.retry">{{ busy ? 'Retrying…' : 'Retry cleanup' }}</button></p>
    <p v-if="error && !confirming" class="mt-2 text-xs text-rose-700" role="alert">{{ error }}</p>
  </div>
</template>
