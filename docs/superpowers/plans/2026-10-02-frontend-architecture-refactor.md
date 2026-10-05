# Frontend Architecture Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the monolithic Vue application with route-based Nuxt pages, layouts, and focused feature components while preserving all current user behavior.

**Architecture:** Nuxt file-based routing owns page navigation. A dashboard layout owns authenticated navigation and sign-out, feature components own their local UI, Pinia owns authentication, and composables own reusable upload and API workflows. The transcription form is rendered once as a reusable component on both the landing page and dashboard overview.

**Tech Stack:** Nuxt 4.5, Vue 3 Composition API, Pinia, TypeScript, Tailwind CSS 4, Nitro API proxy routes.

**Spec:** The current behavior in `frontend/app/app.vue` and `frontend/app/components/DashboardView.vue`.

## Global Constraints

- Preserve upload, URL transcription, browser recording, language selection, folders, authentication, transcript editing, and export download behavior.
- Use Nuxt file-based pages and layouts instead of manual `$route.path` checks.
- Keep API calls behind the existing Nitro proxy routes and `useAuthenticatedFetch`.
- Keep authentication in Pinia; keep page-local UI state local.
- Do not add a new state-management or data-fetching dependency.
- The production build must pass after each architectural slice.

---

### Task 1: Establish Route and Type Boundaries

**Files:**
- Create: `frontend/app/types/transcription.ts`
- Create: `frontend/app/types/folder.ts`
- Create: `frontend/app/config/languages.ts`
- Modify: `frontend/app/app.vue`

**Interfaces:**
- Produces: shared `Folder`, `FolderPage`, `FolderDetails`, `FolderTranscription`, `TranscriptionExport`, `UploadTicket`, and `LanguageOption` types.
- Produces: `transcriptionLanguages: LanguageOption[]`.

- [ ] **Step 1: Extract shared API response types**

```ts
export type TranscriptionExport = {
  id: string
  format: 'pdf' | 'txt'
  status: 'pending' | 'failed' | 'completed'
  downloadUrl: string | null
}
```

- [ ] **Step 2: Extract the language configuration**

Move the existing language list unchanged to `app/config/languages.ts` and export it as `transcriptionLanguages`.

- [ ] **Step 3: Reduce the root application shell**

```vue
<template>
  <NuxtRouteAnnouncer />
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>
```

- [ ] **Step 4: Run the production build**

Run: `npm.cmd run build`

Expected: Nuxt discovers the new pages and completes without TypeScript or template errors.

### Task 2: Extract Authentication UI and Public Layout

**Files:**
- Create: `frontend/app/components/auth/AuthModal.vue`
- Create: `frontend/app/layouts/default.vue`
- Create: `frontend/app/pages/index.vue`

**Interfaces:**
- `AuthModal` consumes `v-model:open` and an initial `mode: 'login' | 'register'`.
- The landing page owns only modal visibility and invokes the auth store for sign-out.

- [ ] **Step 1: Move login, registration, admin verification, and Google login into `AuthModal.vue`**

The component must call the same Nitro endpoints and establish the Pinia session before navigating to `/dashboard`.

- [ ] **Step 2: Move landing-page presentation into `pages/index.vue`**

Render the marketing header, intro, reusable transcription form, auth modal, and footer.

- [ ] **Step 3: Put document-level rendering in `layouts/default.vue`**

```vue
<template><slot /></template>
```

- [ ] **Step 4: Verify anonymous and authenticated landing states**

Run: `npm.cmd run build`

Expected: both authentication branches compile and the root route is generated.

### Task 3: Extract the Transcription Workflow

**Files:**
- Create: `frontend/app/composables/useTranscriptionForm.ts`
- Create: `frontend/app/components/transcription/AudioRecorder.vue`
- Create: `frontend/app/components/transcription/TranscriptionForm.vue`

**Interfaces:**
- `useTranscriptionForm()` produces upload state, source selection, folder loading, `selectFile`, `submit`, and cleanup behavior.
- `AudioRecorder` emits `recorded(file: File, duration: number)`.
- `TranscriptionForm` accepts `showFolder?: boolean` and emits `submitted(id: string)`.

- [ ] **Step 1: Move direct R2 upload and API submission into `useTranscriptionForm`**

