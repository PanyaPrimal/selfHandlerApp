<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { changelogEntries } from '../content/changelog'
import { formatCalendarDate } from '../lib/format'
import { useAuthSession } from '../auth/session'

const session = useAuthSession()
const locale = computed(() => session.user?.preferences.locale ?? 'en-GB')
const entries = computed(() => changelogEntries)
</script>

<template>
  <section class="view-stack changelog-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">Changelog</p>
        <h1>Что нового в SelfHandler</h1>
        <p class="muted">Свежие изменения сверху. У каждого — короткое описание и способ проверить.</p>
      </div>
    </header>

    <ol class="changelog-list">
      <li v-for="entry in entries" :id="entry.id" :key="entry.id" class="panel changelog-entry">
        <div class="changelog-entry__head">
          <div>
            <h2>{{ entry.title }}</h2>
            <p class="changelog-entry__meta">
              <time class="mono" :datetime="entry.date">{{ formatCalendarDate(entry.date, locale) }}</time>
              <span class="kind-chip">{{ entry.feature }}</span>
            </p>
          </div>
        </div>

        <p>{{ entry.summary }}</p>

        <div class="changelog-entry__test">
          <strong>Как проверить</strong>
          <p>{{ entry.howToTest }}</p>
        </div>

        <ul v-if="entry.limitations?.length" class="changelog-entry__limits">
          <li v-for="limitation in entry.limitations" :key="limitation">{{ limitation }}</li>
        </ul>

        <nav v-if="entry.links?.length" class="changelog-entry__links" aria-label="Перейти к разделу">
          <RouterLink v-for="link in entry.links" :key="link.to" class="changelog-link" :to="link.to">
            {{ link.label }}
          </RouterLink>
        </nav>
      </li>
    </ol>
  </section>
</template>
