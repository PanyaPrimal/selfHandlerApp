<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { registerPlugin } from '@capacitor/core'
import { isAndroidNative, nativePlugin } from '../mobile/platform'
import { activeLocaleValue, useI18n } from '../i18n'
defineProps<{ disabled?: boolean }>()
const emit = defineEmits<{ transcript: [text: string] }>()
const { t } = useI18n()
const busy = ref(false), error = ref('')
const native = registerPlugin<{ recognize(input: { language: string }): Promise<{ text: string }> }>('Dictation')
type Recognition = { lang: string; continuous: boolean; interimResults: boolean; onresult: ((event: any) => void) | null; onerror: (() => void) | null; onend: (() => void) | null; start(): void; abort(): void }
let recognition: Recognition | null = null, alive = true
async function dictate() {
  if (busy.value) return
  busy.value = true; error.value = ''
  const locale = activeLocaleValue()
  const language = locale.startsWith('ru') ? 'ru-RU' : locale.startsWith('uk') ? 'uk-UA' : 'en-GB'
  try {
    if (isAndroidNative()) {
      const result = await nativePlugin('Dictation', native).recognize({ language })
      if (alive && result.text) emit('transcript', result.text)
      if (alive) busy.value = false
      return
    }
    const browser = window as unknown as { SpeechRecognition?: new () => Recognition; webkitSpeechRecognition?: new () => Recognition }
    const Constructor = browser.SpeechRecognition ?? browser.webkitSpeechRecognition
    if (!Constructor) throw new Error('unavailable')
    recognition = new Constructor(); recognition.lang = language; recognition.continuous = false; recognition.interimResults = false
    recognition.onresult = event => { if (alive) emit('transcript', String(event.results[0][0].transcript).slice(0, 4000)) }
    recognition.onerror = () => { if (alive) error.value = t('chatgpt.dictationFailed') }
    recognition.onend = () => { if (alive) busy.value = false; recognition = null }
    recognition.start()
  } catch { if (alive) { busy.value = false; error.value = t('chatgpt.dictationFailed') } }
}
onBeforeUnmount(() => { alive = false; recognition?.abort() })
</script>
<template>
  <div><button type="button" class="secondary" :disabled="disabled || busy" @click="dictate">{{ t(busy ? 'mentor.working' : 'chatgpt.dictate') }}</button><p v-if="error" role="alert">{{ error }}</p></div>
</template>
