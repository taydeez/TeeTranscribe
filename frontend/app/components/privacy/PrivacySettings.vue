<script setup lang="ts">
import RetentionForm from './RetentionForm.vue'
import SavedUploads from './SavedUploads.vue'
import DeletionHistory from './DeletionHistory.vue'
const historyVersion = ref(0), filesVersion = ref(0), requested = ref(false)
</script>
<template>
  <div id="privacy" class="space-y-6 scroll-mt-6">
    <RetentionForm />
    <p v-if="requested" class="rounded-xl bg-indigo-50 p-4 text-sm text-indigo-700" role="status">Deletion requested. Follow its progress below.</p>
    <SavedUploads :version="filesVersion" @accepted="historyVersion++; requested = true" />
    <DeletionHistory :version="historyVersion" @settled="filesVersion++" />
  </div>
</template>