Preserve the exact identity rule: send `user_id` for authenticated users and `guest_session_id` otherwise.

- [ ] **Step 2: Move MediaRecorder lifecycle into `AudioRecorder.vue`**

The recorder must normalize MIME types to the signed upload MIME type, measure duration independently of WebM metadata, stop tracks on unmount, and expose playback before submission.

- [ ] **Step 3: Build one reusable `TranscriptionForm.vue`**

Render file, recording, and URL sources once. Use the shared language list and load folder options only for authenticated users.

- [ ] **Step 4: Verify all three audio sources compile**

Run: `npm.cmd run build`

Expected: file upload, recording, and URL branches compile without duplicated form markup.

### Task 4: Build the Authenticated Dashboard Layout

**Files:**
- Create: `frontend/app/layouts/dashboard.vue`
- Create: `frontend/app/components/dashboard/DashboardSidebar.vue`
- Modify: `frontend/app/middleware/auth.global.ts`

**Interfaces:**
- `DashboardSidebar` derives active state from `useRoute()` and navigates with `NuxtLink`.
- The dashboard layout supplies the responsive sidebar, top bar, page title, new-transcription link, and sign-out behavior.

- [ ] **Step 1: Replace button-driven navigation with route links**

Map overview, transcriptions, translations, billing, and settings to concrete `/dashboard/*` URLs.

- [ ] **Step 2: Generalize authentication middleware**

Protect every route whose path starts with `/dashboard`, rather than only `/dashboard`.

- [ ] **Step 3: Add route metadata to dashboard pages**

Each page uses `definePageMeta({ layout: 'dashboard', title: '...' })` so the layout can render the correct heading.

- [ ] **Step 4: Run the production build**

Expected: direct navigation to every dashboard route compiles and remains protected.

### Task 5: Create Dashboard Pages

**Files:**
- Create: `frontend/app/pages/dashboard/index.vue`
- Create: `frontend/app/pages/dashboard/transcriptions/index.vue`
- Create: `frontend/app/pages/dashboard/transcriptions/[folderId].vue`
- Create: `frontend/app/pages/dashboard/translations.vue`
- Create: `frontend/app/pages/dashboard/billing.vue`
- Create: `frontend/app/pages/dashboard/settings.vue`

**Interfaces:**
- Overview renders `TranscriptionForm`.
- Transcriptions index reads search, sort, and page from route query parameters.
- Folder detail reads `folderId` from route params.

- [ ] **Step 1: Move overview content to the dashboard index page**

Include the welcome banner, transcription form, and summary cards.

- [ ] **Step 2: Make folder filters URL state**

Use `search`, `sort`, and `page` query parameters so browser history and page refresh preserve the view.

- [ ] **Step 3: Make folders addressable routes**

Folder rows navigate to `/dashboard/transcriptions/:folderId` rather than changing local component state.

- [ ] **Step 4: Add focused placeholder pages**

Translations, billing, and settings each become independent route components using the dashboard layout.

- [ ] **Step 5: Run the production build**

Expected: all dashboard routes appear in the generated Nuxt route manifest.

### Task 6: Extract Folder and Transcript Components

**Files:**
- Create: `frontend/app/components/folders/CreateFolderModal.vue`
- Create: `frontend/app/components/folders/FolderList.vue`
- Create: `frontend/app/components/transcription/TranscriptEditorModal.vue`
- Create: `frontend/app/components/transcription/TranscriptionList.vue`

**Interfaces:**
- `CreateFolderModal` emits `created(folder)`.
- `FolderList` receives folder pagination data and emits page changes.
- `TranscriptionList` receives `FolderTranscription[]` and emits `select(transcription)`.
- `TranscriptEditorModal` receives a transcription and emits `updated(transcription)`.

- [ ] **Step 1: Extract folder creation and listing**

Keep API mutation ownership in the modal and API query ownership in the page.

- [ ] **Step 2: Extract responsive transcription listing**

Preserve mobile list and desktop table presentations in one focused component.

- [ ] **Step 3: Extract transcript editing and exports**

Preserve export invalidation after edits and temporary download links.

- [ ] **Step 4: Run final verification**

Run: `npm.cmd run build`

Expected: build succeeds, no manual route checks remain, and `app.vue` is only the application shell.

