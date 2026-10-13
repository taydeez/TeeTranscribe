<script setup lang="ts">
import type { BillingPackage } from '~/types/adminBilling'
const packages = defineModel<BillingPackage[]>({ required: true })
defineProps<{ disabled: boolean }>()
function add() { packages.value.push({ code: '', name: '', credits: '', price_ngn: '', enabled: true }) }
</script>
<template>
  <section class="surface p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-lg font-semibold">Credit packages</h2><p class="mt-1 text-sm text-[var(--muted)]">Set the price and the credits a customer receives. USD prices use your exchange rate.</p></div><button type="button" class="button-secondary" :disabled="disabled || packages.length >= 50" @click="add"><UiAppIcon name="plus" :size="16" />Add package</button></div>
    <p v-if="!packages.length" class="mt-5 rounded-xl bg-[var(--page)] p-5 text-sm text-[var(--muted)]">No packages. Add one to offer credit purchases.</p>
    <fieldset v-for="(item, index) in packages" :key="index" :disabled="disabled" class="mt-5 rounded-xl border border-[var(--line)] p-4">
      <legend class="px-2 text-sm font-semibold">{{ item.name || 'New package' }}</legend>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <label class="billing-label">Name<input v-model="item.name" required maxlength="120" class="billing-input" placeholder="Starter"></label>
        <label class="billing-label">Package code<input v-model="item.code" required maxlength="60" pattern="[a-z0-9][a-z0-9_-]*" class="billing-input" placeholder="starter"><span class="billing-help">Keep existing codes stable.</span></label>
        <label class="billing-label">Credits received<input :value="item.credits" required type="number" min="0.01" max="999999.99" step="0.01" class="billing-input" @input="item.credits = ($event.target as HTMLInputElement).value"></label>
        <label class="billing-label">Price (NGN)<input :value="item.price_ngn" required type="number" min="0.01" max="999999.99" step="0.01" class="billing-input" @input="item.price_ngn = ($event.target as HTMLInputElement).value"></label>
      </div>
      <div class="mt-4 flex items-center justify-between gap-4"><label class="flex items-center gap-2 text-sm"><input v-model="item.enabled" type="checkbox" class="accent-indigo-600">Available for purchase</label><button type="button" class="icon-button text-[var(--muted)] hover:text-red-600" :aria-label="'Remove ' + (item.name || 'package')" @click="packages.splice(index, 1)"><UiAppIcon name="close" :size="18" /></button></div>
    </fieldset>
    <p class="mt-4 text-xs text-[var(--muted)]">Removing or disabling a package affects new purchases. Existing purchases keep their saved amounts.</p>
  </section>
</template>
