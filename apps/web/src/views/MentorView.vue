<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthSession } from '../auth/session'
import { useI18n } from '../i18n'
import { askMentor, confirmMentorAction, mentorHistory, mentorSettings, transcribeVoice, type MentorSettings, type MentorTurn } from '../mentor/api'
import { localRead, localWrite } from '../offline/database'

interface Draft { text: string; operation: string | null; audio: Blob | null; voiceOperation: string }
const { t } = useI18n()
const session = useAuthSession()
const owner = session.user!.id
const key = `account:${owner}:mentor-draft`
const historyKey = `account:${owner}:mentor-history`
const settings = ref<MentorSettings | null>(null)
const turns = ref<MentorTurn[]>([])
const draft = ref<Draft>({ text: '', operation: null, audio: null, voiceOperation: crypto.randomUUID() })
const busy = ref(false)
const recording = ref(false)
const seconds = ref(0)
const error = ref('')
const notice = ref('')
const ready = ref(false)
const online = ref(navigator.onLine)
const audioUrl = computed(() => draft.value.audio ? URL.createObjectURL(draft.value.audio) : '')
let previousAudioUrl = ''
let recorder: MediaRecorder | null = null
let stream: MediaStream | null = null
let timer: ReturnType<typeof setInterval> | null = null
let poller: ReturnType<typeof setTimeout> | null = null
let writes = Promise.resolve()
let disposed = false
let conversationVersion = 0
function currentOwner() { return !disposed && session.user?.id === owner }
function applyHistory(history: MentorTurn[], version: number) {
  // A history read started before Send/Confirm must not erase its newer local result.
  const merged = version === conversationVersion ? history : [...new Map([...history, ...turns.value].map(turn => [turn.id, turn])).values()]
  turns.value = merged.sort((a, b) => a.id - b.id)
}
function fail(e: unknown) { if (currentOwner()) error.value = e instanceof Error ? e.message : t('mentor.failed') }
function persist(): Promise<void> {
  const snapshot = { ...draft.value }
  writes = writes.catch(() => undefined).then(() => localWrite(key, snapshot))
  return writes
}
async function saveText() { if (!ready.value) return; draft.value.operation = null; try { await persist(); notice.value = t('mentor.draftSaved') } catch (e) { fail(e) } }
async function load() {
  try {
    const saved = await localRead<Draft>(key)
    const history = await localRead<MentorTurn[]>(historyKey)
    if (!currentOwner()) return
    if (saved) draft.value = saved
    if (history) turns.value = history
    ready.value = true
  } catch (e) { fail(e); return }
  try {
    const version = conversationVersion
    const [preferences, history] = await Promise.all([mentorSettings(), mentorHistory()])
    if (!currentOwner()) return
    settings.value = preferences; applyHistory(history, version)
    await localWrite(historyKey, turns.value)
    schedulePoll()
  } catch (e) { fail(e) }
}
function schedulePoll() {
  if (poller) clearTimeout(poller)
  if (!currentOwner() || !turns.value.some(turn => ['pending', 'processing'].includes(turn.status))) return
  poller = setTimeout(async () => {
    try {
      const version = conversationVersion
      const history = await mentorHistory()
      if (!currentOwner()) return
      applyHistory(history, version); await localWrite(historyKey, turns.value)
      const completed = history.find(turn => turn.operation_id === draft.value.operation && turn.status === 'completed')
      if (completed) { draft.value.text = ''; draft.value.operation = null; await persist() }
    } catch (e) { fail(e) }
    schedulePoll()
  }, 4000)
}
async function send() {
  if (!draft.value.text.trim() || busy.value || !ready.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    draft.value.operation ??= crypto.randomUUID()
    await persist()
    if (!online.value) { notice.value = t('mentor.offlineDraft'); return }
    const result = await askMentor(draft.value.text.trim(), draft.value.operation)
    if (!currentOwner()) return
    conversationVersion++
    turns.value = [...turns.value.filter(turn => turn.id !== result.id), result]
    await localWrite(historyKey, turns.value)
    if (result.status === 'completed') { draft.value.text = ''; draft.value.operation = null; await persist() }
    else { notice.value = t(result.status !== 'failed' ? 'mentor.pending' : 'mentor.requestFailed'); schedulePoll() }
  } catch (e) { fail(e) } finally { if (currentOwner()) busy.value = false }
}
async function confirm(turn: MentorTurn, index: number) {
  busy.value = true; error.value = ''
  try {
    const updated = await confirmMentorAction(turn.id, index)
    if (!currentOwner()) return
    conversationVersion++
    turns.value = turns.value.map(value => value.id === updated.id ? updated : value)
    await localWrite(historyKey, turns.value)
  } catch (e) { fail(e) } finally { if (currentOwner()) busy.value = false }
}
function releaseMicrophone() {
  if (timer) clearInterval(timer)
  timer = null
  stream?.getTracks().forEach(track => track.stop())
  stream = null; recording.value = false
}
function stopRecording() { if (recorder?.state === 'recording') recorder.stop(); releaseMicrophone() }
async function startRecording() {
  if (!ready.value || draft.value.audio || recording.value || busy.value) return
  error.value = ''; notice.value = ''
  try {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') throw new Error(t('mentor.microphoneUnavailable'))
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    if (!currentOwner()) { releaseMicrophone(); return }
    const mimeType = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus'].find(type => MediaRecorder.isTypeSupported(type))
    recorder = new MediaRecorder(stream, mimeType ? { mimeType, audioBitsPerSecond: 32000 } : undefined)
    const chunks: Blob[] = []
    draft.value.voiceOperation = crypto.randomUUID()
    recorder.ondataavailable = event => {
      if (!event.data.size) return
      chunks.push(event.data)
      const blob = new Blob(chunks, { type: recorder?.mimeType || event.data.type })
      if (blob.size > 2 * 1024 * 1024) { stopRecording(); fail(new Error(t('mentor.audioTooLarge'))); return }
      draft.value.audio = blob
      if (previousAudioUrl) URL.revokeObjectURL(previousAudioUrl)
      previousAudioUrl = audioUrl.value
      void persist().catch(e => { stopRecording(); fail(e) })
    }
    recorder.onerror = () => { stopRecording(); fail(new Error(t('mentor.microphoneUnavailable'))) }
    recorder.onstop = () => { releaseMicrophone(); if (currentOwner()) notice.value = t('mentor.audioSaved') }
    recorder.start(1000); recording.value = true; seconds.value = 0
    timer = setInterval(() => { seconds.value++; if (seconds.value >= 60) stopRecording() }, 1000)
  } catch (e) { releaseMicrophone(); fail(e) }
}
async function transcribe() {
  if (!draft.value.audio || busy.value || recording.value) return
  busy.value = true; error.value = ''
  try {
    await writes
    const text = await transcribeVoice(draft.value.audio, draft.value.voiceOperation)
    if (!currentOwner()) return
    draft.value.text = [draft.value.text, text].filter(Boolean).join('\n').slice(0, 4000)
    draft.value.operation = null
    // Keep audio until the editable transcript has been saved successfully.
    await persist()
    draft.value.audio = null
    await persist(); notice.value = t('mentor.reviewTranscript')
  } catch (e) { fail(e) } finally { if (currentOwner()) busy.value = false }
}
async function discardAudio() {
  draft.value.audio = null; draft.value.voiceOperation = crypto.randomUUID()
  try { await persist() } catch (e) { fail(e) }
}
function connectionChanged() { online.value = navigator.onLine }
function visibilityChanged() { if (document.hidden && recording.value) stopRecording() }
onMounted(() => { void load(); window.addEventListener('online', connectionChanged); window.addEventListener('offline', connectionChanged); document.addEventListener('visibilitychange', visibilityChanged) })
onBeforeUnmount(() => {
  stopRecording(); disposed = true
  if (poller) clearTimeout(poller)
  window.removeEventListener('online', connectionChanged); window.removeEventListener('offline', connectionChanged)
  document.removeEventListener('visibilitychange', visibilityChanged)
  if (previousAudioUrl) URL.revokeObjectURL(previousAudioUrl)
})
</script>

