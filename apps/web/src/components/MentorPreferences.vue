<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from '../i18n'
import { mentorSettings, saveMentorSettings, type MentorSettings } from '../mentor/api'
import ChatGptConnection from './ChatGptConnection.vue'
const { t } = useI18n()
const emit = defineEmits<{ mode: [mode: 'api' | 'chatgpt'] }>()
const settings = ref<MentorSettings | null>(null)
watch(() => settings.value?.auth_mode, mode => emit('mode', mode ?? 'api'))
const busy = ref(false)
const message = ref('')
onMounted(async () => { try { settings.value = await mentorSettings() } catch (e) { message.value = e instanceof Error ? e.message : t('mentor.failed') } })
async function save() {
  if (!settings.value) return
  busy.value = true; message.value = ''
  try { settings.value = await saveMentorSettings({ enabled: settings.value.enabled, memory: settings.value.memory, monthly_token_limit: settings.value.monthly_token_limit, auth_mode: settings.value.auth_mode, chatgpt_model: settings.value.chatgpt_model }); message.value = t('mentor.saved') }
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
      <label for="mentor-auth-mode">{{ t('chatgpt.connection') }}</label>
      <select id="mentor-auth-mode" v-model="settings.auth_mode"><option value="chatgpt">{{ t('chatgpt.subscription') }}</option><option value="api">{{ t('chatgpt.api') }}</option></select>
      <ChatGptConnection v-if="settings.auth_mode === 'chatgpt'" v-model="settings.chatgpt_model" @disconnected="settings.enabled = false" />
      <label class="mentor-enable"><input v-model="settings.enabled" type="checkbox"> <span>{{ t('mentor.enable') }}</span></label>
      <label for="mentor-memory">{{ t('mentor.memory') }}</label>
      <textarea id="mentor-memory" v-model="settings.memory" maxlength="3000" rows="4" />
      <details :open="settings.auth_mode !== 'chatgpt'" class="mentor-usage">
      <summary>{{ t('chatgpt.usage') }}</summary>
      <label for="mentor-budget">{{ t('mentor.budget') }}</label>
      <input id="mentor-budget" v-model.number="settings.monthly_token_limit" type="number" min="250000" max="10000000" step="10000" required>
      <p class="muted">{{ t('mentor.budgetHelp') }}</p>
      <p>{{ t('mentor.monthUsage', { month: settings.budget_month, tokens: settings.usage.tokens, reserved: settings.usage.reserved, cost: settings.usage.estimated_usd }) }}</p>
      <p v-if="Number(settings.usage.unpriced_requests)" class="muted">{{ t('mentor.partialCost') }}</p>
      </details>
      <div class="button-row"><button :disabled="busy">{{ t('mentor.saveSettings') }}</button><RouterLink class="button secondary" to="/mentor">{{ t('mentor.open') }}</RouterLink></div>
    </form>
  </section>
</template>
<style scoped>
.mentor-preferences form { display:grid; gap: .8rem; }
.mentor-preferences textarea { width:100%; resize:vertical; }
.mentor-preferences select { width:100%; min-width:0; min-height:44px; }
.mentor-enable { display:flex; align-items:flex-start; gap:.65rem; min-height:44px; }
.mentor-enable input { width:1.2rem; height:1.2rem; min-height:0; flex:none; margin-top:.15rem; }
.mentor-usage summary { cursor:pointer; min-height:44px; padding-block:.65rem; }
</style>
