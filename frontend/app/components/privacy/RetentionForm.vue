<script setup lang="ts">
import { PRIVACY_CATEGORIES } from '~/config/privacy'
import RetentionSetting from './RetentionSetting.vue'
const { retention, shortened, dirty, loading, busy, error, saved, confirming, cleanupInterval, load, save, confirm } = usePrivacyRetention()
const groups = [...new Set(PRIVACY_CATEGORIES.map(item => item.group))]
</script>
<template>
  <section class="surface p-5 sm:p-7" aria-labelledby="retention-heading" :aria-busy="loading || busy">
    <h2 id="retention-heading" class="text-xl font-semibold">Privacy &amp; file retention</h2><p class="mt-2 text-sm leading-relaxed text-slate-500">Choose how long each type of file or saved text stays in your workspace. Everything is kept until you delete it by default.</p>
    <p class="mt-3 rounded-xl bg-slate-50 p-4 text-xs leading-relaxed text-slate-500">These controls manage files and text stored in your workspace. They cannot guarantee removal of copies held by external processing services. Cleanup checks run every {{ cleanupInterval }} minutes.</p>
    <p class="mt-3 text-xs leading-relaxed text-slate-500">Saved transcript and translation limits remove the entire project, including its notes and generated downloads. Source and generated file limits keep saved text. Retention applies to existing and future items.</p>
    <p v-if="loading" class="mt-5 text-sm text-slate-500" role="status">Loading retention settings…</p>
    <p v-if="error" class="mt-5 text-sm text-rose-700" role="alert">{{ error }} <button v-if="!dirty" type="button" class="underline" @click="load">Retry</button></p>
    <form v-if="!loading" class="mt-6 space-y-6" @submit.prevent="save">
      <fieldset v-for="group in groups" :key="group" :disabled="busy" class="space-y-3"><legend class="mb-3 text-sm font-semibold text-slate-700">{{ group }}</legend><RetentionSetting v-for="item in PRIVACY_CATEGORIES.filter(category => category.group === group)" :key="item.key" v-model="retention[item.key]" :category="item.key" :label="item.label" :disabled="busy" /></fieldset>
      <div v-if="confirming" class="rounded-xl border border-rose-200 bg-rose-50 p-5">
        <h3 class="text-sm font-semibold text-rose-900">Confirm shorter retention</h3><p class="mt-2 text-sm leading-relaxed text-rose-800">These settings apply to existing and future files. Files or text already older than the new limit may be permanently deleted during the next cleanup check, within {{ cleanupInterval }} minutes. Deleted content cannot be recovered.</p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-rose-800"><li v-for="item in shortened" :key="item.key">{{ item.label }}: {{ retention[item.key] }} hours</li></ul>
        <div class="mt-4 flex flex-wrap gap-3"><button type="button" class="min-h-11 rounded-xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy" @click="confirm">{{ busy ? 'Saving…' : 'Confirm and save retention' }}</button><button type="button" class="button-secondary" :disabled="busy" @click="confirming = false">Cancel</button></div>
      </div>
      <button v-else type="submit" class="button-primary w-full disabled:opacity-50 sm:w-auto" :disabled="busy || !dirty">{{ busy ? 'Saving…' : 'Save retention settings' }}</button>
      <p v-if="saved" class="text-sm text-emerald-700" role="status">Retention settings saved.</p>
    </form>
  </section>
</template>
