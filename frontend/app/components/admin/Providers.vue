<script setup lang="ts">
const { activities, draft, reason, loading, busy, error, success, can, selected, load, select, save } = useAdminProviders()
const readOnly = computed(() => busy.value || !can('Update_AIProvider'))
</script>
<template>
  <section class="space-y-6">
    <div><h1 class="text-3xl font-semibold">AI providers</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-[var(--muted)]">Choose how new requests are routed. Existing quotes and queued work retain their selected provider and model.</p></div>
    <p v-if="!can('ViewAny_AIProvider')" class="surface p-8" role="alert">You don’t have permission to view provider settings.</p>
    <template v-else>
      <p v-if="error" class="rounded-xl bg-red-50 p-4 text-sm text-red-700" role="alert">{{ error }} <button v-if="!busy" type="button" class="underline" @click="load">Refresh settings</button></p>
      <p v-if="success" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-700" role="status">{{ success }}</p>
      <div v-if="loading" class="surface p-12 text-center" role="status">Loading provider settings…</div>
      <template v-else-if="selected && draft">
        <label class="block text-sm font-medium">Activity<select :value="selected.activity" :disabled="busy" class="mt-2 block w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3 sm:max-w-sm" @change="select(($event.target as HTMLSelectElement).value)"><option v-for="item in activities" :key="item.activity" :value="item.activity">{{ item.name }}</option></select></label>
        <form class="space-y-6" @submit.prevent="save">
          <div class="surface space-y-4 p-6">
            <label class="block text-sm font-medium">Default provider<select v-model="draft.default_provider" :disabled="readOnly" class="mt-2 block w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3 sm:max-w-sm"><option v-for="(provider, key) in selected.catalog" :key="key" :value="key">{{ provider.name }}</option></select></label>
            <p class="text-xs leading-5 text-[var(--muted)]">A disabled or unconfigured selection makes matching requests unavailable. There is no automatic fallback after processing starts. Credentials remain in the backend environment.</p>
          </div>
          <div class="grid gap-4 xl:grid-cols-2">
            <AdminProviderCard v-for="(provider, key) in selected.catalog" :key="selected.activity + key" v-model="draft.providers[key]!" :definition="provider" :models="draft.models[key] ?? []" :disabled="readOnly">
              <AdminProviderModels v-model="draft.models[key]!" :definition="provider" :activity="selected.activity" :disabled="readOnly" />
            </AdminProviderCard>
          </div>
          <AdminProviderLanguageRules v-if="!['cleanup', 'summary'].includes(selected.activity)" :key="selected.activity" v-model="draft.language_rules" :catalog="selected.catalog" :models="draft.models" :disabled="readOnly" :activity="selected.activity" />
          <div v-if="can('Update_AIProvider')" class="surface space-y-4 p-6"><label class="block text-sm font-medium">Reason for change<textarea v-model="reason" required maxlength="1000" rows="2" :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3" placeholder="Explain why these settings are changing" /></label><button type="submit" class="button-primary" :disabled="busy || !reason.trim()"><UiAppIcon v-if="busy" name="loader" class="animate-spin" />{{ busy ? 'Saving…' : 'Save settings' }}</button></div>
        </form>
        <div class="surface p-6"><h2 class="font-semibold">Recent changes</h2><p v-if="!selected.history.length" class="mt-4 text-sm text-[var(--muted)]">No saved changes yet. Current defaults come from the backend environment.</p><ol v-else class="mt-4 divide-y divide-[var(--line)]"><li v-for="change in selected.history" :key="change.id" class="py-4"><p class="text-sm font-medium">{{ change.reason }}</p><p class="mt-1 text-xs text-[var(--muted)]">{{ change.actorName ?? 'Former administrator' }} · {{ new Date(change.createdAt).toLocaleString() }}</p><details class="mt-2 text-xs"><summary class="cursor-pointer text-indigo-600">View configuration change</summary><div class="mt-3 grid gap-3 lg:grid-cols-2"><div><p class="font-semibold">Before</p><pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-[var(--page)] p-3">{{ JSON.stringify(change.before, null, 2) }}</pre></div><div><p class="font-semibold">After</p><pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-[var(--page)] p-3">{{ JSON.stringify(change.after, null, 2) }}</pre></div></div></details></li></ol></div>
      </template>
      <p v-else-if="!error" class="surface p-8">No activities are available.</p>
    </template>
  </section>
</template>
