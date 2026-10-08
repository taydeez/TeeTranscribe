<script setup lang="ts">
import type { TranslationHistoryPage, TranslationLanguage } from '~/types/translation'
const props = defineProps<{ version: number; languages: TranslationLanguage[] }>()
const route = useRoute()
const history = ref<TranslationHistoryPage | null>(null)
const loading = ref(false)
const error = ref('')
const page = computed(() => Math.max(1, Number(route.query.page) || 1))
let sequence = 0
let active = true
const language = (code: string | null) => props.languages.find(item => item.code === code)?.name ?? code ?? 'Auto-detect'
async function load() {
  const current = ++sequence
  loading.value = true; error.value = ''
  try {
    const result = await useAuthenticatedFetch<TranslationHistoryPage>('/api/translations', { query: { page: page.value, per_page: 10 } })
    if (active && current === sequence) history.value = result
  } catch (failure) { if (active && current === sequence) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Could not load translations.' }
  finally { if (active && current === sequence) loading.value = false }
}
watch([page, () => props.version], load)
onMounted(load)
onBeforeUnmount(() => { active = false; sequence++ })
</script>
<template>
  <section class="surface mt-8 overflow-hidden">
    <div class="flex items-center justify-between border-b border-slate-100 p-6"><h2 class="text-xl font-semibold">Saved translations</h2><button type="button" class="text-sm text-indigo-600" :disabled="loading" @click="load">Refresh</button></div>
    <p v-if="error" class="p-5 text-sm text-rose-600" role="alert">{{ error }}</p><p v-if="loading" class="p-5 text-sm text-slate-500">Loading translations…</p>
    <div v-else-if="history?.data.length" class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="border-b border-slate-100 text-xs text-slate-500"><tr><th class="p-4 font-medium">Name</th><th class="p-4 font-medium">Languages</th><th class="p-4 font-medium">Status</th><th class="p-4 font-medium">Created</th></tr></thead><tbody><tr v-for="item in history.data" :key="item.id" class="border-b border-slate-100 last:border-0"><td class="p-4"><NuxtLink class="font-semibold text-indigo-700 hover:underline" :to="{ query: { ...route.query, translation: item.id } }">{{ item.name }}</NuxtLink></td><td class="whitespace-nowrap p-4 text-slate-500">{{ language(item.sourceLanguage ?? item.detectedLanguage) }} → {{ language(item.targetLanguage) }}</td><td class="p-4 capitalize" :class="item.status === 'failed' ? 'text-rose-600' : item.status === 'complete' ? 'text-emerald-600' : 'text-indigo-600'">{{ item.status }}</td><td class="whitespace-nowrap p-4 text-slate-500">{{ new Date(item.createdAt).toLocaleDateString() }}</td></tr></tbody></table></div>
    <p v-else-if="!error" class="p-8 text-center text-sm text-slate-500">Your saved translations will appear here.</p>
    <nav v-if="history && history.meta.last_page > 1" class="flex justify-between border-t border-slate-100 p-4 text-sm" aria-label="Translation history pages"><NuxtLink v-if="page > 1" class="button-secondary" :to="{ query: { ...route.query, page: page - 1 } }">Previous</NuxtLink><span v-else /><span>{{ page }} / {{ history.meta.last_page }}</span><NuxtLink v-if="page < history.meta.last_page" class="button-secondary" :to="{ query: { ...route.query, page: page + 1 } }">Next</NuxtLink><span v-else /></nav>
  </section>
</template>
