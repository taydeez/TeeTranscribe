<script setup lang="ts">
const auth = useAuthStore()
const route = useRoute()
const menuOpen = ref(false)
const signingOut = ref(false)
const title = computed(() => String(route.meta.title ?? 'Overview'))

async function logout() {
  if (signingOut.value) return
  signingOut.value = true
  try { await auth.logout() }
  finally {
    signingOut.value = false
    await navigateTo('/taydeez/login')
  }
}
</script>

<template>
  <div v-show="auth.isAuthenticated && auth.isAdmin" class="workspace-layout">
    <AdminSidebar :open="menuOpen" :signing-out="signingOut" @close="menuOpen = false" @logout="logout" />
    <button v-if="menuOpen" class="fixed inset-0 z-30 bg-slate-950/35 backdrop-blur-sm lg:hidden" type="button" aria-label="Close navigation" @click="menuOpen = false" />
    <main class="workspace-main">
      <header class="workspace-header">
        <div class="flex items-center gap-3">
          <button class="icon-button lg:hidden" type="button" aria-label="Open navigation" :aria-expanded="menuOpen" @click="menuOpen = true"><UiAppIcon name="menu" /></button>
          <span class="text-sm text-slate-500">Admin <span class="mx-2 text-slate-300">/</span><span class="font-medium text-[var(--text)]">{{ title }}</span></span>
        </div>
        <div class="flex items-center gap-3"><span class="hidden rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 sm:inline-flex">Administrator</span><UiThemeToggle /></div>
      </header>
      <div class="workspace-content"><slot /></div>
    </main>
  </div>
  <div v-if="!auth.isAuthenticated || !auth.isAdmin" class="grid min-h-screen place-items-center bg-[var(--page)] text-[var(--muted)]" role="status"><span class="flex items-center gap-3"><UiAppIcon name="loader" class="animate-spin" />Checking administrator access…</span></div>
</template>
