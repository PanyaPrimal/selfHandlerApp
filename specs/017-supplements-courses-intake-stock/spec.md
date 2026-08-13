# Feature Specification: Supplements, Courses, Intake, and Stock

**Feature ID**: `017-supplements-courses-intake-stock`

**Created**: 2026-08-13

**Status**: Complete

**Input**: Deliver the non-deployment Supplements vertical slice from the canonical design: private
supplement/medication references, bounded courses on shared recurrence, planned and actual intake,
escalating reminders, exact remaining stock, run-out forecasts, one-off restock proposals, module-owned
adherence, and the shared EN/RU/UK clients without medical recommendations.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Maintain a Neutral Supplement Catalogue (Priority: P1)

The user records what they already intend to take: a name, neutral category and form, usual dose,
package quantity, stock unit, optional notes, and restock lead time. They can correct, archive, or
restore their own reference without the product recommending a substance, dose, combination, or
regimen.

**Why this priority**: courses, intake facts, and stock need one trustworthy user-owned reference.

**Independent Test**: Create gram-, millilitre-, and piece-based references, edit/archive/restore
them, and verify canonical conversion, validation, privacy, and consistently neutral language.

**Acceptance Scenarios**:

1. **Given** a user enters 500 mg, **When** the reference is accepted, **Then** the system preserves
   the preferred display unit and stores the exact canonical gram quantity without floating-point
   drift.
2. **Given** a tablet/capsule or a liquid/powder, **When** its stock unit is selected, **Then** dose
   and package quantities are compatible with pieces, millilitres, or grams respectively.
3. **Given** an archived reference, **When** history is read, **Then** its label and facts remain
   visible, but no new course can select it until it is restored.
4. **Given** another account's reference, **When** it is addressed, **Then** the response reveals no
   existence signal and changes nothing.

---

### User Story 2 - Follow a Bounded Flexible Course (Priority: P1)

The user creates a course from a start date to an end date or for a number of days, optionally links
an owned goal, chooses a user-entered dose, and schedules one or several daily slots. The schedule can
be daily, every N days, selected weekdays every N weeks, and optionally N days on/M days off.

**Why this priority**: the course is the plan that shared recurrence, Planner, and reminders consume.

**Independent Test**: Create daily twice-per-day, alternate-day, selected-weekday, and 7-on/7-off
courses; verify exact occurrences, times, intake contexts, bounds, pause/resume/archive, and owner
isolation across a daylight-saving transition.

**Acceptance Scenarios**:

1. **Given** two ordered slots, **When** the course is saved, **Then** shared recurrence materializes
   one uniquely keyed occurrence per date/slot with the intended local time.
2. **Given** an interval or on/off cycle, **When** any bounded range is expanded, **Then** dates are
   derived from the course start date in the Profile timezone and repeated expansion is idempotent.
3. **Given** a course end date or duration, **When** the schedule is expanded, **Then** no occurrence
   lies outside the inclusive course bounds.
4. **Given** a course is paused, archived, shortened, or edited, **When** it is rematerialized, **Then**
   unacted future predictions reconcile while fact-bearing or explicitly rescheduled history remains.

---

### User Story 3 - Record and Correct Actual Intake (Priority: P1)

The user opens a planned intake and marks it taken or skipped. A taken fact snapshots the accepted
dose and local/UTC time; a skipped fact consumes no stock. They can correct the outcome, dose, time,
or note, or clear the fact and return the occurrence to planned.

**Why this priority**: actual intake is the authoritative domain fact for adherence and consumption.

**Independent Test**: Take, skip, correct, and clear planned occurrences in different slots; verify
idempotency, occurrence status, immutable snapshots, stock effects, reminder closure, and privacy.

**Acceptance Scenarios**:

1. **Given** a planned occurrence, **When** it is marked taken, **Then** exactly one owned intake fact
   is linked, its occurrence becomes done, and its exact accepted dose is counted once.
2. **Given** a planned occurrence, **When** it is skipped, **Then** exactly one skip fact is linked,
   its occurrence becomes skipped, and no stock is consumed.
3. **Given** a previously accepted intake, **When** it is corrected or cleared, **Then** every derived
   adherence, stock, forecast, Planner, Today, Review, and notification state follows the new fact.
4. **Given** a retry or concurrent duplicate, **When** the same occurrence is updated, **Then** at
   most one fact exists and no dose is double-counted.

