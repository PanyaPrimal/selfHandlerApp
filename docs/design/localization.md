# Localisation

This document is the long-term localisation boundary for SelfHandler. Feature specifications remain
the delivery source of truth for the copy they add.

## Supported Locales

- `en-GB` — English and the canonical message-key set;
- `ru-UA` — Russian;
- `uk-UA` — Ukrainian.

The authenticated user's `UserProfile.locale` is authoritative. Before authentication, a versioned
local cache supplies the guest locale and prevents a first-paint language flash. Session restoration
reconciles that cache to the profile; the cache never overrides an authenticated profile.

## Translation Boundary

All user-visible product text is translated, including navigation, headings, labels, placeholders,
helpers, buttons, loading/empty/success/error states, validation and domain messages, ARIA labels,
title attributes, static changelog content, and enum/status labels. Brand names, API paths, CSS token
names, user-entered content, and developer-only diagnostics are not translated.

English defines the canonical typed keys. Russian and Ukrainian must have exact key parity. A missing
runtime key falls back to English and emits a development warning, but a missing key is a failing
repository gate and may not ship.

## Formatting

Dates, numbers, currencies, units and plural forms use the active profile locale through `Intl`.
Calendar dates remain date-only values and are never parsed as UTC instants. User-entered names,
notes, titles, tags and other content are rendered verbatim.

## Delivery Rule

Every future feature specification identifies its user-text surface. Its plan chooses message keys,
formatting and any backend localisation work; its tasks add all three translations and run parity,
used-key and hardcoded-copy checks. Adding English-only UI is an incomplete feature.

## API Errors

The web client sends its active locale on API and CSRF requests. The API selects the authenticated
profile locale when a user is known and otherwise uses the supported request locale. Framework
validation, authentication feedback and domain validation use translated message keys. Stable domain
warning codes remain the client contract; translated prose is display-only.
