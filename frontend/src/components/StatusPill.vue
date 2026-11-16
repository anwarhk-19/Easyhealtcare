<script setup lang="ts">
import { STATUS_STYLES, label } from '@/utils/format'
defineProps<{ status: string }>()
</script>

<template>
  <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="STATUS_STYLES[status] ?? 'bg-slate-100 text-slate-700'">{{ label(status) }}</span>
</template>
