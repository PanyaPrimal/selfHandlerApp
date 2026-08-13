<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import {
  createNutritionFood,
  createNutritionMeal,
  createNutritionRecipe,
  deleteNutritionMeal,
  getBodyGoals,
  getNutritionDay,
  getNutritionFoods,
  getNutritionRecipes,
  getNutritionSettings,
  getNutritionSummary,
  updateNutritionFood,
  updateNutritionMeal,
  updateNutritionRecipe,
  updateNutritionSettings,
} from '../api/client'
import type {
  BodyGoal,
  FoodBasis,
  FoodItem,
  Meal,
  MealCategory,
  NutritionDay,
  NutritionLifecycleState,
  NutritionSettings,
  NutritionSummary,
  Recipe,
} from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import { UiCheckbox, UiDatePicker, UiNumberInput, UiSegmented, UiSelect, UiTextInput, UiTimeField } from '../components/ui'
import type { UiOption } from '../components/ui'
import { useI18n } from '../i18n'
import { createNutritionMutationQueue, nutritionTargetCopyKey } from '../nutrition/nutrition-state'

interface FoodDraft {
  name: string
  basis: FoodBasis
  beverage: boolean
  calories: number | null
  protein: number | null
  fat: number | null
  carbs: number | null
  quality: number | null
  hydration: number | null
}

interface RecipeComponentDraft { referenceId: number | null, grams: number | null }
interface MealEntryDraft { reference: string | null, quantity: number | null }

const route = useRoute()
const i18n = useI18n()
const locale = i18n.locale
const selectedDate = ref<string | null>(typeof route.query.date === 'string' ? route.query.date : localToday())
const isLoading = ref(true)
const isSaving = ref(false)
const loadFailed = ref(false)
const feedback = ref<string | null>(null)
const error = ref<string | null>(null)
const foods = ref<FoodItem[]>([])
const recipes = ref<Recipe[]>([])
const day = ref<NutritionDay | null>(null)
const history = ref<NutritionSummary[]>([])
const settings = ref<NutritionSettings | null>(null)
const goals = ref<BodyGoal[]>([])
const foodState = ref<NutritionLifecycleState>('active')
const recipeState = ref<NutritionLifecycleState>('active')
const editingFoodId = ref<number | null>(null)
const editingRecipeId = ref<number | null>(null)
const editingMealId = ref<number | null>(null)
const enqueue = createNutritionMutationQueue()

function emptyFood(): FoodDraft {
  return { name: '', basis: 'gram', beverage: false, calories: 0, protein: 0, fat: 0, carbs: 0, quality: null, hydration: 0 }
}

const foodForm = reactive<FoodDraft>(emptyFood())
const foodDraft = reactive<FoodDraft>(emptyFood())
const recipeForm = reactive({ name: '', description: '', components: [] as RecipeComponentDraft[] })
const recipeDraft = reactive({ name: '', description: '', components: [] as RecipeComponentDraft[] })
const mealForm = reactive({
  name: '', category: null as MealCategory | null, time: null as string | null, note: '', entries: [] as MealEntryDraft[],
})
const mealDraft = reactive({
  consumedOn: localToday(), name: '', category: null as MealCategory | null, time: null as string | null, note: '', entries: [] as MealEntryDraft[],
})
const settingsForm = reactive({ bodyGoalId: null as number | null, protein: 20, fat: 30, carbs: 50, water: null as number | null })

const basisOptions = computed<UiOption<FoodBasis>[]>(() => [
  { value: 'gram', label: i18n.t('nutrition.grams') },
  { value: 'millilitre', label: i18n.t('nutrition.millilitres') },
])
const stateOptions = computed<UiOption<NutritionLifecycleState>[]>(() => [
  { value: 'active', label: i18n.t('nutrition.state.activeFoods') },
  { value: 'archived', label: i18n.t('nutrition.state.archivedFoods') },
])
const recipeStateOptions = computed<UiOption<NutritionLifecycleState>[]>(() => [
  { value: 'active', label: i18n.t('nutrition.state.activeRecipes') },
  { value: 'archived', label: i18n.t('nutrition.state.archivedRecipes') },
])
const categoryOptions = computed<UiOption<MealCategory>[]>(() => [
  { value: 'breakfast', label: i18n.t('nutrition.category.breakfast') },
  { value: 'lunch', label: i18n.t('nutrition.category.lunch') },
  { value: 'dinner', label: i18n.t('nutrition.category.dinner') },
  { value: 'snack', label: i18n.t('nutrition.category.snack') },
  { value: 'custom', label: i18n.t('nutrition.category.custom') },
])
const solidOptions = computed<UiOption<number>[]>(() => foods.value
  .filter((food) => !food.is_archived && !food.is_beverage)
  .map((food) => ({ value: food.id, label: foodLabel(food) })))