<template>
  <section class="mentor-page">
    <header class="page-header"><div><p class="eyebrow">SELFHANDLER</p><h1>{{ t('mentor.title') }}</h1><p>{{ t('mentor.subtitle') }}</p></div><RouterLink class="button secondary" to="/settings/ai">{{ t('nav.ai') }}</RouterLink></header>
    <p class="notice">{{ t('mentor.contextNotice') }}</p>
    <p v-if="settings && (!settings.enabled || !settings.active_connection_id)" class="notice">{{ t('mentor.setupRequired') }}</p>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
    <p v-if="notice" class="notice" role="status">{{ notice }}</p>
    <div class="mentor-conversation" aria-live="polite" aria-relevant="additions">
      <p v-if="!turns.length" class="muted">{{ t('mentor.empty') }}</p>
      <article v-for="turn in turns" :key="turn.id" class="panel mentor-turn">
        <p class="mentor-question">{{ turn.question }}</p>
        <p v-if="turn.answer" class="mentor-answer">{{ turn.answer }}</p>
        <p v-else>{{ t(turn.status !== 'failed' ? 'mentor.pending' : 'mentor.requestFailed') }}</p>
        <details v-if="turn.sources.length"><summary>{{ t('mentor.sources') }}</summary><ul><li v-for="(source, index) in turn.sources" :key="index">{{ source.dataset }}: {{ source.ids.join(', ') }}<span v-if="source.has_more"> · {{ t('mentor.partialSources') }}</span></li></ul></details>
        <article v-for="(action, index) in turn.actions" :key="index" class="mentor-action">
          <strong>{{ action.label }}</strong>
          <dl><div v-for="(value, field) in action.payload" :key="field"><dt>{{ field }}</dt><dd>{{ value }}</dd></div></dl>
          <button :disabled="busy || !online || action.status === 'applied'" @click="confirm(turn, index)">{{ t(action.status === 'applied' ? 'mentor.applied' : 'mentor.confirm') }}</button>
        </article>
        <details><summary>{{ turn.model }} · {{ turn.estimated_usd === null ? t('mentor.unknownCost') : `$${turn.estimated_usd}` }}</summary><p>{{ t('mentor.turnUsage', { input: turn.usage.input, output: turn.usage.output, cached: turn.usage.cached, reasoning: turn.usage.reasoning }) }}</p></details>
      </article>
    </div>
    <form class="panel mentor-composer" @submit.prevent="send">
      <label for="mentor-question">{{ t('mentor.question') }}</label>
      <textarea id="mentor-question" v-model="draft.text" rows="4" maxlength="4000" :disabled="busy || !ready" @input="saveText" />
      <div v-if="draft.audio && !recording" class="mentor-recording">
        <audio :src="audioUrl" controls preload="metadata" />
        <div class="button-row"><button type="button" :disabled="busy || !online" @click="transcribe">{{ t('mentor.transcribe') }}</button><button type="button" class="ghost" :disabled="busy" @click="discardAudio">{{ t('mentor.discardAudio') }}</button></div>
      </div>
      <div class="button-row">
        <button v-if="recording" type="button" class="secondary" @click="stopRecording">{{ t('mentor.stop', { seconds }) }}</button>
        <button v-else type="button" class="secondary" :disabled="busy || !ready || !!draft.audio" @click="startRecording">{{ t('mentor.record') }}</button>
        <button type="submit" :disabled="busy || recording || !ready || !draft.text.trim()">{{ t(busy ? 'mentor.working' : online ? 'mentor.send' : 'mentor.saveOffline') }}</button>
      </div>
      <p class="muted">{{ t('mentor.voiceHelp') }}</p>
    </form>
  </section>
</template>
<style scoped>
.mentor-page { max-width: 880px; margin-inline:auto; }
.mentor-page, .mentor-conversation, .mentor-composer { display:grid; gap:1rem; min-width:0; }
.mentor-page .page-header { flex-wrap:wrap; }
.mentor-question { font-weight:700; }
.mentor-answer, .mentor-question { white-space:pre-wrap; overflow-wrap:anywhere; }
.mentor-composer textarea { width:100%; min-height:100px; resize:vertical; }
.mentor-action { border:1px solid var(--border, #aaa); padding:.8rem; border-radius:12px; margin-block:1rem; }
.mentor-action dl { display:grid; gap:.5rem; }
.mentor-action dd { margin:0; overflow-wrap:anywhere; }
.mentor-action dt { font-size:.8rem; opacity:.7; }
.mentor-recording audio { width:100%; max-width:100%; }
.mentor-page button { min-height:44px; white-space:normal; }
.mentor-page details { overflow-wrap:anywhere; margin-top:.8rem; }
@media(max-width:380px) { .mentor-page .button-row > * { flex:1 1 100%; } }
</style>
