<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  clearSupplementIntake,
  createSupplement,
  createSupplementCourse,
  createSupplementStockMovement,
  dismissSupplementRestockProposal,
  getSupplementAdherence,
  getSupplementCourses,
  getSupplementDay,
  getSupplements,
  getSupplementStockMovements,
  getToday,
  updateSupplement,
  updateSupplementCourse,
  upsertSupplementIntake,
  validationErrors,
} from '../api/client'
import type { Supplement, SupplementAdherenceRange, SupplementCourse, SupplementCourseInput, SupplementDay, SupplementInput, SupplementStockMovement, SupplementStockMovementInput } from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import { UiDatePicker } from '../components/ui'
import AdherenceCard from '../components/supplements/AdherenceCard.vue'
import CourseEditor from '../components/supplements/CourseEditor.vue'
import ForecastCard from '../components/supplements/ForecastCard.vue'
import IntakeEditor from '../components/supplements/IntakeEditor.vue'
import StockEditor from '../components/supplements/StockEditor.vue'
import SupplementEditor from '../components/supplements/SupplementEditor.vue'
import { useI18n } from '../i18n'

type Tab = 'day' | 'catalogue' | 'courses' | 'stock'
const route = useRoute()
const router = useRouter()
const i18n = useI18n()
const selectedDate = ref('')
const today = ref('')
const activeTab = ref<Tab>('day')
const supplements = ref<Supplement[]>([])
const courses = ref<SupplementCourse[]>([])
const day = ref<SupplementDay | null>(null)
const adherence = ref<SupplementAdherenceRange | null>(null)
const movements = ref<Record<number, SupplementStockMovement[]>>({})
const editingSupplement = ref<Supplement | null>(null)
const editingCourse = ref<SupplementCourse | null>(null)
const stockSupplement = ref<Supplement | null>(null)
const showSupplementEditor = ref(false)
const showCourseEditor = ref(false)
const loading = ref(true)
const busyKey = ref<string | null>(null)
const error = ref<string | null>(null)
const loadError = ref<string | null>(null)
const feedback = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

const activeSupplements = computed(() => supplements.value.filter((item) => !item.is_archived))
const highlightedCourse = computed(() => Number(route.query.course || 0))

function minusDays(date: string, count: number): string {
  const value = new Date(`${date}T12:00:00Z`)
  value.setUTCDate(value.getUTCDate() - count)
  return value.toISOString().slice(0, 10)
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    if (!today.value) today.value = (await getToday()).date
    if (!selectedDate.value) selectedDate.value = typeof route.query.date === 'string' ? route.query.date : today.value
    const [referenceResponse, courseResponse, selectedDay, range] = await Promise.all([
      getSupplements('all'), getSupplementCourses('all'), getSupplementDay(selectedDate.value),
      getSupplementAdherence(minusDays(selectedDate.value, 6), selectedDate.value),
    ])
    supplements.value = referenceResponse.data
    courses.value = courseResponse.data
    day.value = selectedDay
    adherence.value = range
    const restockId = Number(route.query.restock || 0)
    if (restockId) activeTab.value = 'stock'
  } catch (current) {
    loadError.value = current instanceof Error ? current.message : i18n.t('supplements.loadFailed')
  } finally {
    loading.value = false
  }
}

async function chooseDate(value: string | null): Promise<void> {
  if (!value) return
  selectedDate.value = value
  await router.replace({ query: { ...route.query, date: value } })
  await load()
}

async function saveSupplement(payload: SupplementInput): Promise<void> {
  busyKey.value = 'reference'
  fieldErrors.value = {}
  error.value = null
  try {
    editingSupplement.value
      ? await updateSupplement(editingSupplement.value.id, payload)
      : await createSupplement(payload)
    feedback.value = i18n.t(editingSupplement.value ? 'supplements.referenceUpdated' : 'supplements.referenceCreated')
    showSupplementEditor.value = false
    editingSupplement.value = null
    await load()
  } catch (current) {
    fieldErrors.value = validationErrors(current)
    error.value = current instanceof Error ? current.message : i18n.t('supplements.saveFailed')
  } finally { busyKey.value = null }
}

