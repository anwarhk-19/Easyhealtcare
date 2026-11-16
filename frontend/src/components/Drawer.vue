<script setup lang="ts">
defineProps<{ open: boolean; title: string }>()
const emit = defineEmits<{ close: [] }>()
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-40 flex justify-end bg-slate-900/30" @click.self="emit('close')" @keydown.esc="emit('close')">
      <section class="flex h-full w-full max-w-xl flex-col bg-white shadow-xl" role="dialog" :aria-label="title">
        <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
          <h2 class="text-base font-semibold">{{ title }}</h2>
          <button class="btn-secondary btn-sm" aria-label="Close" @click="emit('close')">✕</button>
        </header>
        <div class="flex-1 overflow-y-auto p-5"><slot /></div>
      </section>
    </div>
  </Teleport>
</template>
