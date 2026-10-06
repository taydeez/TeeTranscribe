<script setup lang="ts">
const route = useRoute()
const reference = computed(() => {
  for (const key of ['reference', 'tx_ref', 'trxref']) {
    const value = route.query[key]
    if (typeof value === 'string' && value.trim()) return value.trim()
  }
  return ''
})

onMounted(async () => {
  if (reference.value) {
    await navigateTo({ path: '/dashboard/billing', query: { reference: reference.value } }, { replace: true })
  }
})
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-slate-50 p-6">
    <section class="surface w-full max-w-md p-8 text-center">
      <template v-if="reference">
        <h1 class="text-xl font-semibold">Checking your payment</h1>
        <p class="mt-3 text-sm text-slate-500" role="status">Opening billing to verify your payment securely…</p>
      </template>
      <template v-else>
        <h1 class="text-xl font-semibold">Payment reference missing</h1>
        <p class="mt-3 text-sm text-slate-500" role="alert">This return link does not contain a payment reference. Check your payment history for its status.</p>
        <NuxtLink to="/dashboard/billing" class="button-primary mt-6">Go to billing</NuxtLink>
      </template>
    </section>
  </main>
</template>
