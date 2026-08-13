<script setup lang="ts" generic="V extends string | number | boolean | null">
import { computed, nextTick, ref, watch } from 'vue'
import UiField from './UiField.vue'
import UiPopoverSurface from './UiPopoverSurface.vue'
import { useAnchoredSurface } from './useAnchoredSurface'
import { useFieldIds } from './useFieldIds'
import type { UiOption } from './types'
import { useI18n } from '../../i18n'

const props = withDefaults(
  defineProps<{
    label: string
    name: string
    modelValue: V | null
    options: readonly UiOption<V>[]
    helper?: string
    error?: string
    disabled?: boolean
    required?: boolean
    placeholder?: string
    /** Adds an explicit "not set" entry that emits `null`. */
    nullable?: boolean
    nullableLabel?: string
    wide?: boolean
  }>(),
  {
    helper: undefined,
    error: undefined,
    disabled: false,
    required: false,
    placeholder: '',
    nullable: false,
    nullableLabel: '',
    wide: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [V | null] }>()
const { t } = useI18n()

const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const listId = `${ids.controlId}-listbox`
const trigger = ref<HTMLElement | null>(null)
const activeIndex = ref(-1)
const typeahead = ref('')
let typeaheadTimer: ReturnType<typeof setTimeout> | undefined

const invalid = computed(() => Boolean(props.error))

const entries = computed<UiOption<V | null>[]>(() => {
  const list: UiOption<V | null>[] = props.options.map((option) => ({ ...option }))

  if (props.nullable) {
    list.unshift({ value: null, label: props.nullableLabel || t('common.notSet') })
  }

  return list
})

const selectedIndex = computed(() =>
  entries.value.findIndex((option) => option.value === props.modelValue),
)

const selectedLabel = computed(() => entries.value[selectedIndex.value]?.label ?? '')

const surface = useAnchoredSurface({
  matchWidth: true,
  focusTarget: () => trigger.value,
})

function optionId(index: number): string {
  return `${listId}-option-${index}`
}

const activeOptionId = computed(() =>
  surface.isOpen.value && activeIndex.value >= 0 ? optionId(activeIndex.value) : undefined,
)

function scrollActiveIntoView(): void {
  surface.whenPositioned(() => {
    void nextTick(() => {
      if (activeIndex.value < 0) {
        return
      }

      const option = document.getElementById(optionId(activeIndex.value))
      const container = surface.surfaceRef.value

      if (!option || !container) {
        return
      }

      const optionTop = option.offsetTop
      const optionBottom = optionTop + option.offsetHeight

      if (optionTop < container.scrollTop) {
        container.scrollTop = optionTop
      } else if (optionBottom > container.scrollTop + container.clientHeight) {
        container.scrollTop = optionBottom - container.clientHeight
      }
    })
  })
}

function firstEnabled(from: number, direction: 1 | -1): number {
  const list = entries.value

  for (let step = 0; step < list.length; step += 1) {
    const index = from + step * direction

    if (index < 0 || index >= list.length) {
      break
    }

    if (!list[index].disabled) {
      return index
    }
  }

  return -1
}

function openList(): void {
  if (props.disabled) {
    return
  }

  const start = selectedIndex.value >= 0 ? selectedIndex.value : firstEnabled(0, 1)
  activeIndex.value = start
  surface.open()
  scrollActiveIntoView()
}

function commit(index: number): void {
  const option = entries.value[index]

  if (!option || option.disabled) {
    return
  }

  emit('update:modelValue', option.value)
  surface.close()
}

function moveActive(delta: number): void {
  const list = entries.value

  if (list.length === 0) {
    return
  }

  const from = activeIndex.value < 0 ? (delta > 0 ? -1 : list.length) : activeIndex.value
  const next = firstEnabled(
    Math.min(list.length - 1, Math.max(0, from + delta)),
    delta > 0 ? 1 : -1,
  )

  if (next >= 0) {
    activeIndex.value = next
    scrollActiveIntoView()
  }
}

function jumpTo(edge: 'first' | 'last'): void {
  const next = edge === 'first' ? firstEnabled(0, 1) : firstEnabled(entries.value.length - 1, -1)

  if (next >= 0) {
    activeIndex.value = next
    scrollActiveIntoView()
  }
}

function applyTypeahead(character: string): void {
  typeahead.value += character.toLowerCase()
  clearTimeout(typeaheadTimer)
  typeaheadTimer = setTimeout(() => {
    typeahead.value = ''
  }, 600)

  const match = entries.value.findIndex(
    (option) => !option.disabled && option.label.toLowerCase().startsWith(typeahead.value),
  )

  if (match >= 0) {
    activeIndex.value = match
    scrollActiveIntoView()
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (props.disabled) {
    return
  }

  if (!surface.isOpen.value) {
    if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) {
      event.preventDefault()
      openList()
    }

    return
  }

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      moveActive(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      moveActive(-1)
      break
    case 'Home':
      event.preventDefault()
      jumpTo('first')
      break
    case 'End':
      event.preventDefault()
      jumpTo('last')
      break
    case 'Enter':
    case ' ':
      event.preventDefault()
      commit(activeIndex.value)
      break
    case 'Tab':
      surface.close({ restoreFocus: false })
      break
    default:
      if (event.key.length === 1 && !event.metaKey && !event.ctrlKey && !event.altKey) {
        event.preventDefault()
        applyTypeahead(event.key)
      }
  }
}

function onTriggerClick(): void {
  if (props.disabled) {
    return
  }

  if (surface.isOpen.value) {
    surface.close()
  } else {
    openList()
  }
}

watch(surface.isOpen, (open) => {
  if (!open) {
    typeahead.value = ''
    clearTimeout(typeaheadTimer)
  }
})

defineExpose({ focus: () => trigger.value?.focus() })
</script>

<template>
  <UiField
    v-slot="{ labelId }"
    :label="label"
    :control-id="ids.controlId"
    :helper-id="ids.helperId"
    :error-id="ids.errorId"
    :helper="helper"
    :error="error"
    :wide="wide"
    labelledby
  >
    <div :ref="(element) => { surface.anchorRef.value = element as HTMLElement | null }" class="ui-anchor">
      <div
        :id="ids.controlId"
        ref="trigger"
        class="ui-control ui-control--trigger"
        :class="{ 'ui-control--invalid': invalid, 'is-open': surface.isOpen.value, 'is-disabled': disabled }"
        role="combobox"
        :tabindex="disabled ? -1 : 0"
        :data-field="name"
        :data-ui-select="name"
        aria-haspopup="listbox"
        :aria-expanded="surface.isOpen.value"
        :aria-controls="listId"
        :aria-labelledby="labelId"
        :aria-activedescendant="activeOptionId"
        :aria-disabled="disabled || undefined"
        :aria-required="required || undefined"
        :aria-invalid="invalid || undefined"
        :aria-describedby="ids.describedBy.value"
        @click="onTriggerClick"
        @keydown="onKeydown"
      >
        <span :class="['ui-control__value', { 'is-placeholder': selectedIndex < 0 }]">
          {{ selectedIndex >= 0 ? selectedLabel : (placeholder || t('common.select')) }}
        </span>
        <span class="ui-control__chevron" aria-hidden="true"></span>
      </div>

      <UiPopoverSurface
        :id="listId"
        :open="surface.isOpen.value"
        :surface-style="surface.surfaceStyle.value"
        :bind-ref="(element) => { surface.surfaceRef.value = element }"
        role="listbox"
        :aria-labelledby="labelId"
        class="ui-listbox"
      >
        <div
          v-for="(option, index) in entries"
          :id="optionId(index)"
          :key="String(option.value)"
          class="ui-option"
          :class="{ 'is-active': index === activeIndex, 'is-selected': index === selectedIndex, 'is-disabled': option.disabled }"
          role="option"
          :aria-selected="index === selectedIndex"
          :aria-disabled="option.disabled || undefined"
          @click="commit(index)"
          @pointermove="activeIndex = option.disabled ? activeIndex : index"
        >
          {{ option.label }}
        </div>
      </UiPopoverSurface>
    </div>
  </UiField>
</template>
