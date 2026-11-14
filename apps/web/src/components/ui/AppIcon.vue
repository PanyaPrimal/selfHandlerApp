<script setup lang="ts">
defineProps<{ name: string }>()
const paths: Record<string, string> = {
  today: 'M3 11 12 3l9 8M5 10v10h5v-6h4v6h5V10',
  search: 'M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14Zm5 12 6 6',
  planner: 'M5 5h14v15H5zM8 3v4m8-4v4M5 10h14',
  storage: 'M5 5h14v15H5zM8 9h8m-8 4h6m-6 4h4',
  habits: 'm4 12 5 5L20 6',
  routines: 'M20 7A9 9 0 1 0 21 14M20 3v5h-5',
  workouts: 'm5 5 14 14M3 7l4-4m10 18 4-4M2 5l3-3m14 20 3-3',
  nutrition: 'M5 3v6c0 3 4 3 4 0V3M7 3v18m11 0V3c-5 3-5 10 0 10',
  supplements: 'm8 4 12 12a5 5 0 0 1-7 7L1 11a5 5 0 0 1 7-7Zm-3 9 7-7',
  body: 'M12 3a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM5 21v-4a7 7 0 0 1 14 0v4',
  finance: 'M4 6h15v14H4zM4 6V4h12m0 7h5v5h-5z',
  goals: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 5a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z',
  review: 'M5 4h14v16H5zM8 8h8m-8 4h8m-8 4h4',
  analytics: 'M4 4v16h17M8 16v-5m5 5V7m5 9V3',
  mentor: 'M4 4h16v12H9l-5 4V4Zm4 5h8m-8 3h5',
  notifications: 'M5 17h14l-2-3V9A5 5 0 0 0 7 9v5l-2 3Zm5 3h4',
  moon: 'M20 14A9 9 0 0 1 10 4a9 9 0 1 0 10 10Z',
  more: 'M5 12h.01M12 12h.01M19 12h.01',
  arrow: 'M5 12h14m-5-5 5 5-5 5',
  plus: 'M12 5v14M5 12h14',
  settings: 'M4 7h16M4 17h16M8 4v6m8 4v6',
}
</script>

<template>
  <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path :d="paths[name] ?? paths.settings" />
  </svg>
</template>

<style scoped>
.app-icon { width: 20px; height: 20px; flex: 0 0 20px; }
</style>