async function toggleSupplement(item: Supplement): Promise<void> {
  busyKey.value = `reference-${item.id}`
  try { await updateSupplement(item.id, { is_archived: !item.is_archived }); await load() }
  catch (current) { error.value = current instanceof Error ? current.message : i18n.t('supplements.saveFailed') }
  finally { busyKey.value = null }
}

async function saveCourse(payload: SupplementCourseInput): Promise<void> {
  busyKey.value = 'course'
  error.value = null
  try {
    if (editingCourse.value) {
      const { supplement_id: _, ...update } = payload
      await updateSupplementCourse(editingCourse.value.id, update)
    } else await createSupplementCourse(payload)
    feedback.value = i18n.t(editingCourse.value ? 'supplements.courseUpdated' : 'supplements.courseCreated')
    editingCourse.value = null
    showCourseEditor.value = false
    await load()
  } catch (current) { error.value = current instanceof Error ? current.message : i18n.t('supplements.saveFailed') }
  finally { busyKey.value = null }
}

async function changeCourse(course: SupplementCourse, attributes: { is_active?: boolean, is_archived?: boolean }): Promise<void> {
  busyKey.value = `course-${course.id}`
  try { await updateSupplementCourse(course.id, attributes); await load() }
  catch (current) { error.value = current instanceof Error ? current.message : i18n.t('supplements.saveFailed') }
  finally { busyKey.value = null }
}

async function saveIntake(occurrenceId: number, payload: Parameters<typeof upsertSupplementIntake>[1]): Promise<void> {
  busyKey.value = `intake-${occurrenceId}`
  try { await upsertSupplementIntake(occurrenceId, payload); feedback.value = i18n.t('supplements.intakeSaved'); await load() }
  catch (current) { error.value = current instanceof Error ? current.message : i18n.t('supplements.saveFailed') }
  finally { busyKey.value = null }
}

async function clearIntake(occurrenceId: number): Promise<void> {
  busyKey.value = `intake-${occurrenceId}`
  try { await clearSupplementIntake(occurrenceId); feedback.value = i18n.t('supplements.intakeCleared'); await load() }
  catch (current) { error.value = current instanceof Error ? current.message : i18n.t('supplements.saveFailed') }
  finally { busyKey.value = null }
}

async function openStock(item: Supplement): Promise<void> {
  stockSupplement.value = item
  activeTab.value = 'stock'
  movements.value[item.id] = await getSupplementStockMovements(item.id)
}

async function saveStock(payload: SupplementStockMovementInput): Promise<void> {
  if (!stockSupplement.value) return
  busyKey.value = 'stock'
  try { await createSupplementStockMovement(stockSupplement.value.id, payload); feedback.value = i18n.t('supplements.stockSaved'); await load(); await openStock(supplements.value.find((item) => item.id === stockSupplement.value?.id)!) }
  catch (current) { error.value = current instanceof Error ? current.message : i18n.t('supplements.saveFailed') }
  finally { busyKey.value = null }
}

async function dismissProposal(id: number): Promise<void> {
  busyKey.value = `proposal-${id}`
  try { await dismissSupplementRestockProposal(id); feedback.value = i18n.t('supplements.proposalDismissed'); await load() }
  catch (current) { error.value = current instanceof Error ? current.message : i18n.t('supplements.saveFailed') }
  finally { busyKey.value = null }
}

watch(() => route.query.date, (value) => {
  if (typeof value === 'string' && value !== selectedDate.value) void chooseDate(value)
})
onMounted(load)
</script>

