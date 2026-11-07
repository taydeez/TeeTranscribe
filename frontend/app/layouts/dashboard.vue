<script setup lang="ts">
import { featureRouteAvailable } from '~/utils/features'
const features = useFeatures()
const auth = useAuthStore()
const route = useRoute()
const menuOpen = ref(false)
const title = computed(() => String(route.meta.title ?? 'Home'))
let featureTimer: ReturnType<typeof setInterval> | null = null
const refreshFeatures = () => { void features.refresh() }
onMounted(() => { featureTimer = setInterval(refreshFeatures, 30_000); window.addEventListener('focus', refreshFeatures) })
onBeforeUnmount(() => { if (featureTimer) clearInterval(featureTimer); window.removeEventListener('focus', refreshFeatures) })
watch(features.flags, flags => { if (!featureRouteAvailable(route.path, flags, route.query)) void navigateTo('/dashboard') })
async function logout() {
  const loginUrl = auth.isAdmin ? '/taydeez/login' : '/'
  await auth.logout()
  await navigateTo(loginUrl)
}
</script>
<template>
  <div class="workspace-layout">
    <DashboardSidebar :open="menuOpen" @close="menuOpen = false" @logout="logout" />
    <button v-if="menuOpen" class="fixed inset-0 z-30 bg-slate-950/35 lg:hidden" aria-label="Close navigation" @click="menuOpen = false" />
    <main class="workspace-main">
      <header class="workspace-header">
        <div class="flex items-center gap-3"><button class="icon-button lg:hidden" type="button" aria-label="Open navigation" @click="menuOpen = true"><UiAppIcon name="menu" /></button><span class="text-sm text-slate-500">Workspace <span class="mx-2 text-slate-300">/</span><span class="font-medium text-slate-800">{{ title }}</span></span></div>
        <div class="flex items-center gap-3"><UiThemeToggle /><NuxtLink v-if="features.enabled('transcription')" class="button-primary" to="/dashboard#new-transcription"><UiAppIcon name="plus" :size="17" /><span class="hidden sm:inline">New transcription</span></NuxtLink></div>
      </header>
      <div class="workspace-content"><p v-if="features.error.value" class="mb-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900" role="alert">{{ features.error.value }} <button class="underline" @click="features.refresh">Retry</button></p><slot /></div>
    </main>
  </div>
</template>
