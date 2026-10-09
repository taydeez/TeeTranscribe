<script setup lang="ts">
defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: []; logout: [] }>()
const auth = useAuthStore()
const items = [
  { label: 'Home', icon: 'home' as const, to: '/dashboard', exact: true },
  { label: 'My Transcriptions', icon: 'transcript' as const, to: '/dashboard/transcriptions', exact: false },
  { label: 'Translations', icon: 'translate' as const, to: '/dashboard/translations', exact: false },
  { label: 'Dubbing', icon: 'audio' as const, to: '/dashboard/dubbing', exact: false },
]
const accountItems = [
  { label: 'Usage', icon: 'usage' as const, to: '/dashboard/usage' },
  { label: 'Credits and billing', icon: 'billing' as const, to: '/dashboard/billing' },
  { label: 'Settings', icon: 'settings' as const, to: '/dashboard/settings' },
]
</script>
<template>
  <aside class="sidebar" :class="{ 'sidebar-open': open }">
    <NuxtLink class="brand" to="/" @click="emit('close')"><span class="brand-mark"><UiAppIcon name="audio" /></span>TeeTranscribe</NuxtLink>
    <p class="nav-label">Workspace</p>
    <nav class="space-y-1" aria-label="Workspace navigation">
      <NuxtLink v-for="item in items" :key="item.to" :to="item.to" class="nav-item" :active-class="item.exact ? '' : 'nav-active'" exact-active-class="nav-active" @click="emit('close')"><UiAppIcon :name="item.icon" />{{ item.label }}</NuxtLink>
    </nav>
    <p class="nav-label mt-8">Account</p>
    <nav class="space-y-1" aria-label="Account navigation"><NuxtLink v-for="item in accountItems" :key="item.to" :to="item.to" class="nav-item" active-class="nav-active" @click="emit('close')"><UiAppIcon :name="item.icon" />{{ item.label }}</NuxtLink></nav>
    <div class="mt-auto pt-8">
      <div class="rounded-xl border border-slate-200 p-4"><p class="text-sm font-semibold">Your words, organized.</p><p class="mt-2 text-xs leading-5 text-slate-500">Keep recordings, transcripts, and exports together in folders.</p><NuxtLink to="/dashboard/transcriptions" class="mt-3 inline-flex items-center gap-2 text-xs font-semibold text-indigo-600" @click="emit('close')">Open your library <UiAppIcon name="arrow" :size="14" /></NuxtLink></div>
      <div class="mt-5 flex items-center gap-3 border-t border-slate-200 pt-5"><span class="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-50 text-sm font-semibold text-indigo-700">{{ auth.user?.name?.charAt(0).toUpperCase() }}</span><div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold">{{ auth.user?.name }}</p><p class="truncate text-xs text-slate-500">{{ auth.user?.email }}</p></div><button type="button" class="icon-button" aria-label="Sign out" @click="emit('logout')"><UiAppIcon name="logout" :size="18" /></button></div>
    </div>
  </aside>
</template>
