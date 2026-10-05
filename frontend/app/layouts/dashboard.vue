<script setup lang="ts">
const auth = useAuthStore()
const route = useRoute()
const menuOpen = ref(false)
const title = computed(() => String(route.meta.title ?? 'Home'))
async function logout() { await auth.logout(); await navigateTo('/') }
</script>
<template>
  <div class="workspace-layout">
    <DashboardSidebar :open="menuOpen" @close="menuOpen = false" @logout="logout" />
    <button v-if="menuOpen" class="fixed inset-0 z-30 bg-slate-950/35 lg:hidden" aria-label="Close navigation" @click="menuOpen = false" />
    <main class="workspace-main">
      <header class="workspace-header">
        <div class="flex items-center gap-3"><button class="icon-button lg:hidden" type="button" aria-label="Open navigation" @click="menuOpen = true"><UiAppIcon name="menu" /></button><span class="text-sm text-slate-500">Workspace <span class="mx-2 text-slate-300">/</span><span class="font-medium text-slate-800">{{ title }}</span></span></div>
        <div class="flex items-center gap-3"><UiThemeToggle /><NuxtLink class="button-primary" to="/dashboard#new-transcription"><UiAppIcon name="plus" :size="17" /><span class="hidden sm:inline">New transcription</span></NuxtLink></div>
      </header>
      <div class="workspace-content"><slot /></div>
    </main>
  </div>
</template>
