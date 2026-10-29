<script setup lang="ts">
import TranslationForm from '~/components/translation/TranslationForm.vue'
import TranslationResult from '~/components/translation/TranslationResult.vue'
import TranslationHistory from '~/components/translation/TranslationHistory.vue'
import type { PrivacyDeletion } from '~/types/privacy'
definePageMeta({ layout: 'dashboard', title: 'Translations' })
const draft = useTranslationDraftStore()
const { languages, languagesLoading, sourceLanguage, targetLanguage, name, folderId, quote, record, busy, opening, error, historyVersion, loadLanguages, checkPrice, submit, save, refresh, remove } = useTranslationWorkflow()
const deletionRequested = ref(false)
function deletion(item: PrivacyDeletion) { deletionRequested.value = true; void remove(item.resourceId) }
</script>
<template>
  <section class="page-intro"><p class="eyebrow">Language studio</p><h1>Every word. A wider audience.</h1><p>Translate transcripts and text into languages around the world. Yoruba, Igbo and Hausa are listed first.</p></section>
  <p v-if="error" class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700" role="alert">{{ error }}</p>
  <p v-if="deletionRequested" class="mb-6 rounded-xl bg-indigo-50 p-4 text-sm text-indigo-700" role="status">Deletion requested. <NuxtLink to="/dashboard/settings#privacy" class="font-semibold underline">Follow cleanup progress</NuxtLink></p>
  <div class="grid items-start gap-6 xl:grid-cols-2"><TranslationForm v-model:folder="folderId" v-model:text="draft.text" v-model:name="name" v-model:source="sourceLanguage" v-model:target="targetLanguage" :languages="languages" :loading="languagesLoading" :busy="busy" :quote="quote" :source-name="draft.sourceName" @price="checkPrice" @submit="submit" @retry="loadLanguages" /><TranslationResult :record="record" :busy="busy" :loading="opening" @save="save" @deletion="deletion" @refresh="record && refresh(record.id)" /></div>
  <TranslationHistory :version="historyVersion" :languages="languages" @deletion="deletion" />
</template>