const referenceOptions = computed<UiOption<string>[]>(() => [
  ...foods.value.filter((food) => !food.is_archived).map((food) => ({ value: `food:${food.id}`, label: foodLabel(food) })),
  ...recipes.value.filter((recipe) => !recipe.is_archived).map((recipe) => ({ value: `recipe:${recipe.id}`, label: recipe.name })),
])
const goalOptions = computed<UiOption<number>[]>(() => goals.value
  .filter((goal) => goal.status === 'active' && !goal.is_archived && goal.body?.metric === 'body_mass')
  .map((goal) => ({ value: goal.id, label: goal.name })))

function localToday(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function foodLabel(food: FoodItem): string {
  return food.system_key === 'plain_water' ? i18n.t('nutrition.plainWater') : food.name
}

function resetFood(target: FoodDraft): void {
  Object.assign(target, emptyFood())
}

function foodPayload(form: FoodDraft) {
  return {
    name: form.name,
    basis_unit: form.beverage ? 'millilitre' as const : form.basis,
    is_beverage: form.beverage,
    calories_per_100: form.calories ?? 0,
    protein_per_100: form.protein ?? 0,
    fat_per_100: form.fat ?? 0,
    carbs_per_100: form.carbs ?? 0,
    quality_score: form.beverage ? null : form.quality,
    hydration_ratio: form.beverage ? (form.hydration ?? 0) : 0,
  }
}

function clearMessages(): void {
  feedback.value = null
  error.value = null
}

async function loadAll(): Promise<void> {
  if (!selectedDate.value) return
  isLoading.value = true
  loadFailed.value = false
  clearMessages()
  try {
    const date = selectedDate.value
    const from = new Date(`${date}T12:00:00`)
    from.setDate(from.getDate() - 6)
    const fromDate = `${from.getFullYear()}-${String(from.getMonth() + 1).padStart(2, '0')}-${String(from.getDate()).padStart(2, '0')}`
    const [foodData, recipeData, dayData, settingsData, range, bodyGoals] = await Promise.all([
      getNutritionFoods(foodState.value), getNutritionRecipes(recipeState.value), getNutritionDay(date),
      getNutritionSettings(), getNutritionSummary(fromDate, date), getBodyGoals(),
    ])
    foods.value = foodData
    recipes.value = recipeData
    day.value = dayData
    settings.value = settingsData
    history.value = range.days
    goals.value = bodyGoals.data
    settingsForm.bodyGoalId = settingsData.body_goal_id
    settingsForm.protein = Number(settingsData.protein_percent)
    settingsForm.fat = Number(settingsData.fat_percent)
    settingsForm.carbs = Number(settingsData.carbs_percent)
    settingsForm.water = settingsData.water_override_ml
  } catch (reason) {
    loadFailed.value = true
    error.value = reason instanceof Error ? reason.message : i18n.t('nutrition.loadFailed')
  } finally {
    isLoading.value = false
  }
}

async function mutate(action: () => Promise<unknown>, message: string): Promise<void> {
  clearMessages()
  isSaving.value = true
  try {
    await enqueue(action)
    await loadAll()
    feedback.value = message
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : i18n.t('nutrition.saveFailed')
  } finally {
    isSaving.value = false
  }
}

async function createFood(): Promise<void> {
  await mutate(async () => {
    await createNutritionFood(foodPayload(foodForm))
    resetFood(foodForm)
  }, i18n.t('nutrition.foodCreated'))
}

function startFoodEdit(food: FoodItem): void {
  editingFoodId.value = food.id
  Object.assign(foodDraft, {
    name: food.name, basis: food.basis_unit, beverage: food.is_beverage,
    calories: Number(food.calories_per_100), protein: Number(food.protein_per_100),
    fat: Number(food.fat_per_100), carbs: Number(food.carbs_per_100),
    quality: food.quality_score === null ? null : Number(food.quality_score), hydration: Number(food.hydration_ratio),
  })
}

async function saveFood(food: FoodItem): Promise<void> {
  await mutate(async () => {
    await updateNutritionFood(food.id, foodPayload(foodDraft))
    editingFoodId.value = null
  }, i18n.t('nutrition.foodUpdated'))
}

async function toggleFood(food: FoodItem): Promise<void> {
  await mutate(() => updateNutritionFood(food.id, { is_archived: !food.is_archived }),
    i18n.t(food.is_archived ? 'nutrition.foodRestored' : 'nutrition.foodArchived'))
}

function addRecipeComponent(target: { components: RecipeComponentDraft[] }): void {
  target.components.push({ referenceId: null, grams: null })
}

function removeRecipeComponent(target: { components: RecipeComponentDraft[] }, index: number): void {
  target.components.splice(index, 1)
}

async function createRecipe(): Promise<void> {
  await mutate(async () => {
    await createNutritionRecipe({
      name: recipeForm.name, description: recipeForm.description || null,
      components: recipeForm.components.map((row) => ({ food_item_id: row.referenceId!, quantity_grams: row.grams! })),
    })
    Object.assign(recipeForm, { name: '', description: '', components: [] })
  }, i18n.t('nutrition.recipeCreated'))
}

function startRecipeEdit(recipe: Recipe): void {
  editingRecipeId.value = recipe.id
  Object.assign(recipeDraft, {
    name: recipe.name, description: recipe.description ?? '', components: recipe.components.map((component) => ({
      referenceId: component.food.id, grams: Number(component.quantity_grams),
    })),
  })
}

async function saveRecipe(recipe: Recipe): Promise<void> {
  await mutate(async () => {
    await updateNutritionRecipe(recipe.id, {
      name: recipeDraft.name, description: recipeDraft.description || null,
      components: recipeDraft.components.map((row) => ({ food_item_id: row.referenceId!, quantity_grams: row.grams! })),
    })
    editingRecipeId.value = null
  }, i18n.t('nutrition.recipeUpdated'))
}

async function toggleRecipe(recipe: Recipe): Promise<void> {
  await mutate(() => updateNutritionRecipe(recipe.id, { is_archived: !recipe.is_archived }),
    i18n.t(recipe.is_archived ? 'nutrition.recipeRestored' : 'nutrition.recipeArchived'))
}

function addMealEntry(target: { entries: MealEntryDraft[] }): void {
  target.entries.push({ reference: null, quantity: null })
}

function removeMealEntry(target: { entries: MealEntryDraft[] }, index: number): void {
  target.entries.splice(index, 1)
}

function entryPayload(entries: MealEntryDraft[]) {
  return entries.map((entry) => {
    const [kind, id] = (entry.reference ?? ':').split(':')
    return { food_item_id: kind === 'food' ? Number(id) : null, recipe_id: kind === 'recipe' ? Number(id) : null, quantity: entry.quantity! }
  })
}

async function createMeal(): Promise<void> {
  if (!selectedDate.value) return
  await mutate(async () => {
    await createNutritionMeal({
      consumed_on: selectedDate.value!, name: mealForm.name, category: mealForm.category,
      consumed_at_local: mealForm.time, note: mealForm.note || null,
      submission_key: crypto.randomUUID(), entries: entryPayload(mealForm.entries),
    })
    Object.assign(mealForm, { name: '', category: null, time: null, note: '', entries: [] })
  }, i18n.t('nutrition.mealCreated'))
}

function startMealEdit(meal: Meal): void {
  editingMealId.value = meal.id
  Object.assign(mealDraft, {
    consumedOn: meal.consumed_on, name: meal.name, category: meal.category, time: meal.consumed_at_local, note: meal.note ?? '',
    entries: meal.entries.map((entry) => ({
      reference: entry.food_item_id ? `food:${entry.food_item_id}` : `recipe:${entry.recipe_id}`,
      quantity: Number(entry.quantity),
    })),
  })
}

async function saveMeal(meal: Meal): Promise<void> {
  await mutate(async () => {
    await updateNutritionMeal(meal.id, {
      consumed_on: mealDraft.consumedOn, name: mealDraft.name, category: mealDraft.category,
      consumed_at_local: mealDraft.time, note: mealDraft.note || null, entries: entryPayload(mealDraft.entries),
    })
    editingMealId.value = null
  }, i18n.t('nutrition.mealUpdated'))
}

async function removeMeal(meal: Meal): Promise<void> {
  if (!window.confirm(i18n.t('nutrition.deleteMealConfirm', { name: meal.name }))) return
  await mutate(() => deleteNutritionMeal(meal.id), i18n.t('nutrition.mealDeleted'))
}

async function saveSettings(): Promise<void> {
  await mutate(() => updateNutritionSettings({
    body_goal_id: settingsForm.bodyGoalId, protein_percent: settingsForm.protein,
    fat_percent: settingsForm.fat, carbs_percent: settingsForm.carbs, water_override_ml: settingsForm.water,
  }), i18n.t('nutrition.settingsSaved'))
}

function selectDate(value: string | null): void {
  if (!value) return
  selectedDate.value = value
  void loadAll()
}

function format(value: string | number | null, suffix: string): string {
  return value === null ? i18n.t('common.notSet') : `${i18n.number(Number(value))} ${suffix}`
}

function refinementMessage(status: NutritionDay['refinement']['status']): string {
  return {
    available: i18n.t('nutrition.refinement.available'),
    incomplete_target: i18n.t('nutrition.refinement.incomplete_target'),
    no_completed_workouts: i18n.t('nutrition.refinement.no_completed_workouts'),
    missing_energy: i18n.t('nutrition.refinement.missing_energy', { count: day.value?.refinement.missing_actual_energy_count ?? 0 }),
  }[status]
}

onMounted(loadAll)
</script>

<template>
  <div class="view-stack nutrition-workspace">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ i18n.t('nutrition.eyebrow') }}</p>
        <h1>{{ i18n.t('nutrition.title') }}</h1>
        <p class="muted">{{ i18n.t('nutrition.subtitle') }}</p>
      </div>
      <UiDatePicker :model-value="selectedDate" :label="i18n.t('nutrition.date')" name="nutrition-date" :locale="locale" :today="localToday()" @update:model-value="selectDate" />
    </header>

    <p v-if="feedback" role="status" aria-live="polite" class="success-message">{{ feedback }}</p>
    <p v-if="error" role="alert" class="error-message">{{ error }}</p>
    <AsyncState :loading="isLoading" :error="loadFailed ? i18n.t('nutrition.loadFailed') : null" :empty="false" @retry="loadAll" />

    <template v-if="!isLoading && !loadFailed && day">
      <section class="panel" :aria-label="i18n.t('nutrition.progress')">
        <div class="section-heading">
          <div><p class="eyebrow">{{ i18n.t('nutrition.selectedDay') }}</p><h2>{{ i18n.t('nutrition.progress') }}</h2></div>
          <span>{{ day.summary.meal_count }} {{ i18n.t('nutrition.mealsShort') }}</span>
        </div>
        <div class="summary-grid nutrition-metrics">
          <article class="metric"><span>{{ i18n.t('nutrition.calories') }}</span><strong>{{ format(day.summary.calories, 'kcal') }}</strong><small>{{ format(day.target.calorie_target, 'kcal') }}</small></article>
          <article class="metric"><span>{{ i18n.t('nutrition.protein') }}</span><strong>{{ format(day.summary.protein_grams, 'g') }}</strong><small>{{ format(day.target.protein_target_grams, 'g') }}</small></article>
          <article class="metric"><span>{{ i18n.t('nutrition.fat') }}</span><strong>{{ format(day.summary.fat_grams, 'g') }}</strong><small>{{ format(day.target.fat_target_grams, 'g') }}</small></article>
          <article class="metric"><span>{{ i18n.t('nutrition.carbs') }}</span><strong>{{ format(day.summary.carbs_grams, 'g') }}</strong><small>{{ format(day.target.carbs_target_grams, 'g') }}</small></article>
          <article class="metric"><span>{{ i18n.t('nutrition.hydration') }}</span><strong>{{ format(day.summary.hydration_ml, 'ml') }}</strong><small>{{ format(day.target.water_target_ml, 'ml') }}</small></article>
          <article class="metric"><span>{{ i18n.t('nutrition.quality') }}</span><strong>{{ format(day.summary.quality_score, '/ 100') }}</strong><small>{{ format(day.target.quality_target, '/ 100') }}</small></article>
        </div>
      </section>

      <section class="panel" :aria-label="i18n.t('nutrition.dailyTarget')">
        <div class="section-heading"><div><p class="eyebrow">{{ i18n.t('nutrition.stableReference') }}</p><h2>{{ i18n.t('nutrition.dailyTarget') }}</h2></div><strong>{{ i18n.t(nutritionTargetCopyKey(day.target.status)) }}</strong></div>
        <p class="muted">{{ i18n.t('nutrition.targetEstimate') }}</p>
        <dl class="nutrition-breakdown">
          <div><dt>{{ i18n.t('nutrition.formula') }}</dt><dd>{{ day.target.formula }}</dd></div>
          <div><dt>{{ i18n.t('nutrition.bmr') }}</dt><dd>{{ format(day.target.bmr_kcal, 'kcal') }}</dd></div>
          <div><dt>{{ i18n.t('nutrition.baseline') }}</dt><dd>{{ format(day.target.baseline_kcal, 'kcal') }}</dd></div>
          <div><dt>{{ i18n.t('nutrition.goalAdjustment') }}</dt><dd>{{ format(day.target.goal_adjustment_kcal, 'kcal') }}</dd></div>
          <div><dt>{{ i18n.t('nutrition.plannedEnergy') }}</dt><dd>{{ format(day.target.planned_workout_kcal, 'kcal') }}</dd></div>
          <div><dt>{{ i18n.t('nutrition.refinement') }}</dt><dd>{{ format(day.refinement.refined_calorie_target, 'kcal') }}</dd></div>
        </dl>
        <p v-if="day.target.calculation_basis.missing_fields.length" class="muted">{{ i18n.t('nutrition.missingFields') }}: {{ day.target.calculation_basis.missing_fields.join(', ') }}</p>
        <p class="muted">{{ refinementMessage(day.refinement.status) }}</p>
      </section>

      <section class="panel">
        <div class="section-heading"><div><p class="eyebrow">{{ i18n.t('nutrition.references') }}</p><h2>{{ i18n.t('nutrition.foodCatalogue') }}</h2></div></div>
        <form class="form-grid" :aria-label="i18n.t('nutrition.createFood')" @submit.prevent="createFood">
          <UiTextInput v-model="foodForm.name" :label="i18n.t('nutrition.foodName')" name="food-name" required />
          <UiSelect v-model="foodForm.basis" :label="i18n.t('nutrition.basis')" name="food-basis" :options="basisOptions" />
          <UiCheckbox v-model="foodForm.beverage" :label="i18n.t('nutrition.beverage')" name="food-beverage" />
          <UiNumberInput v-model="foodForm.calories" :label="i18n.t('nutrition.caloriesPer100')" name="food-calories" :min="0" :step="0.001" />
          <UiNumberInput v-model="foodForm.protein" :label="i18n.t('nutrition.proteinPer100')" name="food-protein" :min="0" :step="0.001" />
          <UiNumberInput v-model="foodForm.fat" :label="i18n.t('nutrition.fatPer100')" name="food-fat" :min="0" :step="0.001" />
          <UiNumberInput v-model="foodForm.carbs" :label="i18n.t('nutrition.carbsPer100')" name="food-carbs" :min="0" :step="0.001" />
          <UiNumberInput v-if="!foodForm.beverage" v-model="foodForm.quality" :label="i18n.t('nutrition.foodQuality')" name="food-quality" :min="0" :max="100" :step="0.01" />
          <UiNumberInput v-if="foodForm.beverage" v-model="foodForm.hydration" :label="i18n.t('nutrition.hydrationRatio')" name="food-hydration" :min="0" :max="1" :step="0.0001" />
          <div class="button-row wide-field"><button type="submit" :disabled="isSaving">{{ i18n.t('nutrition.createFood') }}</button></div>
        </form>
        <UiSegmented v-model="foodState" :label="i18n.t('nutrition.foodState')" name="food-state" :options="stateOptions" @update:model-value="loadAll" />
        <ul class="management-list" :aria-label="i18n.t('nutrition.foodCatalogue')">
          <li v-for="food in foods" :key="food.id" class="management-row" :aria-label="foodLabel(food)">
            <template v-if="editingFoodId !== food.id">
              <div><strong>{{ foodLabel(food) }}</strong><p class="muted">{{ food.calories_per_100 }} kcal / 100 {{ food.basis_unit === 'gram' ? 'g' : 'ml' }} · P {{ food.protein_per_100 }} · F {{ food.fat_per_100 }} · C {{ food.carbs_per_100 }}</p></div>
              <div v-if="!food.is_public" class="management-actions"><button type="button" class="secondary" :aria-label="i18n.t('nutrition.editFoodNamed', { name: food.name })" @click="startFoodEdit(food)">{{ i18n.t('common.edit') }}</button><button type="button" class="secondary" :aria-label="i18n.t(food.is_archived ? 'nutrition.restoreNamed' : 'nutrition.archiveNamed', { name: food.name })" @click="toggleFood(food)">{{ i18n.t(food.is_archived ? 'workouts.restore' : 'workouts.archive') }}</button></div>
            </template>
            <form v-else class="form-grid wide-field" :aria-label="i18n.t('nutrition.editFoodNamed', { name: food.name })" @submit.prevent="saveFood(food)">
              <UiTextInput v-model="foodDraft.name" :label="i18n.t('nutrition.foodName')" :name="`edit-food-name-${food.id}`" required />
              <UiSelect v-model="foodDraft.basis" :label="i18n.t('nutrition.basis')" :name="`edit-food-basis-${food.id}`" :options="basisOptions" />
              <UiCheckbox v-model="foodDraft.beverage" :label="i18n.t('nutrition.beverage')" :name="`edit-food-beverage-${food.id}`" />
              <UiNumberInput v-model="foodDraft.calories" :label="i18n.t('nutrition.caloriesPer100')" :name="`edit-food-calories-${food.id}`" :min="0" :step="0.001" />
              <UiNumberInput v-model="foodDraft.protein" :label="i18n.t('nutrition.proteinPer100')" :name="`edit-food-protein-${food.id}`" :min="0" :step="0.001" />
              <UiNumberInput v-model="foodDraft.fat" :label="i18n.t('nutrition.fatPer100')" :name="`edit-food-fat-${food.id}`" :min="0" :step="0.001" />
              <UiNumberInput v-model="foodDraft.carbs" :label="i18n.t('nutrition.carbsPer100')" :name="`edit-food-carbs-${food.id}`" :min="0" :step="0.001" />
              <UiNumberInput v-if="!foodDraft.beverage" v-model="foodDraft.quality" :label="i18n.t('nutrition.foodQuality')" :name="`edit-food-quality-${food.id}`" :min="0" :max="100" :step="0.01" />
              <UiNumberInput v-if="foodDraft.beverage" v-model="foodDraft.hydration" :label="i18n.t('nutrition.hydrationRatio')" :name="`edit-food-hydration-${food.id}`" :min="0" :max="1" :step="0.0001" />
              <div class="button-row wide-field"><button type="submit">{{ i18n.t('nutrition.saveFood') }}</button><button type="button" class="secondary" @click="editingFoodId = null">{{ i18n.t('common.cancel') }}</button></div>
            </form>
          </li>
        </ul>
      </section>

      <section class="panel">
        <div class="section-heading"><h2>{{ i18n.t('nutrition.recipes') }}</h2></div>
        <form class="form-grid" :aria-label="i18n.t('nutrition.createRecipe')" @submit.prevent="createRecipe">
          <UiTextInput v-model="recipeForm.name" :label="i18n.t('nutrition.recipeName')" name="recipe-name" required />
          <UiTextInput v-model="recipeForm.description" :label="i18n.t('nutrition.description')" name="recipe-description" />
          <div v-for="(component, index) in recipeForm.components" :key="index" class="form-grid wide-field nutrition-entry-row">
            <UiSelect v-model="component.referenceId" :label="i18n.t('nutrition.componentNumber', { number: index + 1 })" :name="`recipe-component-${index}`" :options="solidOptions" />
            <UiNumberInput v-model="component.grams" :label="i18n.t('nutrition.componentGramsNumber', { number: index + 1 })" :name="`recipe-component-grams-${index}`" :min="0.001" :step="0.001" />
            <button type="button" class="secondary danger" :aria-label="i18n.t('nutrition.removeComponentNumber', { number: index + 1 })" @click="removeRecipeComponent(recipeForm, index)">{{ i18n.t('common.remove') }}</button>
          </div>
          <div class="button-row wide-field"><button type="button" class="secondary" @click="addRecipeComponent(recipeForm)">{{ i18n.t('nutrition.addComponent') }}</button><button type="submit">{{ i18n.t('nutrition.createRecipe') }}</button></div>
        </form>
        <UiSegmented v-model="recipeState" :label="i18n.t('nutrition.recipeState')" name="recipe-state" :options="recipeStateOptions" @update:model-value="loadAll" />
        <ul class="management-list" :aria-label="i18n.t('nutrition.recipes')">
          <li v-for="recipe in recipes" :key="recipe.id" class="management-row" :aria-label="recipe.name">
            <template v-if="editingRecipeId !== recipe.id"><div><strong>{{ recipe.name }}</strong><p class="muted">{{ recipe.nutrition_per_100.calories }} kcal / 100 g · {{ i18n.t('nutrition.quality') }} {{ recipe.nutrition_per_100.quality_score ?? '—' }}</p></div><div class="management-actions"><button type="button" class="secondary" :aria-label="i18n.t('nutrition.editRecipeNamed', { name: recipe.name })" @click="startRecipeEdit(recipe)">{{ i18n.t('common.edit') }}</button><button type="button" class="secondary" :aria-label="i18n.t(recipe.is_archived ? 'nutrition.restoreNamed' : 'nutrition.archiveNamed', { name: recipe.name })" @click="toggleRecipe(recipe)">{{ i18n.t(recipe.is_archived ? 'workouts.restore' : 'workouts.archive') }}</button></div></template>
            <form v-else class="form-grid wide-field" :aria-label="i18n.t('nutrition.editRecipeNamed', { name: recipe.name })" @submit.prevent="saveRecipe(recipe)">
              <UiTextInput v-model="recipeDraft.name" :label="i18n.t('nutrition.recipeName')" :name="`edit-recipe-name-${recipe.id}`" required />
              <UiTextInput v-model="recipeDraft.description" :label="i18n.t('nutrition.description')" :name="`edit-recipe-description-${recipe.id}`" />
              <div v-for="(component, index) in recipeDraft.components" :key="index" class="form-grid wide-field nutrition-entry-row">
                <UiSelect v-model="component.referenceId" :label="i18n.t('nutrition.componentNumber', { number: index + 1 })" :name="`edit-recipe-component-${recipe.id}-${index}`" :options="solidOptions" />
                <UiNumberInput v-model="component.grams" :label="i18n.t('nutrition.componentGramsNumber', { number: index + 1 })" :name="`edit-recipe-component-grams-${recipe.id}-${index}`" :min="0.001" :step="0.001" />
                <button type="button" class="secondary danger" :aria-label="i18n.t('nutrition.removeComponentNumber', { number: index + 1 })" @click="removeRecipeComponent(recipeDraft, index)">{{ i18n.t('common.remove') }}</button>
              </div>
              <div class="button-row wide-field"><button type="button" class="secondary" @click="addRecipeComponent(recipeDraft)">{{ i18n.t('nutrition.addComponent') }}</button><button type="submit">{{ i18n.t('nutrition.saveRecipe') }}</button><button type="button" class="secondary" @click="editingRecipeId = null">{{ i18n.t('common.cancel') }}</button></div>
            </form>
          </li>
        </ul>
      </section>

      <section class="panel">
        <div class="section-heading"><h2>{{ i18n.t('nutrition.meals') }}</h2></div>
        <form class="form-grid" :aria-label="i18n.t('nutrition.logMeal')" @submit.prevent="createMeal">
          <UiDatePicker :model-value="selectedDate" :label="i18n.t('nutrition.consumedDate')" name="meal-date" :locale="locale" :today="localToday()" :max="localToday()" @update:model-value="selectDate" />
          <UiTextInput v-model="mealForm.name" :label="i18n.t('nutrition.mealName')" name="meal-name" required />
          <UiSelect v-model="mealForm.category" :label="i18n.t('nutrition.category')" name="meal-category" :options="categoryOptions" nullable />
          <UiTimeField v-model="mealForm.time" :label="i18n.t('nutrition.localTime')" name="meal-time" />
          <UiTextInput v-model="mealForm.note" :label="i18n.t('nutrition.note')" name="meal-note" />
          <div v-for="(entry, index) in mealForm.entries" :key="index" class="form-grid wide-field nutrition-entry-row"><UiSelect v-model="entry.reference" :label="i18n.t('nutrition.entryNumber', { number: index + 1 })" :name="`meal-entry-${index}`" :options="referenceOptions" /><UiNumberInput v-model="entry.quantity" :label="i18n.t('nutrition.entryQuantityNumber', { number: index + 1 })" :name="`meal-entry-quantity-${index}`" :min="0.001" :step="0.001" /><button type="button" class="secondary danger" :aria-label="i18n.t('nutrition.removeEntryNumber', { number: index + 1 })" @click="removeMealEntry(mealForm, index)">{{ i18n.t('common.remove') }}</button></div>
          <div class="button-row wide-field"><button type="button" class="secondary" @click="addMealEntry(mealForm)">{{ i18n.t('nutrition.addEntry') }}</button><button type="submit">{{ i18n.t('nutrition.logMeal') }}</button></div>
        </form>
        <ul class="management-list" :aria-label="i18n.t('nutrition.meals')">
          <li v-for="meal in day.meals" :key="meal.id" class="management-row" :aria-label="meal.name">
            <template v-if="editingMealId !== meal.id"><div><strong>{{ meal.name }}</strong><p class="muted">{{ meal.consumed_at_local ?? i18n.t('nutrition.anyTime') }} · {{ meal.entries.reduce((sum, entry) => sum + Number(entry.calories), 0).toFixed(0) }} kcal</p></div><div class="management-actions"><button type="button" class="secondary" :aria-label="i18n.t('nutrition.editMealNamed', { name: meal.name })" @click="startMealEdit(meal)">{{ i18n.t('common.edit') }}</button><button type="button" class="danger" :aria-label="i18n.t('nutrition.deleteMealNamed', { name: meal.name })" @click="removeMeal(meal)">{{ i18n.t('common.delete') }}</button></div></template>
            <form v-else class="form-grid wide-field" :aria-label="i18n.t('nutrition.editMealNamed', { name: meal.name })" @submit.prevent="saveMeal(meal)">
              <UiDatePicker :model-value="mealDraft.consumedOn" :label="i18n.t('nutrition.consumedDate')" :name="`edit-meal-date-${meal.id}`" :locale="locale" :today="localToday()" :max="localToday()" @update:model-value="(value) => { if (value) mealDraft.consumedOn = value }" />
              <UiTextInput v-model="mealDraft.name" :label="i18n.t('nutrition.mealName')" :name="`edit-meal-name-${meal.id}`" required />
              <UiSelect v-model="mealDraft.category" :label="i18n.t('nutrition.category')" :name="`edit-meal-category-${meal.id}`" :options="categoryOptions" nullable />
              <UiTimeField v-model="mealDraft.time" :label="i18n.t('nutrition.localTime')" :name="`edit-meal-time-${meal.id}`" />
              <UiTextInput v-model="mealDraft.note" :label="i18n.t('nutrition.note')" :name="`edit-meal-note-${meal.id}`" />
              <div v-for="(entry, index) in mealDraft.entries" :key="index" class="form-grid wide-field nutrition-entry-row">
                <UiSelect v-model="entry.reference" :label="i18n.t('nutrition.entryNumber', { number: index + 1 })" :name="`edit-meal-entry-${meal.id}-${index}`" :options="referenceOptions" />
                <UiNumberInput v-model="entry.quantity" :label="i18n.t('nutrition.entryQuantityNumber', { number: index + 1 })" :name="`edit-meal-quantity-${meal.id}-${index}`" :min="0.001" :step="0.001" />
                <button type="button" class="secondary danger" :aria-label="i18n.t('nutrition.removeEntryNumber', { number: index + 1 })" @click="removeMealEntry(mealDraft, index)">{{ i18n.t('common.remove') }}</button>
              </div>
              <div class="button-row wide-field"><button type="button" class="secondary" @click="addMealEntry(mealDraft)">{{ i18n.t('nutrition.addEntry') }}</button><button type="submit">{{ i18n.t('nutrition.saveMeal') }}</button><button type="button" class="secondary" @click="editingMealId = null">{{ i18n.t('common.cancel') }}</button></div>
            </form>
          </li>
        </ul>
      </section>

      <section class="panel"><div class="section-heading"><h2>{{ i18n.t('nutrition.targetSettings') }}</h2></div><form class="form-grid" :aria-label="i18n.t('nutrition.targetSettings')" @submit.prevent="saveSettings"><UiSelect v-model="settingsForm.bodyGoalId" :label="i18n.t('nutrition.bodyGoal')" name="nutrition-body-goal" :options="goalOptions" nullable /><UiNumberInput v-model="settingsForm.protein" :label="i18n.t('nutrition.proteinPercent')" name="nutrition-protein-percent" :min="10" :max="35" /><UiNumberInput v-model="settingsForm.fat" :label="i18n.t('nutrition.fatPercent')" name="nutrition-fat-percent" :min="20" :max="35" /><UiNumberInput v-model="settingsForm.carbs" :label="i18n.t('nutrition.carbsPercent')" name="nutrition-carbs-percent" :min="45" :max="65" /><UiNumberInput v-model="settingsForm.water" :label="i18n.t('nutrition.waterOverride')" name="nutrition-water-override" :min="1000" :max="6000" /><div class="button-row wide-field"><button type="submit">{{ i18n.t('nutrition.saveSettings') }}</button></div></form></section>

      <section class="panel"><div class="section-heading"><h2>{{ i18n.t('nutrition.recentHistory') }}</h2></div><ul class="nutrition-history"><li v-for="summary in history" :key="summary.date"><time :datetime="summary.date">{{ summary.date }}</time><strong>{{ format(summary.calories, 'kcal') }}</strong><span>{{ format(summary.hydration_ml, 'ml') }}</span></li></ul></section>
    </template>
  </div>
</template>
