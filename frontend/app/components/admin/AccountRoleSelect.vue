<script setup lang="ts">
import type { AdminAccount, AdminRole } from '~/types/adminAccount'
const props = defineProps<{ account: AdminAccount; roles: AdminRole[]; disabled: boolean }>()
const emit = defineEmits<{ save: [roleId: number] }>()
const selected = ref(props.account.roleId)
watch(() => props.account.roleId, value => { selected.value = value })
</script>
<template><div class="flex items-center gap-2"><select v-model.number="selected" :disabled="disabled" aria-label="Administrator role" class="rounded-lg border border-[var(--line)] bg-[var(--surface)] p-2 text-sm"><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select><button class="button-secondary text-xs" type="button" :disabled="disabled || selected === account.roleId" @click="emit('save', selected)">Save role</button></div></template>
