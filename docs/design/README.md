# SelfHandler — Design Docs

Detailed system design: vision, module specifications, data schemas, and cross-cutting mechanisms. These documents are the foundation for implementation (Laravel API + Vue web + Capacitor mobile).

> These docs describe a **fully designed** system. Implementation proceeds in layers, starting from the MVP slice (see [../MVP.md](../MVP.md)). Not everything that has been designed ships in the first version.

## Where to start

1. **[vision.md](vision.md)** — product vision, 11 modules, stack, roadmap. The entry point.
2. **[modules.md](modules.md)** — detailed specification of each module (what we track, fields, behavior, relationships). The basis for the database schema.
3. **[decisions.md](decisions.md)** — log of product and architectural decisions plus the "why" behind them. What's settled, what's deferred.

## Cross-cutting mechanisms (shared across all modules)

Designed once and reused everywhere — to be laid down before any code is written:

- **[data-conventions.md](data-conventions.md)** — schema conventions: money (Money/DECIMAL), "base + type" polymorphism, `user_id` from day one, deletion vs. archiving, time zones, units, aggregation strategy.
- **[recurrence-engine.md](recurrence-engine.md)** — recurrence engine (`RecurringRule` + `PlannedOccurrence`): schedules, courses, recurring payments, habits. One format for the entire application.
- **[notifications.md](notifications.md)** — notification subsystem: channels (in-app/push/email/telegram), escalation, quiet hours, daily digest.
- **[attachments.md](attachments.md)** — attachments (photos/documents/tracks): polymorphic association + disk abstraction.
- **[integrations.md](integrations.md)** — external integrations (Google/Apple calendars, later Strava/Garmin/banks): a single contract + adapters.
- **[llm-layer.md](llm-layer.md)** — the optional AI layer: per-module Level-2 scenarios on top of the deterministic baseline, the context/tool-calling contract, and privacy/safety boundaries (BYOK).

## Diagrams

- **[finance-er.md](finance-er.md)** — ER diagram for the Finance module (accounts, transactions, budget, debts, saving funds, emergency fund). Mermaid diagram + invariants.

## Related documents

- [../ARCHITECTURE.md](../ARCHITECTURE.md) — high-level architecture of the monorepo
- [../MVP.md](../MVP.md) — MVP slice (where implementation begins)
- [../MVP_TECHNICAL_DESIGN.md](../MVP_TECHNICAL_DESIGN.md) — technical contract for the first MVP slice
- [../OPEN_SERVER.md](../OPEN_SERVER.md) — local backend runtime (Open Server)

## Status

All 11 modules and cross-cutting mechanisms are designed. The next step is layered implementation starting from the MVP. The "before the first migration" checklist is at the end of [data-conventions.md](data-conventions.md).