---

### User Story 4 - Track Stock and Act on a Run-out Forecast (Priority: P1)

The user records restocks and signed inventory corrections, sees exact remaining stock after taken
facts, and sees when the current active courses will exhaust it. When exhaustion approaches inside
the chosen lead time, Supplements owns one one-off restock proposal and reminder.

**Why this priority**: stock visibility and timely replenishment are the primary value beyond a
checklist, and must not be confused with another recurring schedule.

**Independent Test**: Add stock, consume it through overlapping courses, correct facts and stock,
and verify remaining quantity, forecast states, one active proposal, dismissal/reopening, and no
recurring restock rule.

**Acceptance Scenarios**:

1. **Given** restocks/corrections and taken facts, **When** stock is read, **Then** remaining quantity
   is their exact canonical sum and may honestly show zero or a negative discrepancy.
2. **Given** active future course occurrences, **When** the forecast is calculated, **Then** it uses
   each course's dose and slot frequency and returns an explainable run-out date or a specific
   unavailable/beyond-horizon state.
3. **Given** run-out falls within lead time, **When** proposal state is reconciled, **Then** at most
   one active one-off proposal exists for the supplement and no RecurringRule is created for restock.
4. **Given** a restock, correction, course change, intake correction, or dismissal, **When** state is
   reloaded, **Then** stale proposals close and only a materially new shortage may open a new one.

---

### User Story 5 - Receive Reminders and Review Adherence (Priority: P2)

The user receives a localized reminder at each timed planned intake and up to three configured
escalations while it remains planned. Planner shows course occurrences, and Supplements supplies one
bounded adherence summary reused by Today and Review.

**Why this priority**: the shared cross-module surfaces make a course actionable without transferring
ownership of intake or adherence.

**Independent Test**: Deliver, escalate, action, skip, reschedule, disable, pause, and archive intake
notifications; compare Supplements, Planner, Today, and Review for a controlled range.

**Acceptance Scenarios**:

1. **Given** a timed planned intake, **When** its time arrives, **Then** a localized notification deep
   links to that course/date/slot and repeats only while the occurrence is still actionable.
2. **Given** taken, skipped, dismissed, disabled, paused, archived, or overdue terminal state, **When**
   notification state reconciles, **Then** escalation stops according to shared notification policy.
3. **Given** a bounded range, **When** adherence is read, **Then** taken, skipped, overdue, pending,
   and eligible totals are distinct and the percentage is derived by Supplements without rollups.
4. **Given** one selected day, **When** Planner, Today, and Review load, **Then** they consume the same
   occurrence/summary owners and never persist a duplicate intake or aggregate.

---

### User Story 6 - Use Supplements Across Current Clients (Priority: P3)

The user completes the catalogue, course, intake, stock, and proposal journeys on desktop or mobile
in EN/RU/UK and either scheme, including empty, loading, rejected, and correction states.

**Why this priority**: the module is complete only when its full daily loop is accessible in existing
clients rather than through API-only scaffolding.

**Independent Test**: Complete the primary journey at desktop and exact 390x844 dimensions in each
locale/theme, verify rollback/focus/ARIA/overflow, then synchronize and validate the Android shell.

**Acceptance Scenarios**:

1. **Given** a rejected catalogue, course, intake, or stock mutation, **When** the response arrives,
   **Then** accepted state is restored, the draft stays recoverable, and no false success is announced.
2. **Given** EN, RU, or UK, **When** category/form/unit/status/help/feedback/ARIA text renders, **Then**
   all product copy localizes while names and notes remain untouched.
3. **Given** keyboard, screen reader, or 390x844 use, **When** the user completes the primary flow,
   **Then** controls remain labelled, focus-visible, at least 44px, safe-area aware, and overflow-free.

## Edge Cases

- Weight inputs may use milligrams or grams but normalize to exact grams; volume uses millilitres and
  discrete units use whole pieces. Incompatible or non-positive quantities reject atomically.
- A reference stock unit may change only before any course, intake, or stock fact exists; otherwise a
  new reference is required so accepted facts cannot silently change dimensions.
- A course dose snapshots independently from later reference edits; a taken fact snapshots again from
  the accepted course/action so history never drifts.
- A new course starts on or after the current Profile-local date. Its Supplement link never changes;
  importing a course/intake history that predates feature use is a separate deferred workflow.
