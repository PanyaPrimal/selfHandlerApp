<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from '../i18n'
import { chatGptStatus, chatGptLogin, chatGptLogout, chatGptModels, type ChatGptStatus, type ChatGptLogin, type ChatGptModel } from '../mentor/chatgpt'
const model = defineModel<string | null>({ required: true })
const emit = defineEmits<{ disconnected: [] }>()
const { t } = useI18n()
const status = ref<ChatGptStatus | null>(null), login = ref<ChatGptLogin | null>(null)
const models = ref<ChatGptModel[]>([]), busy = ref(false), error = ref('')
let timer: ReturnType<typeof setTimeout> | undefined, alive = true
async function refresh() {
  try {
    const result = await chatGptStatus()
    if (!alive) return
    status.value = result; login.value = result.login ?? null
    if (result.connected) {
      const choices = await chatGptModels()
      if (!alive) return
      models.value = choices
      if (!choices.some(choice => choice.id === model.value)) model.value = choices.find(choice => choice.default)?.id ?? choices[0]?.id ?? null
      login.value = null; error.value = ''
    } else if (login.value && login.value.expires_at > Date.now()) schedule()
  } catch { if (alive) error.value = t('chatgpt.failed') }
}
function schedule() { clearTimeout(timer); timer = setTimeout(() => { if (alive) void refresh() }, 5000) }
async function connect() {
  busy.value = true; error.value = ''
  try { const result = await chatGptLogin(); if (alive) { login.value = result; schedule() } }
  catch { if (alive) error.value = t('chatgpt.failed') }
  finally { if (alive) busy.value = false }
}
async function disconnect() {
  busy.value = true; error.value = ''
  try {
    await chatGptLogout()
    if (alive) { clearTimeout(timer); login.value = null; status.value = { available: true, connected: false }; models.value = []; emit('disconnected') }
  } catch { if (alive) error.value = t('chatgpt.failed') }
  finally { if (alive) busy.value = false }
}
onMounted(refresh)
onBeforeUnmount(() => { alive = false; clearTimeout(timer) })
</script>
<template>
  <div class="chatgpt-connection">
    <p>{{ t('chatgpt.help') }}</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-if="status && !status.available">{{ t('chatgpt.unavailable') }}</p>
    <template v-else-if="status?.connected">
      <p role="status">{{ t('chatgpt.connected', { email: status.email ?? '', plan: status.plan ?? '' }) }}</p>
      <label for="chatgpt-model">{{ t('chatgpt.model') }}</label>
      <select id="chatgpt-model" v-model="model"><option v-for="choice in models" :key="choice.id" :value="choice.id">{{ choice.name }}</option></select>
      <p v-if="status.limits?.primary">{{ t('chatgpt.remaining', { percent: Math.max(0, 100 - status.limits.primary.usedPercent) }) }}</p>
      <button type="button" class="secondary" :disabled="busy" @click="disconnect">{{ t('chatgpt.disconnect') }}</button>
    </template>
    <template v-else>
      <button type="button" :disabled="busy || status?.available === false" @click="connect">{{ t('chatgpt.connect') }}</button>
      <div v-if="login" class="chatgpt-login" role="status">
        <p>{{ t('chatgpt.loginHelp') }}</p><strong class="chatgpt-code">{{ login.user_code }}</strong>
        <a class="button" href="https://auth.openai.com/codex/device" target="_blank" rel="noopener noreferrer">{{ t('chatgpt.openLogin') }}</a>
        <button class="secondary" type="button" :disabled="busy" @click="refresh">{{ t('chatgpt.check') }}</button>
      </div>
    </template>
  </div>
</template>
<style scoped>
.chatgpt-connection,.chatgpt-login { display:grid; gap:.75rem; min-width:0; }
.chatgpt-connection p { overflow-wrap:anywhere; }
.chatgpt-code { font-size:1.5rem; letter-spacing:.1em; }
select { width:100%; min-width:0; min-height:44px; }
</style>
