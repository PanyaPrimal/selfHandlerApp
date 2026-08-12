<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { changelogEntries } from '../content/changelog'
import { formatCalendarDate } from '../lib/format'
import { useI18n } from '../i18n'

const i18n = useI18n()
const locale = i18n.locale
const entries = computed(() => changelogEntries)
</script>

<template>
  <section class="view-stack changelog-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ i18n.t('changelog.eyebrow') }}</p>
        <h1>{{ i18n.t('changelog.title') }}</h1>
        <p class="muted">{{ i18n.t('changelog.subtitle') }}</p>
      </div>
    </header>

    <ol class="changelog-list">
      <li v-for="entry in entries" :id="entry.id" :key="entry.id" class="panel changelog-entry">
        <div class="changelog-entry__head">
          <div>
            <h2>{{ i18n.t(entry.titleKey) }}</h2>
            <p class="changelog-entry__meta">
              <time class="mono" :datetime="entry.date">{{ formatCalendarDate(entry.date, locale) }}</time>
              <span class="kind-chip">{{ entry.feature }}</span>
            </p>
          </div>
        </div>

        <p>{{ i18n.t(entry.summaryKey) }}</p>

        <div class="changelog-entry__test">
          <strong>{{ i18n.t('changelog.howToTest') }}</strong>
          <p>{{ i18n.t(entry.testKey) }}</p>
        </div>

        <ul v-if="entry.limitationKeys?.length" class="changelog-entry__limits">
          <li v-for="limitationKey in entry.limitationKeys" :key="limitationKey">{{ i18n.t(limitationKey) }}</li>
        </ul>

        <nav v-if="entry.links?.length" class="changelog-entry__links" :aria-label="i18n.t('changelog.openSection')">
          <RouterLink v-for="link in entry.links" :key="link.to" class="changelog-link" :to="link.to">
            {{ i18n.t(link.labelKey) }}
          </RouterLink>
        </nav>
      </li>
    </ol>
  </section>
</template>
