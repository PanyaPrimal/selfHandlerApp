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
