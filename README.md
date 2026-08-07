# selfHandlerApp

Monorepo for the SelfHandler project.

## Purpose

SelfHandler is a personal system for managing routines, health, goals, tasks, ideas, and reviews in one place.

## Stack

- Backend: Laravel 11
- Database: MySQL 8
- Cache/queues: Redis
- Web: Vue 3 + Vite
- Mobile: Capacitor
- Local backend runtime: Open Server

## Monorepo Layout

- `apps/api` - Laravel API
- `apps/web` - Vue web client
- `apps/mobile` - Capacitor shell and mobile-specific setup
- `docs` - project docs and decisions

## First Milestones

1. Bootstrap monorepo structure.
2. Create Laravel API app.
3. Create Vue web app.
4. Attach Capacitor to the web client.
5. Configure Open Server workflow for local backend development.

## Spec-Driven Workflow

SelfHandler uses GitHub Spec Kit for feature delivery. Long-term product and domain design remains in
[`docs/design/`](docs/design/README.md); each implementation increment lives under `specs/` with its
own specification, plan, contracts, and dependency-ordered task list.

The project is initialized for Codex skills and PowerShell. Start a new feature with
`$speckit-specify`, then use `$speckit-plan`, `$speckit-tasks`, and `$speckit-analyze` before
implementation. Project governance is defined in
[`.specify/memory/constitution.md`](.specify/memory/constitution.md).

Spec Kit's Git extension is intentionally not installed. Work stays on the branch already selected by
the user; project automation must not create or switch branches.