- A schedule must have unique non-empty slot codes and local times; selected-weekday schedules require
  weekdays, daily schedules forbid them, and intervals/cycles must be positive and bounded.
- A cycle is anchored to the course start; interval and cycle filtering are deterministic across DST.
- Course start/end edits cannot erase facts or user reschedules. A fact-bearing occurrence outside the
  new plan remains historical but does not generate a replacement prediction.
- A future occurrence cannot be marked taken with a future actual time; a skip may be recorded for the
  effective local day, but not for a foreign course/occurrence.
- Recording a taken fact is allowed when calculated stock is insufficient, because reality must be
  recordable; the negative remainder is an explicit discrepancy, not silently clamped.
- Stock movements are append-only facts. Corrections use signed compensating movements with a reason;
  editing/deleting past stock facts is not a hidden rewrite.
- Multiple active courses may consume one supplement. Forecasting combines them once per future
  occurrence and returns no-active-course, no-consumption, already-depleted, ready, or beyond-horizon.
- Forecasting is bounded to 730 local dates; a later possible run-out is labelled beyond-horizon rather
  than guessed. Course end before exhaustion is distinct from no active schedule.
- `no_stock` means neither inventory nor taken-intake facts exist; skipped intake facts do not establish
  stock. A zero/negative remainder after any stock or taken fact is
  `already_depleted`, uses the as-of date as run-out, and may create an immediate shortage proposal.
- Adherence counts elapsed eligible occurrences: done is numerator; done, skipped, and overdue planned
  occurrences form the denominator. Future pending occurrences never reduce the percentage.
- An occurrence rescheduled to another date uses its effective date/time for display, action, and
  notification while retaining its stable identity and slot.
- Dismissing a restock proposal closes that shortage signal; an unchanged forecast cannot immediately
  recreate it, while a materially different run-out state may create one new proposal.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Supplement references MUST be private user-owned rows with a neutral category, form,
  name, optional note, canonical stock unit, usual dose, optional package quantity, preferred display
  unit, restock lead days, and active/archive lifecycle.
- **FR-002**: Accepted categories and forms MUST be descriptive and extensible; product language MUST
  state that all substance, dose, timing, context, and course decisions were entered by the user.
- **FR-003**: Weight, volume, and discrete quantities MUST use exact fixed-scale canonical grams,
  millilitres, or whole pieces; supported display conversions MUST be deterministic and compatible.
- **FR-004**: Archived references MUST remain readable through history but MUST reject new courses;
  restore MUST not alter existing courses or facts, and stock unit MUST become immutable once any
  related course or fact exists.
- **FR-005**: A course MUST be user-owned, link one owned supplement and optionally one owned goal,
  snapshot an explicit dose, carry an inclusive start/end, active/paused/archive lifecycle, start no
  earlier than the current local date at creation, keep its Supplement scope immutable, and never infer
  a regimen.
- **FR-006**: Course input MUST accept either an end date or a positive duration and derive one stable
  inclusive bound; inconsistent or reversed bounds MUST reject the complete write.
- **FR-007**: Every course MUST use the shared RecurringRule with daily or weekly frequency, interval,
  optional selected weekdays, optional positive on/off day cycle, timezone, and one or more unique
  ordered timed slots with neutral intake context.
- **FR-008**: Shared recurrence MUST expand interval and cycle patterns from the local start date,
  materialize multiple date/slot occurrences idempotently, and preserve legacy owner behavior.
- **FR-009**: Course create/update/pause/resume/archive MUST transactionally reconcile the 90-day
  materialized window, preserving every fact-bearing or explicitly rescheduled occurrence.
- **FR-010**: A SupplementIntake MUST be the authoritative one-per-occurrence user-owned fact with
  taken/skipped outcome, accepted dose/unit/label snapshot, actual timestamp where applicable, and
  optional note.
- **FR-011**: Taken, skipped, corrected, and cleared actions MUST be idempotent, owner-scoped, and
  transactionally synchronize PlannedOccurrence status/fact linkage without partial state.
- **FR-012**: Taken facts MUST consume their exact snapshot dose once; skipped or cleared facts MUST
  consume zero; corrections MUST immediately replace the derived contribution.
- **FR-013**: Intake action constraints MUST use the occurrence's effective Profile-local date/time,
  reject impossible future facts, and preserve the stable occurrence identity after reschedule.
- **FR-014**: Stock changes MUST be immutable user-owned restock or signed correction facts with exact
  canonical quantity, local effective date, optional note, and a required correction reason.
