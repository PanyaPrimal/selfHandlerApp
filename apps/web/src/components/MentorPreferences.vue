<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from '../i18n'
import { mentorSettings, saveMentorSettings, type MentorSettings } from '../mentor/api'
const { t } = useI18n()
const settings = ref<MentorSettings | null>(null)
const busy = ref(false)
const message = ref('')
onMounted(async () => { try { settings.value = await mentorSettings() } catch (e) { message.value = e instanceof Error ? e.message : t('mentor.failed') } })
async function save() {
  if (!settings.value) return
  busy.value = true; message.value = ''
  try { settings.value = await saveMentorSettings({ enabled: settings.value.enabled, memory: settings.value.memory, monthly_token_limit: settings.value.monthly_token_limit }); message.value = t('mentor.saved') }
  catch (e) { message.value = e instanceof Error ? e.message : t('mentor.failed') }
  finally { busy.value = false }
}
</script>
<template>
  <section class="panel mentor-preferences">
    <h2>{{ t('mentor.title') }}</h2>
    <p>{{ t('mentor.disclosure') }}</p>
    <p v-if="message" role="status">{{ message }}</p>
    <form v-if="settings" @submit.prevent="save">
      <label><input v-model="settings.enabled" type="checkbox"> {{ t('mentor.enable') }}</label>
      <label for="mentor-memory">{{ t('mentor.memory') }}</label>
      <textarea id="mentor-memory" v-model="settings.memory" maxlength="3000" rows="4" />
      <label for="mentor-budget">{{ t('mentor.budget') }}</label>
      <input id="mentor-budget" v-model.number="settings.monthly_token_limit" type="number" min="250000" max="10000000" step="10000" required>
      <p class="muted">{{ t('mentor.budgetHelp') }}</p>
      <p>{{ t('mentor.monthUsage', { month: settings.budget_month, tokens: settings.usage.tokens, reserved: settings.usage.reserved, cost: settings.usage.estimated_usd }) }}</p>
      <p v-if="Number(settings.usage.unpriced_requests)" class="muted">{{ t('mentor.partialCost') }}</p>
      <div class="button-row"><button :disabled="busy">{{ t('mentor.saveSettings') }}</button><RouterLink class="button secondary" to="/mentor">{{ t('mentor.open') }}</RouterLink></div>
    </form>
  </section>
</template>
<style scoped>
.mentor-preferences form { display:grid; gap: .8rem; }
.mentor-preferences textarea { width:100%; resize:vertical; }
</style>
