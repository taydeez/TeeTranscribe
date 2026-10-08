<script setup lang="ts">
const auth = useAuthStore()
const settings = useAccountSettings()
</script>

<template>
  <section class="surface p-6">
    <h2 class="text-xl font-semibold">Profile</h2>
    <p class="mt-2 text-sm text-slate-500">Update your name or organization name.</p>
    <form class="mt-6 grid gap-5" @submit.prevent="settings.saveName">
      <div class="grid gap-5 sm:grid-cols-2">
        <label for="profile-name" class="grid gap-2 text-sm font-medium">Name or organization
          <input id="profile-name" v-model="settings.name.value" required maxlength="255" autocomplete="name" placeholder="Your name or organization name" class="w-full rounded-lg border border-slate-200 p-3 text-sm">
        </label>
        <label for="profile-email" class="grid gap-2 text-sm font-medium">Email address
          <input id="profile-email" :value="auth.user?.email" type="email" readonly class="w-full rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
        </label>
      </div>
      <p v-if="settings.error.value" role="alert" class="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ settings.error.value }}</p>
      <p v-if="settings.message.value" role="status" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ settings.message.value }}</p>
      <button class="button-primary justify-center sm:justify-self-start" :disabled="settings.busy.value || !settings.nameChanged.value">{{ settings.busy.value ? 'Saving…' : 'Save name' }}</button>
    </form>
  </section>
</template>
