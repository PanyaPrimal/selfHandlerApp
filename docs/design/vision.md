# SelfHandler

> A single app for self-management: routine, health, goals, tasks, ideas.

---

## The Problem

Everything about your personal life is scattered across dozens of places: Google Keep, spreadsheets, chats, notes. Ideas slip through the cracks, plans get forgotten, and there's no record of any of it. What's needed is **a single point of entry** for everything that touches self-management.

---

## Core Modules

### 1. Daily Routine & Sleep
- Schedule planning for the next day / week
- Sleep tracking (bedtime, wake-up time, quality)
- Day templates (workday, day off, training day)

### 2. Nutrition & Supplements
- Meal planning
- A tracker for supplements and medications (what, when, dosage)
- Intake reminders

### 3. Workouts
- Different workout types for different goals (strength, cardio, flexibility, and so on)
- Planning of training programs
- Logging of completed workouts

### 4. Goals
- Setting goals across different horizons:
  - **Long-term** (a year or more)
  - **Mid-term** (a month to a quarter)
  - **Short-term** (a week)
- Linking daily actions to goals
- Progress tracking

### 5. Daily Review (evening check-in)
- What got done during the day, per module
- A self-assessment of the day
- Adjusting tomorrow's plan

### 6. Planner
- **For tomorrow** — a concrete plan for the day
- **For the week** — an overview and priorities
- Plan generation based on goals, workouts, and schedule

### 7. Storage
- **Tasks** — incoming, prioritized, organized by project
- **Ideas** — quick capture → inbox → triage → shaping into a plan/project
- **Lists** — books, films, series, anything (wishlists)
- **Shopping wishlist** — linked to ideas (idea → the purchases needed to make it happen)
  - An idea and its dependent purchases live together instead of drifting across separate lists
  - A purchase's status affects the idea's status (a blocker)

### 8. Habits & Anti-habits
- **Habits** — things you want to do regularly (streaks for following through)
- **Anti-habits** — things you want to quit (streaks for staying clean)
  - For example: "No doomscrolling reels — 14 days straight"
  - Streak resets on a slip, with a history of your best runs
  - Visual motivation: the longer the streak, the more it hurts to lose it
- Linking to goals (a habit/anti-habit as a tool for reaching a goal)

### 9. Analytics & Visualization
- A dashboard with charts and graphs for the routines you track
- Combo streaks (runs of days without a miss)
- Progress visualization: sleep, workouts, nutrition, habits
- Trends and patterns (week over week, month over month)
- Correlations (how sleep affects productivity, and so on)
- Bars and charts showing how engaged and successful you are with each routine

### 10. Finance
- Income and expense tracking
- Spending categorization
- Investments and savings — tracking your portfolio / nest egg
- Budgeting (planned vs. actual)
- Financial goals (tied into the Goals module)
- Analytics: where the money goes, month-over-month dynamics

---

## Rhythms & Reviews

### Daily (evening)
- Mark routines as done / not done
- Fill in tomorrow's planner

### Weekly (Sunday)
- Weekly review: what worked, what didn't
- Planning the week ahead

### Monthly
- Monthly review, adjusting goals
- Planning the month ahead

### Routine recurrence types
- Multiple times a day (for example, supplements in the morning and evening)
- Once daily
- Several times a week (Mon/Wed/Fri)
- Several times a month

---

## Interaction Principles

- **Quick capture** — drop an idea or task in seconds, with zero friction
- **Single point of entry** — everything in one place, no external spreadsheets
- **Evening ritual** — the daily review and planning for tomorrow
- **Weekly review** — priorities and a course correction

---

## Technology Stack

| Layer | Technology | Notes |
|------|-----------|---------|
| **Backend** | PHP 8.3 + Laravel 11 | REST API, queues, schedules |
| **Database** | MySQL 8 | Primary data store |
| **Frontend (web)** | Vue.js 3 (no meta-framework) | SPA, Composition API, Vite |
| **Mobile** | Capacitor (wrapper around the Vue app) | Android APK, potentially iOS |
| **Charts** | Chart.js or ApexCharts (Vue wrappers) | Dashboards and analytics |
| **Cache/queues** | Redis | For Laravel Queue + caching |
| **VPN** | WireGuard | Direct access, no middlemen |
| **Server** | Firebat N100 (16GB DDR5, 512GB) | Homelab, WSL2 + Docker |

### Containers (Docker Compose)
- `nginx` — reverse proxy
- `php-fpm` — Laravel backend
- `mysql` — database
- `redis` — cache and queues
- `node` — frontend build (dev)

### Clients
- **Web** — browser on Windows (primary)
- **Android** — APK via Capacitor (the same Vue code)
- **Future** — the architecture allows adding iOS / macOS without a rewrite

---

## Technology Roadmap

The order in which the stack comes up — from infrastructure to features:

1. **Docker + WSL2** — a containerized development environment
2. **Laravel** — routing, controllers, migrations, Eloquent ORM
3. **MySQL** — schema, relationships, queries
4. **REST API** — endpoints, resources, validation
5. **Vue 3** — reactivity, components, Composition API
6. **Wiring frontend to backend** — HTTP client, auth, CORS
7. **Capacitor** — wrapping the Vue app into an Android APK
8. **WireGuard** — a VPN for reaching the homelab from outside
9. **Analytics** — Chart.js / ApexCharts, dashboards

---

## Open questions

- [x] ~~Platform~~ → Web + Android (Capacitor), architecture built for expansion
- [x] ~~Technology stack~~ → Laravel + MySQL + Vue 3 + Capacitor
- [x] ~~External access to the homelab~~ → WireGuard
- [ ] Authentication: single-user for now, but built with multi-user in mind
- [x] ~~Notifications and reminders — push, Telegram bot, email?~~ → the [Notifications](notifications.md) subsystem: a unified channel contract, in-app first, with push/telegram/email as adapters later
- [x] ~~Integrations with anything existing?~~ → a shared [Integrations](integrations.md) layer: calendars (Google/Apple, two-way) first; Strava/Garmin/Apple Health (running) and bank statements later via the same contract
