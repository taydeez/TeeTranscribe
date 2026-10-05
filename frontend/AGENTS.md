# Frontend engineering rules

These rules apply to every file under `frontend/`. They keep the Nuxt application easy to extend and prevent route, state, and feature logic from accumulating in one file.

## Required structure

- Keep `app/app.vue` as the application shell. It may render `NuxtRouteAnnouncer`, `NuxtLayout`, and `NuxtPage`; it must not contain feature logic, data fetching, page state, scripts, or styles.
- Put URL-addressable screens in `app/pages/`. Every dashboard page must declare `definePageMeta({ layout: 'dashboard' })`.
- Put shared page chrome such as the dashboard sidebar and header in `app/layouts/` and focused layout components.
- Put feature UI in `app/components/<feature>/`. Prefer one component per file and split components before they exceed 250 lines.
- Put reusable workflows and API orchestration in `app/composables/`. Split composables before they exceed 300 lines.
- Put shared API and domain contracts in `app/types/`; do not redefine the same response shape inside multiple components.
- Put stable option lists and application configuration in `app/config/`.
- Put server-only proxy and integration logic in `server/`. Secrets and provider credentials must never be exposed to browser code.

## Routing and state

- Use Nuxt routes for screens. Do not implement page navigation with `activePage`, `currentPageComponent`, or conditional rendering in a root component.
- Use `NuxtLink` active state or route metadata for navigation styling. Do not manually compare `route.path` inside pages or components.
- Store search, sorting, pagination, filters, and shareable selections in route query parameters.
- Keep modal, dropdown, recording, and form state local to the owning component or composable.
- Use Pinia only for state that must survive route changes or be shared across unrelated features. Authentication belongs in Pinia; ordinary API results do not.

## Data flow

- Use `useAuthenticatedFetch` for authenticated browser requests so unauthorized responses clear the session and redirect consistently.
- Keep provider calls, credentials, and backend URL construction in Nuxt server routes and server utilities.
- Give every data-dependent view explicit loading, error, empty, and success states.
- Define request and response types before wiring a new endpoint into UI components.

## Change workflow

For each frontend feature:

1. Create or identify its route and layout.
2. Define shared request and response types.
3. Add the server proxy or composable that owns the request workflow.
4. Build focused feature components and keep page files responsible for composition.
5. Handle loading, error, empty, and success states.
6. Run `npm run check` from `frontend/` before considering the work complete.

`npm run check` runs the architecture rules, ESLint, Nuxt type checking, and a production build. New code must pass all four checks.