- **FR-015**: Remaining stock MUST be derived from stock facts minus taken intake snapshots, MUST NOT
  use a mutable counter or stored rollup, and MUST expose zero/negative discrepancy distinctly.
- **FR-016**: Supplements MUST forecast combined consumption from all live owned courses using the
  same recurrence semantics, bounded to 730 dates, and expose input quantities plus a machine-readable
  ready/unavailable/depleted/beyond-horizon result.
- **FR-017**: Forecasting MUST be correction-safe and MUST distinguish course ending before stock
  exhaustion from absence of an active course, absence of consumption, no inventory history, and an
  exhausted/negative balance after facts; exhausted balance MUST use the as-of date as run-out.
- **FR-018**: A shortage inside the reference lead time MUST create/reconcile at most one active
  one-off restock proposal with forecast date, needed-by date, optional suggested package quantity,
  unit, and lifecycle; an already depleted balance MUST qualify immediately, and neither case may
  create a recurring rule or finance fact.
- **FR-019**: A dismissed proposal MUST remain closed for the same shortage fingerprint; restock,
  stock correction, course/intake change, or materially new forecast MUST close/reconcile stale state.
- **FR-020**: Shared Notifications MUST add a supplement category/type, localized intake delivery and
  up to three configurable escalations that stop on done, skipped, dismiss, disabled category, inactive
  course, archive, or non-actionable date.
- **FR-021**: A one-off restock proposal MAY source one non-escalating localized notification and a
  safe module deep link; proposal and notification lifecycle MUST reconcile together.
- **FR-022**: Supplements MUST expose selected-day occurrences plus bounded ordered adherence for up
  to 366 dates, distinguishing done, skipped, overdue, pending, eligible, and unavailable percentage.
- **FR-023**: Adherence MUST be derived from shared occurrences and intake facts without a persisted
  mutable rollup; done divided by elapsed eligible occurrences is the documented percentage.
- **FR-024**: Planner MUST expose supplement occurrences through its source registry while leaving
  dose, intake, stock, proposal, and adherence ownership in Supplements.
- **FR-025**: Today `module_summaries` MUST add one backward-compatible Supplements DTO and Review MUST
  present it without recomputing or persisting a duplicate aggregate.
- **FR-026**: All reference/course/occurrence/intake/stock/proposal operations MUST use Sanctum, strict
  closed requests, eager/bounded loading, authenticated owner-derived IDs, 404 isolation, concurrency
  guards, exact OpenAPI 3.1, and TypeScript parity.
- **FR-027**: `/supplements` MUST expose the full reference/course/intake/stock/forecast/proposal loop,
  selected-day navigation, adherence, neutral safety framing, and deep-link restoration.
- **FR-028**: All product copy, validation, feedback, empty/error/loading states, reminders, statuses,
  units, and ARIA text MUST ship together in EN/RU/UK with locale-aware date/number/unit formatting and
  untranslated user content.
- **FR-029**: Desktop and exact 390x844 layouts MUST work in light/dark schemes, keyboard and screen
  reader use, 44px touch targets, safe areas, and no horizontal overflow.
- **FR-030**: The shared web implementation MUST synchronize into the existing Android Capacitor shell
  without native Supplements ownership, offline authority, or a second schedule store.
- **FR-031**: The feature MUST update contracts, recurrence/notification/module docs, changelog,
  roadmap, and ownership documentation while leaving deployment and the user handoff untouched.
- **FR-032**: Package price/cost, finance transaction or budget creation, medical/therapeutic advice,
  AI regimen generation, provider import, attachments, arbitrary RRULE, and universal consumables MUST
  remain deferred; bulk import of pre-existing course/intake history is also deferred.

### Key Entities

- **Supplement**: private neutral reference with dose/package/stock-unit defaults and lifecycle.
- **SupplementCourse**: bounded user-entered regimen owner linked to Supplement and shared recurrence.
- **RecurringRule / PlannedOccurrence**: shared schedule and durable date/slot identity, extended with
  interval, cycle, multiple slots, and a SupplementIntake fact link.
- **SupplementIntake**: correctable authoritative taken/skipped fact with accepted dose snapshot.
- **SupplementStockMovement**: immutable restock or compensating correction fact.
- **SupplementStockForecast**: non-persisted module-owned projection from facts and future recurrence.
- **SupplementRestockProposal**: persisted one-off shortage action, never a recurrence or finance fact.
- **SupplementAdherenceSummary**: non-persisted bounded aggregate reused by Today and Review.

