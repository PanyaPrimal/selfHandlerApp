# Requirements Checklist: Interface Personalisation and Complete Localisation

**Purpose**: Verify that the feature contract is complete and testable before planning.
**Created**: 2026-08-13
**Feature**: [spec.md](../spec.md)

## User Value and Scope

- [x] CHK001 All four user journeys are prioritized and independently testable.
- [x] CHK002 Guest, authenticated, desktop, mobile, success, failure and reload behavior are explicit.
- [x] CHK003 Background personalisation, complete current-UI translation and global controls form one
  coherent profile-preference feature.
- [x] CHK004 Deployment and later roadmap modules are explicitly excluded.

## Data, Contracts and Safety

- [x] CHK005 Profile versus cache authority and reconciliation are explicit.
- [x] CHK006 Partial preference updates cannot submit or overwrite Account drafts.
- [x] CHK007 Backward compatibility, unknown fields, atomicity, rollback and request races are covered.
- [x] CHK008 Custom colour validation, token derivation and measurable contrast are specified.

## Localisation

- [x] CHK009 Every current user-text category includes English, Russian and Ukrainian.
- [x] CHK010 Validation/domain feedback, accessibility text, changelog and enum labels are included.
- [x] CHK011 Dates, numbers, currencies, units and plural forms are specified.
- [x] CHK012 Canonical-key parity, used-key and hardcoded-copy gates have negative-test outcomes.
- [x] CHK013 Profile locale, guest cache, API locale selection and first-paint behavior are unambiguous.

## Verification

- [x] CHK014 Success criteria are measurable and technology-independent at the user boundary.
- [x] CHK015 Ownership, existing-behavior regression and exact 390x844 coverage are required.
- [x] CHK016 No requirement remains marked NEEDS CLARIFICATION.
