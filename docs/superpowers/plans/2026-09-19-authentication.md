# Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add email/password and Google authentication, Spatie roles and permissions, Redis-backed login limits, and email verification codes for administrator logins.

**Architecture:** Keep authentication use cases in `App\Domain\Auth` and administrator-only operations in `App\Domain\Admin`. Controllers and Eloquent models remain infrastructure/HTTP adapters; services depend on contracts. Sanctum issues API tokens, while the Nuxt frontend stores the returned token for authenticated API requests.

**Tech Stack:** Laravel 13, Sanctum, Spatie Permission, Laravel Socialite, Redis rate limiting, Nuxt 4.

**Spec:** User request in this conversation.

## Global Constraints

- Use `App\Domain\Admin\{Feature}` for administrator behavior.
- Do not pass Eloquent models into domain services.
- Update `docs/teetranscribe.postman_collection.json` for every API route.
- Admin email/password logins require an emailed one-time code.

### Task 1: Persistence and domain contracts

**Files:** migrations, `App\Domain\Auth`, `App\Infrastructure\Persistence\Eloquent`.

- [ ] Add Google identity and two-factor code persistence.
- [ ] Add domain user value object and repository contracts.
- [ ] Adapt the Eloquent user repository without exposing Eloquent to domain services.

### Task 2: Authentication use cases and HTTP routes

**Files:** `App\Domain\Auth\Services`, `App\Domain\Admin\TwoFactor`, requests, controllers, routes.

- [ ] Register email/password users with the `user` role.
- [ ] Authenticate standard users with Sanctum tokens.
- [ ] Authenticate Google users through Socialite.
- [ ] Require and verify an emailed code for administrator token issuance.
- [ ] Apply Redis-backed login throttling.

### Task 3: Frontend and API collection

**Files:** Nuxt app/proxy routes and Postman collection.

- [ ] Add account creation, login, Google redirect, and administrator-code screens.
- [ ] Persist the API token locally and add it to authenticated calls.
- [ ] Document every auth route in the Postman collection.

### Task 4: Tests and verification

**Files:** feature and unit tests.

- [ ] Cover registration, login, throttling, admin code verification, and authorization.
- [ ] Verify relevant frontend production build and focused backend tests.
