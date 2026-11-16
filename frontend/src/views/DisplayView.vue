<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { api } from '@/api/client'

const props = defineProps<{ token: string }>()
interface Board { branch: string; updated_at: string; doctors: { doctor: string; now_serving: string | null; next: { token: string; initials: string }[] }[] }
const board = ref<Board | null>(null)
let timer: ReturnType<typeof setInterval> | undefined

async function refresh() { board.value = await api.get<Board>(`/api/public/v1/display/${props.token}`) }
onMounted(() => { refresh(); timer = setInterval(refresh, 10000) })
onUnmounted(() => clearInterval(timer))
</script>

<template>
  <main class="min-h-screen bg-slate-900 p-8 text-white">
    <h1 class="mb-6 text-3xl font-semibold">{{ board?.branch ?? 'Queue' }}</h1>
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
      <section v-for="d in board?.doctors ?? []" :key="d.doctor" class="rounded-xl bg-slate-800 p-6">
        <h2 class="text-xl text-slate-300">{{ d.doctor }}</h2>
        <div class="mt-4 text-sm tracking-wide text-slate-400 uppercase">Now serving</div>
        <div class="tabular text-6xl font-bold text-teal-300">{{ d.now_serving ?? '—' }}</div>
        <div class="mt-6 text-sm tracking-wide text-slate-400 uppercase">Next</div>
        <ul class="mt-2 flex flex-wrap gap-3"><li v-for="n in d.next" :key="n.token" class="tabular rounded-lg bg-slate-700 px-3 py-2 text-2xl">{{ n.token }} <span class="text-base text-slate-400">{{ n.initials }}</span></li></ul>
      </section>
    </div>
    <p v-if="board && !board.doctors.length" class="text-2xl text-slate-400">No patients waiting.</p>
  </main>
</template>
