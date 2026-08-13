<script setup lang="ts">
import { computed, reactive } from 'vue'
import type { FinanceCategory, FinanceCategoryInput } from '../../api/types'
import { useI18n } from '../../i18n'

const props = defineProps<{ categories: FinanceCategory[], busy?: boolean }>()
const emit = defineEmits<{ create: [FinanceCategoryInput], lifecycle: [FinanceCategory, boolean] }>()
const i18n = useI18n()
const draft = reactive<FinanceCategoryInput>({ direction: 'expense', parent_id: null, name: '' })
const roots = computed(() => props.categories.filter((item) => item.parent_id === null && item.direction === draft.direction && !item.archived))
</script>

<template>
  <section class="finance-section" aria-labelledby="finance-categories-heading">
    <div class="section-heading"><div><h2 id="finance-categories-heading">{{ i18n.t('finance.categories') }}</h2><p class="muted">{{ i18n.t('finance.categoriesHelp') }}</p></div></div>
    <form class="finance-form" :aria-label="i18n.t('finance.categoryEditor')" @submit.prevent="emit('create', { ...draft })">
      <label><span>{{ i18n.t('finance.direction') }}</span><select v-model="draft.direction"><option value="expense">{{ i18n.t('finance.expense') }}</option><option value="income">{{ i18n.t('finance.income') }}</option></select></label>
      <label><span>{{ i18n.t('finance.parentCategory') }}</span><select v-model="draft.parent_id"><option :value="null">{{ i18n.t('finance.rootCategory') }}</option><option v-for="root in roots" :key="root.id" :value="root.id">{{ root.label }}</option></select></label>
      <label><span>{{ i18n.t('finance.name') }}</span><input v-model="draft.name" required maxlength="120"></label>
      <button type="submit" :disabled="busy">{{ i18n.t('finance.addCategory') }}</button>
    </form>
    <div class="finance-category-columns">
      <section v-for="direction in ['expense', 'income'] as const" :key="direction">
        <h3>{{ i18n.t(`finance.${direction}` as never) }}</h3>
        <article v-for="root in categories.filter((item) => item.direction === direction && item.parent_id === null)" :key="root.id" class="finance-category-group" :class="{ 'is-muted': root.archived }">
          <div><strong>{{ root.label }}</strong><button type="button" class="text-button" :disabled="busy" @click="emit('lifecycle', root, !root.archived)">{{ i18n.t(root.archived ? 'finance.restore' : 'finance.archive') }}</button></div>
          <ul><li v-for="child in categories.filter((item) => item.parent_id === root.id)" :key="child.id" :class="{ 'is-muted': child.archived }"><span>{{ child.label }}</span><small v-if="child.used">{{ i18n.t('finance.used') }}</small><button type="button" class="text-button" :disabled="busy" @click="emit('lifecycle', child, !child.archived)">{{ i18n.t(child.archived ? 'finance.restore' : 'finance.archive') }}</button></li></ul>
        </article>
      </section>
    </div>
  </section>
</template>