<template>
  <section class="view-stack supplements-page">
    <header class="view-header supplements-header">
      <div><p class="eyebrow">{{ i18n.t('supplements.eyebrow') }}</p><h1>{{ i18n.t('supplements.title') }}</h1><p class="muted">{{ i18n.t('supplements.subtitle') }}</p></div>
      <UiDatePicker :model-value="selectedDate || null" name="supplements-date" :label="i18n.t('supplements.selectedDate')" :locale="i18n.locale.value" :today="today || null" @update:model-value="chooseDate" />
    </header>
    <div v-if="feedback" class="notice success" role="status" aria-live="polite">{{ feedback }}</div>
    <div v-if="error" class="notice error" role="alert">{{ error }}</div>
    <div class="supplement-tabs" role="tablist" :aria-label="i18n.t('supplements.sections')">
      <button v-for="tab in (['day', 'catalogue', 'courses', 'stock'] as Tab[])" :key="tab" type="button" role="tab" :aria-selected="activeTab === tab" :class="{ 'is-active': activeTab === tab }" @click="activeTab = tab">{{ i18n.t(`supplements.tab.${tab}` as never) }}</button>
    </div>
    <AsyncState :loading="loading" :error="loadError" :empty="false" :loading-title="i18n.t('common.loading')" @retry="load">
      <template v-if="day && activeTab === 'day'">
        <AdherenceCard :summary="day.summary" />
        <section class="panel" aria-labelledby="intakes-heading">
          <div class="section-heading"><div><h2 id="intakes-heading">{{ i18n.t('supplements.plannedIntakes') }}</h2><p class="muted">{{ i18n.t('supplements.intakeHelp') }}</p></div></div>
          <div v-if="day.occurrences.length" class="supplement-occurrences">
            <article v-for="occurrence in day.occurrences" :key="occurrence.id" class="supplement-occurrence" :class="{ 'is-highlighted': highlightedCourse === occurrence.course_id }">
              <header><div><span class="token-caption">{{ occurrence.time }} · {{ i18n.t(`supplements.context.${occurrence.intake_context}` as never) }}</span><h3>{{ occurrence.course_name }}</h3><p class="muted">{{ occurrence.dose_quantity }} {{ i18n.t(`supplements.unit.${occurrence.dose_display_unit}` as never) }} · {{ i18n.t(`supplements.status.${occurrence.status}` as never) }}</p></div></header>
              <IntakeEditor :occurrence="occurrence" :busy="busyKey === `intake-${occurrence.id}`" @save="saveIntake(occurrence.id, $event)" @clear="clearIntake(occurrence.id)" />
            </article>
          </div>
          <p v-else class="empty-copy">{{ i18n.t('supplements.noIntakes') }}</p>
        </section>
        <section v-if="adherence" class="panel"><h2>{{ i18n.t('supplements.recentAdherence') }}</h2><div class="adherence-days"><div v-for="item in adherence.days" :key="item.date"><span>{{ item.date.slice(5) }}</span><strong>{{ item.adherence_percentage === null ? '—' : `${item.adherence_percentage}%` }}</strong></div></div></section>
      </template>

      <section v-else-if="activeTab === 'catalogue'" class="panel" aria-labelledby="catalogue-heading">
        <div class="section-heading"><div><h2 id="catalogue-heading">{{ i18n.t('supplements.catalogue') }}</h2><p class="muted">{{ i18n.t('supplements.neutralHelp') }}</p></div><button type="button" @click="editingSupplement = null; showSupplementEditor = true">{{ i18n.t('supplements.addReference') }}</button></div>
        <SupplementEditor v-if="showSupplementEditor" :supplement="editingSupplement" :busy="busyKey === 'reference'" :errors="fieldErrors" @save="saveSupplement" @cancel="showSupplementEditor = false" />
        <div class="supplement-grid">
          <article v-for="item in supplements" :key="item.id" class="supplement-card" :class="{ 'is-muted': item.is_archived }">
            <header><div><span class="token-caption">{{ i18n.t(`supplements.category.${item.category}` as never) }} · {{ i18n.t(`supplements.form.${item.form}` as never) }}</span><h3>{{ item.name }}</h3></div><span class="status-chip">{{ item.is_archived ? i18n.t('common.archived') : i18n.t('common.current') }}</span></header>
            <ForecastCard :supplement="item" :busy="busyKey === `proposal-${item.restock_proposal?.id}`" @dismiss="dismissProposal" />
            <div class="form-actions"><button type="button" class="secondary" @click="editingSupplement = item; showSupplementEditor = true">{{ i18n.t('common.edit') }}</button><button type="button" class="ghost" :disabled="busyKey === `reference-${item.id}`" @click="toggleSupplement(item)">{{ i18n.t(item.is_archived ? 'supplements.restore' : 'supplements.archive') }}</button><button type="button" class="text-button" @click="openStock(item)">{{ i18n.t('supplements.manageStock') }}</button></div>
          </article>
        </div>
      </section>

      <section v-else-if="activeTab === 'courses'" class="panel" aria-labelledby="courses-heading">
        <div class="section-heading"><div><h2 id="courses-heading">{{ i18n.t('supplements.courses') }}</h2><p class="muted">{{ i18n.t('supplements.courseHelp') }}</p></div><button type="button" :disabled="activeSupplements.length === 0" @click="editingCourse = null; showCourseEditor = true">{{ i18n.t('supplements.addCourse') }}</button></div>
        <CourseEditor v-if="showCourseEditor" :supplements="activeSupplements" :course="editingCourse" :today="today" :busy="busyKey === 'course'" @save="saveCourse" @cancel="showCourseEditor = false" />
        <div class="course-list"><article v-for="course in courses" :key="course.id" class="course-card" :class="{ 'is-muted': course.is_archived }"><header><div><span class="token-caption">{{ course.starts_on }} — {{ course.ends_on }}</span><h3>{{ course.name || course.supplement_name }}</h3><p class="muted">{{ course.dose_quantity }} {{ i18n.t(`supplements.unit.${course.dose_display_unit}` as never) }} · {{ course.schedule.slots.length }} {{ i18n.t('supplements.slots').toLocaleLowerCase(i18n.locale.value) }}</p></div><span class="status-chip">{{ course.is_archived ? i18n.t('common.archived') : course.is_active ? i18n.t('supplements.active') : i18n.t('supplements.paused') }}</span></header><div class="course-slots"><span v-for="slot in course.schedule.slots" :key="slot.slot">{{ slot.time }} · {{ i18n.t(`supplements.context.${slot.intake_context}` as never) }}</span></div><div class="form-actions"><button type="button" class="secondary" @click="editingCourse = course; showCourseEditor = true">{{ i18n.t('common.edit') }}</button><button v-if="!course.is_archived" type="button" class="ghost" @click="changeCourse(course, { is_active: !course.is_active })">{{ i18n.t(course.is_active ? 'supplements.pause' : 'supplements.resume') }}</button><button type="button" class="text-button" @click="changeCourse(course, { is_archived: !course.is_archived })">{{ i18n.t(course.is_archived ? 'supplements.restore' : 'supplements.archive') }}</button></div></article></div>
      </section>

      <section v-else class="panel" aria-labelledby="stock-heading">
        <div class="section-heading"><div><h2 id="stock-heading">{{ i18n.t('supplements.stock') }}</h2><p class="muted">{{ i18n.t('supplements.stockHelp') }}</p></div></div>
        <div class="stock-picker"><button v-for="item in activeSupplements" :key="item.id" type="button" class="secondary" :class="{ 'is-active': stockSupplement?.id === item.id }" @click="openStock(item)">{{ item.name }}</button></div>
        <template v-if="stockSupplement"><ForecastCard :supplement="stockSupplement" :busy="busyKey?.startsWith('proposal')" @dismiss="dismissProposal" /><StockEditor :key="`${stockSupplement.id}-${movements[stockSupplement.id]?.length ?? 0}`" :supplement="stockSupplement" :today="today" :busy="busyKey === 'stock'" @save="saveStock" @cancel="stockSupplement = null" /><div class="movement-list"><article v-for="movement in movements[stockSupplement.id] ?? []" :key="movement.id"><strong>{{ movement.quantity_delta }} {{ i18n.t(`supplements.unit.${movement.stock_unit}` as never) }}</strong><span>{{ i18n.t(`supplements.${movement.kind}` as never) }} · {{ movement.effective_on }}</span><small v-if="movement.reason" class="muted">{{ movement.reason }}</small></article></div></template>
        <p v-else class="empty-copy">{{ i18n.t('supplements.chooseStockReference') }}</p>
      </section>
    </AsyncState>
  </section>
</template>
