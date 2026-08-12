# Implementation Plan: Interface Personalisation and Complete Localisation

**Branch**: `master` (existing user branch; no branch operation) | **Date**: 2026-08-13 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/010-interface-personalization/spec.md`

## Summary

Complete the existing profile-owned appearance value with safe preset/custom backgrounds, add a
strict locale/theme partial profile update, and introduce one typed reactive EN/RU/UK localisation
boundary for every current web and API user message. Prehydration caches prevent wrong-language/theme
first paint; successful session restoration always reconciles them to profile truth. One global
control offers language and quick light/dark switching on guest and authenticated screens. Repository
and Spec Kit gates make complete three-locale delivery mandatory thereafter.

## Technical Context

**Language/Version**: PHP 8.4, TypeScript 5.9, Vue 3.5, HTML/CSS, Node 22 tooling

**Primary Dependencies**: Laravel 12, Sanctum, Vue Router, Vite, browser `Intl`; no new runtime package

**Storage**: Existing MySQL 8/SQLite-test `user_profiles.locale` and `theme_preferences` JSON; local
storage is a best-effort first-paint cache

**Testing**: PHPUnit/Laravel feature tests, Pint, vue-tsc, Vite production build, Node localisation
gate, Playwright desktop and exact 390x844 projects

**Target Platform**: Modern desktop/mobile web browsers plus Laravel API

**Project Type**: Monorepo web application with same-origin REST API

**Performance Goals**: Cached locale/theme applied synchronously before first paint; language/theme
changes render within one Vue update; no additional network request during guest changes

**Constraints**: Complete current UI in three locales; profile truth after auth; no Account-draft
submission; 4.5:1 background contrast; additive data evolution; no deployment files or live systems

**Scale/Scope**: 12 current routes, shared control layer, static changelog, current API validation and
domain messages, three fixed locales, one profile preference endpoint

## Localisation Plan

**Message ownership**: `apps/web/src/i18n/locales/en.ts` is the canonical flat key set; `ru.ts` and
`uk.ts` satisfy the exact key type. Static changelog records use message keys. Laravel keeps matching
framework/domain catalogs under `apps/api/lang/{en,ru,uk}`.

**Runtime locale**: `apps/web/src/i18n/index.ts` owns reactive active/accepted locale state and the
versioned cache. `index.html` validates the cache and sets `html.lang` before mount. Session restore
reconciles profile locale/theme; sequence-numbered optimistic global changes ignore stale responses.

**Formatting**: Runtime wrappers use `Intl.DateTimeFormat`, `NumberFormat` and plural rules with the
active BCP-47 locale. Existing date-only parsing remains local-calendar construction. Domain enum
identifiers map to keys; user text is never translated.

**Backend feedback**: Every fetch includes `Accept-Language`. Locale middleware prefers an
authenticated profile, otherwise maps the header. Framework validation and repository domain strings
use translation keys. Stable warning codes stay behavior contracts.

**Delivery gates**: TypeScript exact locale typing, `npm run check:i18n` parity/used-key/hardcoded-copy
checks, API locale tests, Playwright all-route locale and first-paint/rollback journeys, typecheck/build.

## Constitution Check

### Before Research

1. **Specifications before implementation**: PASS — approved spec/checklist precede code changes.
2. **Design versus delivery truth**: PASS — new design boundary is in `docs/design/localization.md`;
   this directory is the increment contract.
3. **Thin slice/simplicity**: PASS — one existing preference aggregate, one current consumer set, no
   external framework or future module.
4. **Deterministic core/optional AI**: PASS — no AI; token derivation and formatting are deterministic.
5. **User ownership/privacy**: PASS — profile-derived ownership; caches contain presentation choices
   only; request cannot name an owner.
6. **Contracts/tests together**: PASS — PATCH delta, backend/frontend contracts and cross-app tests are
   planned together.
7. **Complete localisation**: PASS — this feature establishes and satisfies the complete gate.

### After Design

PASS. Research resolves runtime, cache authority, races, partial update, API locale and safe token
derivation. The data model is additive and the OpenAPI delta rejects unknown keys. No constitution
exception or complexity waiver is required.

## Architecture Gates

1. **Owner**: Profile owns locale/theme; the web i18n runtime owns presentation state only.
2. **Inputs**: locale/theme are read from Profile and never copied into a domain module.
3. **Time**: no persisted instant changes; existing date-only and time-zone behavior is preserved.
4. **Scheduling**: none.
5. **Money/units**: canonical storage unchanged; display uses active locale/profile units.
6. **Ownership/privacy**: authenticated profile is derived from session; no new personal data.
7. **Contracts**: strict PATCH delta; existing PUT/GET responses remain compatible.
8. **Failure/idempotency**: validation is atomic; optimistic state rolls back; stale responses ignored.
9. **Accessibility/responsiveness**: accessible names, keyboard operation, focus, contrast and 390x844
   overflow are acceptance gates in all three locales.
10. **Deferral**: notifications and all later roadmap modules remain out of scope; deployment excluded.

## Project Structure

### Documentation

```text
specs/010-interface-personalization/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── analysis.md
├── tasks.md
├── checklists/requirements.md
└── contracts/openapi.yaml
```

### Source

```text
apps/api/
├── app/Http/Middleware/UseRequestLocale.php
├── app/Http/Requests/UpdatePreferencesRequest.php
├── app/Http/Controllers/ProfileController.php
├── app/Models/UserProfile.php
├── lang/{en,ru,uk}/
└── tests/Feature/Profile/

apps/web/
├── scripts/check-i18n.mjs
├── src/i18n/{index.ts,locales/*.ts}
├── src/components/GlobalPreferences.vue
├── src/content/changelog.ts
├── src/theme.ts
├── src/views/*.vue
├── src/layouts/AppShell.vue
├── src/components/**/*.vue
├── src/api/{http.ts,client.ts,types.ts}
└── e2e/{localization,preferences}/
```

**Structure Decision**: Keep the existing Laravel/Vue monorepo boundaries. The API changes only
because profile persistence and localized server feedback are required. No shared package is created.

## Implementation Sequence

1. Freeze governance, roadmap numbering and Spec Kit artifacts.
2. Add failing focused API and browser/localisation checks.
3. Implement typed i18n runtime, catalogs, prehydration and request locale.
4. Implement generalized preference PATCH, background normalization/derivation and global control.
5. Migrate shared controls and every route, enum/formatter and changelog to canonical keys.
6. Localize backend validation/domain feedback and add contract tests.
7. Complete focused then full gates, reconcile docs/tasks, commit atomically and push.

## Complexity Tracking

No constitution violations.
