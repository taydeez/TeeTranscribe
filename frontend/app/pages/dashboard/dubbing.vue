<script setup lang="ts">
import DubbingForm from '~/components/dubbing/DubbingForm.vue'
import DubbingResult from '~/components/dubbing/DubbingResult.vue'
import DubbingHistory from '~/components/dubbing/DubbingHistory.vue'
import type { PrivacyDeletion } from '~/types/privacy'
definePageMeta({ layout: 'dashboard', title: 'Dubbing' })
const workflow = useDubbingWorkflow()
const deletionRequested = ref(false)
function deletion(item: PrivacyDeletion) { deletionRequested.value = true; void workflow.remove(item.resourceId) }
</script>
<template>
  <div class="space-y-7">
    <header><p class="text-xs font-semibold uppercase tracking-widest text-indigo-600">Make your voice travel</p><h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">Audio &amp; video dubbing</h1><p class="mt-3 max-w-2xl text-sm leading-relaxed text-slate-500">Translate your audio or video into another language, or add subtitles while keeping the original sound.</p></header>
    <p v-if="deletionRequested" class="rounded-xl bg-indigo-50 p-4 text-sm text-indigo-700" role="status">Deletion requested. <NuxtLink to="/dashboard/settings#privacy" class="font-semibold underline">Follow cleanup progress</NuxtLink></p>
    <div class="grid items-start gap-6 xl:grid-cols-2"><DubbingForm v-model:name="workflow.name.value" v-model:source="workflow.sourceLanguage.value" v-model:target="workflow.targetLanguage.value" :workflow="workflow" /><DubbingResult :record="workflow.record.value" :opening="workflow.opening.value" :busy="workflow.busy.value" @retry="workflow.retryExports" @refresh="workflow.record.value && workflow.refresh(workflow.record.value.id)" @deletion="deletion" /></div>
    <DubbingHistory :version="workflow.historyVersion.value" :languages="workflow.languages.value" @deletion="deletion" />
  </div>
</template>
