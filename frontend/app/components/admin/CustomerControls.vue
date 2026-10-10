<script setup lang="ts">
import type { CustomerAccessInput, CustomerCreditInput } from '~/types/adminCustomer'
defineProps<{ canUpdate: boolean; canAdjust: boolean; busy: boolean }>()
const emit = defineEmits<{ access: [input: CustomerAccessInput]; credits: [input: CustomerCreditInput] }>()
const action = ref<CustomerAccessInput['action']>('suspend')
const days = ref(7)
const accessReason = ref('')
const creditAction = ref<CustomerCreditInput['action']>('add')
const credits = ref('')
const creditReason = ref('')
</script>

<template>
  <div class="grid gap-5 lg:grid-cols-2">
    <form v-if="canUpdate" class="surface space-y-5 p-6" @submit.prevent="emit('access', { action, days: action === 'suspend' ? days : undefined, reason: accessReason.trim() })">
      <div><h2 class="text-lg font-semibold">Account access</h2><p class="mt-2 text-sm text-[var(--muted)]">Suspending or blocking signs the customer out immediately.</p></div>
      <label class="block text-sm font-medium">Action<select v-model="action" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3" :disabled="busy"><option value="suspend">Suspend for a number of days</option><option value="block">Block indefinitely</option><option value="restore">Restore access</option></select></label>
      <label v-if="action === 'suspend'" class="block text-sm font-medium">Days<input v-model.number="days" type="number" min="1" max="3650" step="1" required :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label>
      <label class="block text-sm font-medium">Reason<textarea v-model="accessReason" required maxlength="2000" rows="3" :disabled="busy" placeholder="Why are you changing this customer’s access?" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3" /></label>
      <button type="submit" class="button-primary" :disabled="busy || !accessReason.trim()"><UiAppIcon v-if="busy" name="loader" class="animate-spin" :size="16" />Update access</button>
    </form>
    <form v-if="canAdjust" class="surface space-y-5 p-6" @submit.prevent="emit('credits', { action: creditAction, credits, reason: creditReason.trim() })">
      <div><h2 class="text-lg font-semibold">Adjust credits</h2><p class="mt-2 text-sm text-[var(--muted)]">Every adjustment is recorded. Reserved credits cannot be removed.</p></div>
      <label class="block text-sm font-medium">Action<select v-model="creditAction" :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"><option value="add">Add credits</option><option value="remove">Remove credits</option></select></label>
      <label class="block text-sm font-medium">Credits<input v-model="credits" type="number" min="0.01" max="1000000" step="0.01" required :disabled="busy" placeholder="e.g. 50" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label>
      <label class="block text-sm font-medium">Reason<textarea v-model="creditReason" required maxlength="2000" rows="3" :disabled="busy" placeholder="Why are you adjusting this balance?" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3" /></label>
      <button type="submit" class="button-primary" :disabled="busy || !creditReason.trim()"><UiAppIcon v-if="busy" name="loader" class="animate-spin" :size="16" />{{ creditAction === 'add' ? 'Add credits' : 'Remove credits' }}</button>
    </form>
  </div>
</template>
