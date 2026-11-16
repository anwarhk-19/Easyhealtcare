<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, errorMessage } from '@/api/client'
import type { Appointment, Token } from '@/api/types'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { time, today } from '@/utils/format'
import BookingDrawer from '@/components/BookingDrawer.vue'
import StatusPill from '@/components/StatusPill.vue'

interface Board { doctors: { doctor_id: string; doctor: string; tokens: Token[]; estimates: { tokens: Record<string, { estimated_wait_minutes: number }> } }[] }
const s = useSession()
const toast = useToast()
const router = useRouter()
const board = ref<Board | null>(null)
const arrivals = ref<Appointment[]>([])
const toBill = ref<Appointment[]>([])
const drawer = ref<'book' | 'walkin' | null>(null)
let timer: ReturnType<typeof setInterval> | undefined

async function load() {
  const [b, a, done] = await Promise.all([
    api.get<Board>('/queue', { branch_id: s.branchId }),
    api.list<Appointment>('/appointments', { date: today(), branch_id: s.branchId, status: 'booked', per_page: 50 }),
    s.can('invoices.create') ? api.list<Appointment>('/appointments', { date: today(), branch_id: s.branchId, status: 'completed', per_page: 50 }) : Promise.resolve({ data: [] as Appointment[] }),
  ])
  board.value = b; arrivals.value = a.data; toBill.value = done.data
}
// Real-time: Reverb broadcasts are invalidation hints; polling is the fallback when sockets are unavailable.
onMounted(() => { load(); timer = setInterval(load, 15000) })
onUnmounted(() => clearInterval(timer))

async function act(fn: () => Promise<unknown>, msg?: string) {
  try { await fn(); if (msg) toast.show(msg); await load() } catch (e) { toast.error(errorMessage(e)) }
}
const checkIn = (a: Appointment) => act(() => api.post(`/appointments/${a.id}/check-in`), 'Checked in')
const tokenAct = (t: Token, action: string) => act(() => api.post(`/queue/${t.id}/${action}`))
async function bill(a: Appointment) {
  try { const inv = await api.post<{ id: string }>(`/invoices/from-appointment/${a.id}`); router.push(`/billing/${inv.id}`) } catch (e) { toast.error(errorMessage(e)) }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-semibold">Front desk · today</h1>
      <div class="flex gap-2">
        <button v-if="s.can('appointments.create')" class="btn-secondary" @click="drawer = 'book'">Book</button>
        <button v-if="s.can('appointments.create')" class="btn-primary" @click="drawer = 'walkin'">+ Walk-in</button>
      </div>
    </div>
    <div class="grid gap-4 xl:grid-cols-[1fr_320px]">
      <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
        <section v-for="d in board?.doctors ?? []" :key="d.doctor_id" class="card">
          <header class="flex items-center justify-between border-b border-slate-100 px-3 py-2"><h2 class="text-sm font-semibold">{{ d.doctor }}</h2><span class="text-xs text-slate-500">{{ d.tokens.filter(t => t.status === 'waiting').length }} waiting</span></header>
          <ul class="divide-y divide-slate-100">
            <li v-for="t in d.tokens.filter(t => !['completed', 'transferred'].includes(t.status))" :key="t.id" class="flex items-center gap-2 px-3 py-2 text-sm">
              <span class="tabular w-14 font-semibold" :class="t.priority === 'emergency' ? 'text-red-700' : ''">{{ t.token_label }}</span>
              <span class="min-w-0 flex-1 truncate">{{ t.appointment?.patient?.full_name }}
                <span v-if="d.estimates.tokens[t.id]" class="text-xs text-slate-500">· ~{{ d.estimates.tokens[t.id].estimated_wait_minutes }}m</span></span>
              <StatusPill :status="t.status" />
              <div v-if="s.can('queue.manage')" class="flex gap-1">
                <button v-if="t.status === 'waiting'" class="btn-secondary btn-sm" title="Call" @click="tokenAct(t, 'call')">📣</button>
                <button v-if="['waiting', 'called'].includes(t.status)" class="btn-secondary btn-sm" title="Skip" @click="tokenAct(t, 'skip')">⏭</button>
                <button v-if="t.status === 'skipped'" class="btn-secondary btn-sm" title="Back to queue" @click="tokenAct(t, 'requeue')">↩</button>
              </div>
            </li>
            <li v-if="!d.tokens.length" class="px-3 py-4 text-center text-xs text-slate-400">No patients yet</li>
          </ul>
        </section>
      </div>
      <aside class="space-y-4">
        <section class="card">
          <h2 class="border-b border-slate-100 px-3 py-2 text-sm font-semibold">Arriving today</h2>
          <ul class="divide-y divide-slate-100 text-sm">
            <li v-for="a in arrivals" :key="a.id" class="flex items-center justify-between px-3 py-2">
              <span><span class="tabular text-slate-500">{{ time(a.scheduled_start) }}</span> {{ a.patient?.full_name }}</span>
              <button v-if="s.can('queue.manage')" class="btn-primary btn-sm" @click="checkIn(a)">Check in</button>
            </li>
            <li v-if="!arrivals.length" class="px-3 py-4 text-center text-xs text-slate-400">No pending arrivals</li>
          </ul>
        </section>
        <section v-if="s.can('invoices.create')" class="card">
          <h2 class="border-b border-slate-100 px-3 py-2 text-sm font-semibold">Ready for billing</h2>
          <ul class="divide-y divide-slate-100 text-sm">
            <li v-for="a in toBill" :key="a.id" class="flex items-center justify-between px-3 py-2"><span>{{ a.patient?.full_name }}</span><button class="btn-secondary btn-sm" @click="bill(a)">Bill</button></li>
            <li v-if="!toBill.length" class="px-3 py-4 text-center text-xs text-slate-400">Nothing to bill</li>
          </ul>
        </section>
      </aside>
    </div>
    <BookingDrawer v-if="drawer" :open="true" :walk-in="drawer === 'walkin'" @close="drawer = null" @done="load" />
  </div>
</template>
