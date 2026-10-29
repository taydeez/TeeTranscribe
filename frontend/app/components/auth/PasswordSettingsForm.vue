<script setup lang="ts">
const settings = useAccountSettings()
</script>

<template>
  <section class="surface p-6">
    <h2 class="text-xl font-semibold">Change password</h2>
    <p class="mt-2 text-sm leading-6 text-slate-500">Use at least eight characters, including a letter and a number. You’ll be signed out of all devices after changing your password.</p>
    <form class="mt-6 grid gap-5" @submit.prevent="settings.changePassword">
      <label for="current-password" class="grid gap-2 text-sm font-medium">Current password
        <input id="current-password" v-model="settings.currentPassword.value" type="password" required autocomplete="current-password" class="rounded-lg border border-slate-200 p-3 text-sm">
      </label>
      <div class="grid gap-5 sm:grid-cols-2">
        <label for="new-password" class="grid gap-2 text-sm font-medium">New password
          <input id="new-password" v-model="settings.password.value" type="password" required minlength="8" autocomplete="new-password" class="rounded-lg border border-slate-200 p-3 text-sm">
        </label>
        <label for="confirm-new-password" class="grid gap-2 text-sm font-medium">Confirm new password
          <input id="confirm-new-password" v-model="settings.passwordConfirmation.value" type="password" required minlength="8" autocomplete="new-password" class="rounded-lg border border-slate-200 p-3 text-sm">
        </label>
      </div>
      <p v-if="settings.error.value" role="alert" class="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ settings.error.value }}</p>
      <div class="flex flex-wrap items-center justify-between gap-4">
        <button class="button-primary justify-center" :disabled="settings.busy.value">{{ settings.busy.value ? 'Changing…' : 'Change password' }}</button>
        <NuxtLink to="/auth/forgot-password" class="text-sm font-semibold text-indigo-600">Forgot or haven’t set a password?</NuxtLink>
      </div>
    </form>
  </section>
</template>
