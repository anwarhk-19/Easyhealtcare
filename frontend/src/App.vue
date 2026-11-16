<script setup lang="ts">
import { useToast } from '@/stores/toast'
const toast = useToast()
</script>

<template>
  <RouterView />
  <div class="fixed right-4 bottom-4 z-50 flex flex-col gap-2" aria-live="polite">
    <div v-for="t in toast.items" :key="t.id" class="rounded-md px-4 py-2 text-sm text-white shadow-lg" :class="t.kind === 'ok' ? 'bg-slate-800' : 'bg-red-700'">{{ t.text }}</div>
  </div>
</template>