## Success Criteria *(mandatory)*

- **SC-001**: Two accounts expose zero references, courses, intakes, stock movements, forecasts, or
  proposals belonging to each other, including through shared Planner/notification endpoints.
- **SC-002**: Known mg/g/ml/piece fixtures preserve exact quantities through reference, course, intake,
  correction, stock, forecast, and JSON round trips with no floating-point drift.
- **SC-003**: Daily multi-slot, every-other-day, selected-weekday interval, and 7-on/7-off fixtures
  match hand-expanded dates across DST; repeat/concurrent materialization creates zero duplicates and
  does not change legacy recurrence fixtures.
- **SC-004**: Taken/skipped/corrected/cleared lifecycle leaves exactly one or zero fact per occurrence,
  exact occurrence status, exact stock contribution, and no live stale reminder.
- **SC-005**: Controlled overlapping-course fixtures yield exact remaining stock and run-out state;
  every fact/course/stock correction updates it, and at most one valid active proposal exists.
- **SC-006**: Intake reminders deliver at local slot time and create no more than three repeats while
  pending; every documented terminal state stops the family deterministically.
- **SC-007**: Supplements, Planner, Today, and Review agree on selected-day status and the same bounded
  adherence fixtures before and after every correction.
- **SC-008**: Maximum 366-day adherence and 730-day forecast reads stay within documented fixed query
  budgets as occurrences, intakes, stock facts, and courses grow.
- **SC-009**: Every authenticated operation matches registered routes, closed OpenAPI schemas, and
  TypeScript consumers with zero undocumented request/response field.
- **SC-010**: EN/RU/UK light/dark desktop/mobile screenshots, keyboard, ARIA, overflow, notification,
  and neutral-language probes reveal no untranslated product copy, clipping, collision, medical claim,
  or runtime error.
- **SC-011**: Focused and full Laravel, Pint, i18n, typecheck, Vitest, build, Playwright, mobile Node,
  Capacitor, protected-path, handoff, and dependency-audit gates pass with exact evidence before commit.

## Assumptions

- The user already decided what to take and provides every substance, dose, course, schedule, and
  context; the application records and reminds but does not validate clinical appropriateness.
- Weight may be entered in milligrams or grams but persists canonically as grams. Stock for discrete
  capsules/tablets uses whole pieces; liquid uses millilitres.
- One course dose applies to every slot in that course. Different slot doses require separate courses,
  preserving clear facts and forecasts without hidden per-slot regimens.
- New course tracking begins today or later; once created, durable occurrences retain correction
  history as the course progresses.
- Every course slot has a local time so Planner order and reminder eligibility are deterministic.
- A 730-date transparent horizon is sufficient for actionable run-out forecasting; later exhaustion is
  explicitly unknown rather than estimated from an average rate.
- Package quantity may be absent; the proposal can still state when replenishment is needed without
  inventing how much to buy.

## Dependencies

- `003-multi-user-auth` ownership and Sanctum boundary.
- `004-profile-settings` timezone, locale, units, date/time, and optional Goal ownership checks.
- `005-interface-foundation` controls and responsive shell.
- `006-unified-recurrence` shared rules, occurrences, materialization, reschedule, and reconciliation.
- `009-planner-day` source registry and effective-day actions.
- `010-interface-personalization` EN/RU/UK and theme foundation.
- `011-in-app-notifications` localized delivery, settings, escalation, quiet hours, and deep links.
- `012-android-capacitor-shell` shared bundle.

## Explicit Deferrals

- Deployment and every feature-002/live operation.
- Medical advice, contraindication/interactions checking, diagnosis, protocols, recommended doses,
  suggested substances/combinations, and AI-generated regimens.
- Package price, cost per intake/day/month, currency, transaction/budget creation, and savings hints;
  features 018 and 020 add the authoritative Money/Profile/Finance linkage.
- Barcode/provider/pharmacy import, receipt/photo recognition, attachment uploads, and prescriptions.
- Bulk import/backfill of courses or intake facts from before the user starts tracking in SelfHandler.
- Arbitrary RFC 5545 input, count-based recurrence, and extracting a universal consumables framework.
- Long-period charts/correlations (022), export/report files (023), and AI (026).
