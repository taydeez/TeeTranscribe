<script setup lang="ts">
defineProps<{ open: boolean; signingOut: boolean }>()
const emit = defineEmits<{ close: []; logout: [] }>()
const auth = useAuthStore()
</script>

<template>
  <aside class="sidebar" :class="{ 'sidebar-open': open }">
    <NuxtLink class="brand" to="/taydeez" @click="emit('close')"><span class="brand-mark"><UiAppIcon name="audio" /></span>TeeTranscribe</NuxtLink>
    <div class="mt-4 flex items-center gap-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase"><span class="size-1.5 rounded-full bg-indigo-500" />Administration</div>
    <p class="nav-label mt-9">Manage</p>
    <nav class="space-y-1" aria-label="Administrator navigation">
      <NuxtLink to="/taydeez" class="nav-item" exact-active-class="nav-active" @click="emit('close')"><UiAppIcon name="home" />Overview</NuxtLink>
      <NuxtLink v-if="auth.user?.permissions?.includes('ViewAny_User')" to="/taydeez/customers" class="nav-item" active-class="nav-active" @click="emit('close')"><UiAppIcon name="users" />Customers</NuxtLink>
      <NuxtLink v-if="auth.user?.permissions?.includes('ViewAny_AdminAccount')" to="/taydeez/accounts" class="nav-item" active-class="nav-active" @click="emit('close')"><UiAppIcon name="users" />Admin accounts</NuxtLink>
      <NuxtLink v-if="auth.user?.permissions?.includes('ViewAny_Role')" to="/taydeez/roles" class="nav-item" active-class="nav-active" @click="emit('close')"><UiAppIcon name="settings" />Roles and permissions</NuxtLink>
      <NuxtLink v-if="auth.user?.permissions?.includes('ViewAny_AIProvider')" to="/taydeez/providers" class="nav-item" active-class="nav-active" @click="emit('close')"><UiAppIcon name="settings" />AI providers</NuxtLink>
    </nav>
    <div class="mt-auto pt-8">
      <div class="rounded-xl border border-[var(--line)] bg-[var(--page)] p-4"><p class="text-sm font-semibold">Secure admin session</p><p class="mt-2 text-xs leading-5 text-[var(--muted)]">You’ll be signed out after 5 minutes of inactivity.</p></div>
      <div class="mt-5 flex items-center gap-3 border-t border-[var(--line)] pt-5">
        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-50 text-sm font-semibold text-indigo-700">{{ auth.user?.name?.charAt(0).toUpperCase() }}</span>
        <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold">{{ auth.user?.name }}</p><p class="truncate text-xs text-[var(--muted)]">{{ auth.user?.email }}</p></div>
        <button type="button" class="icon-button" :disabled="signingOut" aria-label="Sign out" @click="emit('logout')"><UiAppIcon :name="signingOut ? 'loader' : 'logout'" :class="{ 'animate-spin': signingOut }" :size="18" /></button>
      </div>
    </div>
  </aside>
</template>
