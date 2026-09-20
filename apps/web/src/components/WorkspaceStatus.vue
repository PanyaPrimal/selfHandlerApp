<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from '../i18n'
import { commands, discardCommand, retryRejectedCommand, workspaceState, type LocalCommand } from '../offline/workspace'
const { t } = useI18n()
const rows = ref<LocalCommand[]>([])
const expanded = ref(false)
const busy = ref(false)
const removing = ref<string | null>(null)
const root = ref<HTMLElement | null>(null)
const trigger = ref<HTMLButtonElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const panelId = useId()
const visible = computed(() => !workspaceState.online || workspaceState.pending > 0)
const rejectedCount = computed(() => rows.value.filter(row => row.status === 'rejected').length)
const needsAttention = computed(() => rejectedCount.value > 0)
watch(visible, (value) => { if (!value) expanded.value = false })
async function refresh() { rows.value = await commands() }
function changed() { void refresh().catch(() => undefined) }
async function retry(row: LocalCommand) {
  busy.value = true
  try {
    await retryRejectedCommand(row.id)
  } catch (e) { workspaceState.issue = e instanceof Error ? e.message : t('offline.syncFailed') }
  finally { busy.value = false; await refresh() }
}
async function togglePanel() {
  expanded.value = !expanded.value
  if (expanded.value) { await nextTick(); panel.value?.focus() }
}
function closePanel() { expanded.value = false; trigger.value?.focus() }
function outsidePointer(event: PointerEvent) {
  if (expanded.value && event.target instanceof Node && !root.value?.contains(event.target)) expanded.value = false
}
async function remove(id: string) {
  if (removing.value !== id) { removing.value = id; return }
  try { await discardCommand(id); removing.value = null; await refresh() }
  catch (e) { workspaceState.issue = e instanceof Error ? e.message : t('offline.syncFailed') }
}
function details(row: LocalCommand) { try { return JSON.parse(row.body ?? '{}') as Record<string, unknown> } catch { return {} } }
function route(row: LocalCommand): string {
  const module = row.path.split('/')[1]
  return ({ 'time-blocks': '/planner', 'today': '/', 'reviews': '/review', 'sleep': '/routines' } as Record<string, string>)[module ?? ''] ?? `/${module}`
}
async function exportDrafts() {
  const blob = new Blob([JSON.stringify(await commands(), null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a'); link.href = url; link.download = 'selfhandler-pending.json'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
}
onMounted(() => { changed(); window.addEventListener('workspace-queue-changed', changed) })
onBeforeUnmount(() => window.removeEventListener('workspace-queue-changed', changed))
onMounted(() => document.addEventListener('pointerdown', outsidePointer))
onBeforeUnmount(() => document.removeEventListener('pointerdown', outsidePointer))
</script>
<template>
  <aside v-if="visible" ref="root" class="workspace-status" :aria-label="t('offline.review')" @keydown.esc.stop.prevent="closePanel">
    <button ref="trigger" type="button" class="workspace-trigger" :class="{ 'needs-attention': needsAttention }"
      :aria-label="t('offline.review')" :aria-expanded="expanded" :aria-controls="panelId" @click="togglePanel">
      <span aria-hidden="true">{{ needsAttention ? '!' : '↻' }}</span>
      <span aria-live="polite">{{ t(needsAttention ? 'offline.invalidTitle' : workspaceState.online ? 'offline.pendingTitle' : 'offline.offlineTitle', { count: needsAttention ? rejectedCount : workspaceState.pending }) }}</span>
      <span aria-hidden="true">{{ expanded ? '⌄' : '⌃' }}</span>
    </button>
    <section v-if="expanded" :id="panelId" ref="panel" class="workspace-panel" role="region" :aria-label="t('offline.review')" tabindex="-1">
    <div class="workspace-heading"><strong>{{ t('offline.review') }}</strong><button type="button" class="ghost" :aria-label="t('offline.close')" @click="closePanel">×</button></div>
    <p v-if="!workspaceState.online">{{ t('offline.explanation') }}</p>
    <div v-if="workspaceState.pending">
      <button class="ghost" type="button" @click="exportDrafts">{{ t('offline.export') }}</button>
      <article v-for="row in rows" :key="row.id" class="workspace-command">
        <strong>{{ row.title || t('offline.change') }}</strong>
        <p>{{ t(row.status === 'rejected' ? 'offline.rejected' : 'offline.queued') }}</p>
        <p v-if="row.status === 'rejected' && row.message">{{ row.message }}</p>
        <details><summary>{{ t('offline.details') }}</summary><dl><div v-for="(value, field) in details(row)" :key="field"><dt>{{ field }}</dt><dd>{{ value }}</dd></div></dl></details>
        <div class="button-row">
          <RouterLink :to="route(row)">{{ t('offline.openModule') }}</RouterLink>
          <button v-if="row.status === 'rejected'" type="button" :disabled="busy || workspaceState.syncing || !workspaceState.online" @click="retry(row)">{{ t('offline.retry') }}</button>
          <button type="button" class="ghost" :disabled="busy || workspaceState.syncing" @click="remove(row.id)">{{ t(removing === row.id ? 'offline.confirmDiscard' : 'offline.discard') }}</button>
        </div>
      </article>
    </div>
    </section>
  </aside>
</template>
<style scoped>
.workspace-status { position:fixed; z-index:30; right:1rem; bottom:calc(1rem + var(--app-safe-bottom, 0px)); width:max-content; max-width:calc(100vw - 2rem); overflow-wrap:anywhere; }
.workspace-trigger { display:flex; align-items:center; gap:.65rem; max-width:100%; padding:.65rem .9rem; border-radius:999px; background:var(--surface); color:var(--ink); border:1px solid var(--border-strong); box-shadow:var(--shadow); font-size:.85rem; }
.workspace-trigger.needs-attention { border-color:var(--error); }
.workspace-trigger > span:first-child { flex-shrink:0; font-weight:700; color:var(--accent); }
.workspace-trigger.needs-attention > span:first-child { color:var(--error); }
.workspace-panel { position:absolute; right:0; bottom:calc(100% + .65rem); width:min(26rem, calc(100vw - 2rem)); max-height:min(32rem, calc(100dvh - 8rem - var(--app-safe-bottom, 0px))); overflow:auto; overscroll-behavior:contain; padding:1rem; border:1px solid var(--border); border-radius:1rem; background:var(--surface); color:var(--ink); box-shadow:var(--shadow); }
.workspace-heading { display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
.workspace-heading button { min-width:44px; font-size:1.4rem; }
.workspace-command { border-top:1px solid var(--border); padding-block:1rem; margin-top:1rem; }
.workspace-command dd { margin-inline-start:.5rem; white-space:pre-wrap; }
.workspace-status button { min-height:44px; white-space:normal; }
@media (max-width:900px) { .workspace-status { right:.75rem; bottom:calc(92px + var(--app-safe-bottom, 0px)); max-width:calc(100vw - 1.5rem); } .workspace-panel { width:min(26rem, calc(100vw - 1.5rem)); max-height:calc(100dvh - 12rem - var(--app-safe-bottom, 0px)); } }
</style>
