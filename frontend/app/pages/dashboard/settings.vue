<script setup lang="ts">
definePageMeta({ layout: 'dashboard', title: 'Settings' })
</script>
<template>
  <section class="page-intro"><p class="eyebrow">Account</p><h1>Workspace settings.</h1><p>Your profile and the way you work.</p></section>
  <div class="grid max-w-4xl gap-6">
    <AuthProfileSettingsForm />
    <AuthPasswordSettingsForm />
    <section class="surface flex items-center justify-between p-6"><div><h2 class="text-lg font-semibold">Appearance</h2><p class="mt-2 text-sm text-slate-500">Switch between light and dark mode.</p></div><UiThemeToggle /></section>
  </div>
</template>
