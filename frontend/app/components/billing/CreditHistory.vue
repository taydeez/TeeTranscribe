<script setup lang="ts">
import type { BillingHistoryPage, BillingHistoryType, InvoiceDownload } from '~/types/billing'
import { formatCredits, formatMoney } from '~/utils/credits'

const props = withDefaults(defineProps<{ type?: BillingHistoryType; version?: number }>(), { type: 'payments', version: 0 })
const route = useRoute()
const history = ref<BillingHistoryPage | null>(null)
const loading = ref(false)
const error = ref('')
const downloading = ref('')
const page = computed(() => Math.max(1, Number(route.query.page) || 1))
let sequence = 0
async function load() {
  const current = ++sequence
  loading.value = true; error.value = ''
  try {
    const result = await useAuthenticatedFetch<BillingHistoryPage>(`/api/billing/history/${props.type}`, { query: { page: page.value, per_page: 10 } })
    if (current === sequence) history.value = result
  } catch (failure: unknown) {
    if (current === sequence) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Could not load history.'
  } finally { if (current === sequence) loading.value = false }
}
watch([page, () => props.type, () => props.version], load)
onMounted(load)
async function downloadInvoice(id: string) {
  if (downloading.value) return
  downloading.value = id; error.value = ''
  try {
    const invoice = await useAuthenticatedFetch<InvoiceDownload>(`/api/billing/payments/${id}/invoice`)
    const url = new URL(invoice.url)
    if (url.protocol !== 'https:') throw new Error('The invoice download link is invalid.')
    window.location.assign(url.toString())
  } catch (failure: unknown) {
    error.value = (failure as { data?: { message?: string }; message?: string }).data?.message ?? (failure as Error).message ?? 'Could not download invoice.'
  } finally { downloading.value = '' }
}
function date(value: string) { return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) }
</script>

<template>
  <section class="surface overflow-hidden" aria-live="polite" :aria-busy="loading">
    <p v-if="error" class="p-5 text-sm text-rose-600" role="alert">{{ error }} <button class="underline" type="button" @click="load">Retry</button></p>
    <p v-if="loading" class="p-5 text-sm text-slate-500">Loading history…</p>
    <div v-else-if="history?.data.length" class="overflow-x-auto">
      <table class="w-full text-left text-sm"><thead class="border-b border-slate-100 text-xs text-slate-500"><tr><th class="p-4 font-medium">Date</th><th class="p-4 font-medium">Activity</th><th class="p-4 font-medium">Credits</th><th class="p-4 font-medium">{{ type === 'payments' ? 'Paid' : 'Status' }}</th><th v-if="type === 'payments'" class="p-4 font-medium">Invoice</th></tr></thead>
        <tbody><tr v-for="entry in history.data" :key="entry.id" class="border-b border-slate-100 last:border-0"><td class="whitespace-nowrap p-4 text-slate-500">{{ date(entry.created_at) }}</td><td class="p-4"><p class="font-semibold capitalize">{{ entry.package_name ?? entry.activity ?? entry.kind }}</p><p v-if="entry.provider" class="mt-1 text-xs text-slate-500">{{ entry.provider }} · {{ ((entry.quantity ?? 0) / 60000).toFixed(2) }} min</p></td><td class="whitespace-nowrap p-4 font-medium">{{ formatCredits(entry.credit_units ?? entry.amount_units ?? 0) }}</td><td class="p-4"><p v-if="entry.currency">{{ formatMoney(entry.amount_minor ?? 0, entry.currency) }}</p><span class="text-xs capitalize text-slate-500">{{ entry.status ?? `${formatCredits(entry.available_after ?? 0)} available` }}</span></td><td v-if="type === 'payments'" class="whitespace-nowrap p-4"><button v-if="entry.invoice_ready" class="button-secondary text-xs" type="button" :disabled="!!downloading" @click="downloadInvoice(entry.id)">{{ downloading === entry.id ? 'Preparing download…' : 'Download invoice' }}</button><button v-else-if="entry.status === 'paid'" class="text-xs text-slate-500 underline" type="button" @click="load">Preparing invoice · Refresh</button><span v-else class="text-slate-400">—</span></td></tr></tbody>
      </table>
    </div>
    <p v-else-if="!error" class="p-8 text-center text-sm text-slate-500">No {{ type === 'ledger' ? 'credit transactions' : type }} yet.</p>
    <nav v-if="history && history.meta.last_page > 1" class="flex items-center justify-between border-t border-slate-100 p-4 text-sm" aria-label="History pages"><NuxtLink v-if="page > 1" class="button-secondary" :to="{ query: { ...route.query, page: page - 1 } }">Previous</NuxtLink><span v-else /><span>{{ page }} / {{ history.meta.last_page }}</span><NuxtLink v-if="page < history.meta.last_page" class="button-secondary" :to="{ query: { ...route.query, page: page + 1 } }">Next</NuxtLink><span v-else /></nav>
  </section>
</template>
