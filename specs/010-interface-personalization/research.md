# Research: Interface Personalisation and Complete Localisation

## R1 — Localisation Runtime

**Decision**: Use a small repository-owned reactive TypeScript runtime with a flat canonical English
catalog, compile-time key typing, exact `satisfies` checks for Russian/Ukrainian, interpolation,
plural selection and `Intl` formatters.

**Rationale**: The current web application has no i18n dependency and needs only three fixed locales.
Vue reactivity plus the platform's `Intl` implementation covers the required behavior while keeping
keys and fallback policy directly testable. The runtime exposes one active-locale ref and pure
formatters, so controls and views update without reload.

**Alternatives considered**:

- `vue-i18n`: capable, but adds a large general dependency for a compact fixed-locale catalog and
  does not remove the need for custom repository gates.
- DOM post-processing/English-string replacement: rejected because it flashes, misses ARIA and
  programmatic feedback, cannot type keys and can alter user content.
- Separate localized components: rejected because it triples behavior code and invites drift.

## R2 — Canonical Keys and Governance Gates

**Decision**: English is the canonical flat key object. Locale modules must satisfy its key type. A
Node gate loads/parses all catalogs, extracts static `t('key')` references, recognizes explicit
dynamic-key families, and scans checked Vue/TypeScript product surfaces for unapproved hardcoded copy.
Technical literals, brand text and user data are narrow documented exceptions.

**Rationale**: Compile-time parity catches missing and extra locale keys during typecheck; the
standalone gate produces faster actionable failures and checks unused/unknown keys plus copy that
never entered a catalog. Spec Kit governance changes make this a continuing delivery rule.

**Alternatives considered**:

- Review-only policy: rejected because later features can silently regress complete translation.
- Runtime missing-key fallback alone: retained as resilience, rejected as a delivery check.
- Broad allowlists by directory/file: rejected because they would hide future product copy.

## R3 — Locale Ownership, Cache and Races

**Decision**: `UserProfile.locale` is authoritative after authentication. A versioned local locale
cache is the guest preference and pre-paint copy. `index.html` applies a validated cached locale to
`<html lang>` before mount. Session restoration replaces it with the profile. Authenticated changes
use optimistic sequence-numbered partial PATCH requests; only the latest result may accept or roll
back state.

**Rationale**: This prevents first-paint flash without turning browser storage into domain state.
Sequence numbers prevent a slow earlier request from overwriting a later selection. Account draft
state is untouched because the global selector never submits the profile form.

**Alternatives considered**:

- Browser locale as default: rejected because the product already defines `en-GB` as profile default
  and the requested choices must be explicit/deterministic.
- Wait for `/user` before rendering: rejected because guest and slow/unavailable flows still need a
  language and would show a blank/skeleton delay.
- Update locale through the full Profile PUT: rejected because it can validate or overwrite unrelated
  unsaved fields.

## R4 — Partial Preference Contract

**Decision**: Generalize the existing authenticated `PATCH /api/profile` from theme-only to a strict
partial `preferences` object containing at least one of `locale` or a complete `theme`. Reject unknown
top-level/nested keys. Validate all submitted changes before one atomic profile write and return the
existing full profile/options response.

**Rationale**: The route already owns appearance preference changes. Extending it preserves the
existing endpoint while allowing locale-only updates and combined atomic saves. A complete theme
value makes rollback and normalization simple; locale remains independently optional.

**Alternatives considered**:

- New locale endpoint: workable, but duplicates preference update concerns and response behavior.
- JSON Merge Patch: rejected as unnecessary semantics for two bounded preference fields.
- Full PUT: rejected by R3.

## R5 — API Locale Selection

**Decision**: Send the active BCP-47 locale in `Accept-Language` on API and CSRF requests. An API
middleware maps supported values to Laravel locales (`en`, `ru`, `uk`), preferring the authenticated
profile when available. Laravel validation catalogs and repository domain-message keys provide all
three languages. Stable warning codes remain authoritative for client behavior.

**Rationale**: Guest registration/login validation needs the guest locale; authenticated requests
must not let an arbitrary header override profile truth. Translation keys eliminate hardcoded domain
copy while preserving existing error shapes.

**Alternatives considered**:

- Client-only mapping of every server message: rejected because framework messages would remain
  English and prose matching is brittle.
- Locale query parameter: rejected because language is request metadata, not resource identity.

## R6 — Background Persistence and Backward Compatibility

**Decision**: Extend the existing theme JSON with `background` (`paper`, `sand`, `mist`, `sage`, or
`custom`) and `background_hex`. Runtime and model normalization fill these fields for old rows and
caches. No database migration is needed because the existing nullable JSON column remains the
storage boundary.

**Rationale**: This is additive, preserves live rows and lets theme save remain one atomic value.
The default reproduces current tokens exactly.

**Alternatives considered**:

- Dedicated background columns: rejected because the data is presentation-only and belongs to the
  already version-tolerant theme value.
- Store derived CSS tokens: rejected because derivation may improve and redundant values can drift.

## R7 — Safe Custom Background Algorithm

**Decision**: A validated six-digit HEX is a tint input, not a literal page background. For light
scheme it is mixed toward white; for dark scheme toward near-black. Secondary surfaces/borders/text
are derived from the resulting page using bounded mixes. The algorithm increases the safety mix when
needed until normal text on paper and surface reaches at least 4.5:1. Only named CSS custom
properties receive normalized computed colours.

**Rationale**: Arbitrary saturated/mid-tone page colours would break the existing ink, borders,
notices and inputs. A constrained tint preserves personal expression while guaranteeing readable
light/dark palettes and never accepting a CSS expression.

**Alternatives considered**:

- Apply raw HEX directly: rejected for contrast and component-coherence failures.
- Reject most colours based on raw contrast: rejected because the colour can safely influence a tint
  even when it is unsuitable as a literal surface.
- Save two user-authored colours: rejected because it increases work and still cannot guarantee the
  complete token system.

## R8 — Quick Theme Toggle

**Decision**: Put one global preference control outside routed content. The quick toggle changes only
`scheme` to the opposite of the current resolved scheme, leaving every other theme field intact. Guest
changes update cache; authenticated changes share the partial preference client and rollback logic.

**Rationale**: One component covers login, registration, startup/unavailable and every authenticated
route without duplicating state. Converting `system` to an explicit opposite is predictable for a
button that promises an immediate light/dark switch.

## R9 — Changelog and Enum Labels

**Decision**: Static changelog entries contain identifiers/dates/routes plus message keys, not one
hardcoded language. Domain enums returned by APIs are mapped to typed client keys; user-entered names,
titles and notes stay verbatim.

**Rationale**: The changelog is current UI and must follow the active locale. Enum values are contract
identifiers, not user copy. The current duplicate changelog identifier is removed while restructuring
the static content.

## R10 — Verification Scope

**Decision**: Add focused API tests for preference patching/locale errors, frontend unit-like script
checks for catalog/tokens, and Playwright journeys for prehydration, guest/auth reconciliation,
rollback, Account draft preservation, backgrounds, all routes/locales, keyboard operation and exact
390x844 overflow. Run the full existing backend and browser suites for regression.

**Rationale**: The feature is cross-cutting: focused tests prove its special invariants while the full
suite proves that translating every view did not alter behavior.
