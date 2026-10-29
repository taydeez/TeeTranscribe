# Resumable R2 uploads implementation plan

**Goal:** Upload large media directly to R2 and resume after network interruption or a page refresh.

**Architecture:** A domain upload service uses repository and multipart-storage contracts. Infrastructure persists owned ULID sessions and uses the existing AWS SDK for R2. Browser upload state is stored in IndexedDB, while R2 ListParts is authoritative. File bytes never pass through Laravel or Nuxt.

**Tech stack:** Laravel 13, existing AWS SDK 3, Nuxt 4, IndexedDB, XMLHttpRequest.

**Constraints:** Signed-in multipart uploads up to 5 GiB; 16 MiB parts; seven-day expiry; existing guest single uploads stay limited to 100 MiB. No new dependencies. Do not run migrations against the user's database.

## Tasks

- [x] Add owned upload sessions, domain entity and contracts, service, Eloquent repository, and R2 multipart gateway. Serialize mutations, verify every part's number/size and final object, and recover a completed R2 object when its database update failed.
- [x] Add authenticated start/status/sign-part/complete/abort endpoints and expired-session cleanup. Update the Postman collection and CORS ETag exposure check.
- [x] Add browser fingerprinting and IndexedDB session recovery. Upload one part at a time, obtain fresh signed URLs on retries, support pause/resume/cancel, and retain the completed object if transcription submission fails.
- [x] Integrate the uploader with the form, keep URL transcription and guest upload working, show genuine progress and refresh recovery guidance.
- [x] Test ownership, idempotent start/completion, missing/wrong parts, interrupted completion recovery, abort and expiry. Run targeted feature/unit tests, Pint, and all frontend checks.

**Verification:** 31 backend feature/unit tests passed; 11 frontend unit tests passed; architecture, ESLint, TypeScript, production build, and Pint passed. R2 API calls were exercised with the AWS SDK mock handler; a live bucket upload has not been tested. The migration has not been run against the user's database.
