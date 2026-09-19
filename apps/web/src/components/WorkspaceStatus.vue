<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from '../i18n'
import { commands, discardCommand, retryReviewedCommand, synchronizeWorkspace, workspaceState, type LocalCommand } from '../offline/workspace'
const { t } = useI18n()
const rows = ref<LocalCommand[]>([])
const expanded = ref(false)
const busy = ref(false)
const removing = ref<string | null>(null)
async function refresh() { rows.value = await commands() }
function changed() { void refresh().catch(() => undefined) }
async function retry(id: string) {
  busy.value = true
  try { await retryReviewedCommand(id) } catch (e) { workspaceState.issue = e instanceof Error ? e.message : t('offline.syncFailed') }
  finally { busy.value = false; await refresh() }
}
async function remove(id: string) {
  if (removing.value !== id) { removing.value = id; return }
  try { await discardCommand(id); removing.value = null; await refresh() }
  catch (e) { workspaceState.issue = e instanceof Error ? e.message : t('offline.syncFailed') }
}
function details(row: LocalCommand) { try { return JSON.parse(row.body ?? '{}') as Record<string, unknown> } catch { return {} } }
function route(row: LocalCommand): string {
  const module = row.path.split('/')[1]
  return ({ 'time-blocks': '/planner', 'today': '/', 'reviews': '/review', 'sleep': '/' } as Record<string, string>)[module ?? ''] ?? `/${module}`
}
async function exportDrafts() {
  const blob = new Blob([JSON.stringify(await commands(), null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a'); link.href = url; link.download = 'selfhandler-pending.json'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
}
onMounted(() => { changed(); window.addEventListener('workspace-queue-changed', changed) })
onBeforeUnmount(() => window.removeEventListener('workspace-queue-changed', changed))
</script>
<template>
  <section v-if="!workspaceState.online || workspaceState.pending || workspaceState.issue" class="workspace-status notice" aria-live="polite">
    <strong>{{ t(workspaceState.online ? 'offline.pendingTitle' : 'offline.offlineTitle', { count: workspaceState.pending }) }}</strong>
    <p v-if="!workspaceState.online">{{ t('offline.explanation') }}</p>
    <p v-if="workspaceState.issue" role="status">{{ workspaceState.issue }}</p>
    <div class="button-row">
      <button type="button" class="secondary" :disabled="workspaceState.syncing || busy" @click="synchronizeWorkspace">{{ t(workspaceState.syncing ? 'offline.syncing' : 'offline.sync') }}</button>
      <button v-if="workspaceState.pending" type="button" class="ghost" :aria-expanded="expanded" @click="expanded = !expanded">{{ t('offline.review') }}</button>
    </div>
    <div v-if="expanded">
      <p>{{ t('offline.reviewHelp') }}</p>
      <button class="ghost" type="button" @click="exportDrafts">{{ t('offline.export') }}</button>
      <article v-for="row in rows" :key="row.id" class="workspace-command">
        <strong>{{ row.title || t('offline.change') }}</strong>
        <p>{{ t(row.status === 'pending' ? 'offline.queued' : row.status === 'conflict' ? 'offline.conflict' : 'offline.rejected') }}</p>
        <p v-if="row.message">{{ row.message }}</p>
        <details><summary>{{ t('offline.details') }}</summary><dl><div v-for="(value, field) in details(row)" :key="field"><dt>{{ field }}</dt><dd>{{ value }}</dd></div></dl></details>
        <div class="button-row">
          <RouterLink :to="route(row)">{{ t('offline.openModule') }}</RouterLink>
          <button v-if="row.status === 'conflict'" type="button" :disabled="busy || !workspaceState.online" @click="retry(row.id)">{{ t('offline.applyReviewed') }}</button>
          <button type="button" class="ghost" :disabled="busy || workspaceState.syncing" @click="remove(row.id)">{{ t(removing === row.id ? 'offline.confirmDiscard' : 'offline.discard') }}</button>
        </div>
      </article>
    </div>
  </section>
</template>
<style scoped>
.workspace-status { margin-bottom:1rem; overflow-wrap:anywhere; }
.workspace-command { border-top:1px solid currentColor; padding-block:1rem; margin-top:1rem; }
.workspace-command dd { margin-inline-start:.5rem; white-space:pre-wrap; }
.workspace-status button { min-height:44px; white-space:normal; }
</style>
