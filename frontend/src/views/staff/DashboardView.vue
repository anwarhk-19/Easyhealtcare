<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, errorMessage } from '@/api/client'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { money, today } from '@/utils/format'
import StatTile from '@/components/StatTile.vue'

interface Dash {
  patients: { total: number; new: number }
  today: { appointments: number; completed: number; waiting: number; revenue?: number }
  appointments: { total: number; pending: number; cancelled: number; no_show: number }
  consultations_completed: number
  revenue?: { collected: number; billed: number; outstanding: number; daily: { date: string; total: number }[] }
  doctors: { doctor_id: string; name: string; appointments: number; completed: number; no_show: number; revenue?: number }[]
  services?: { description: string; quantity: number; revenue: number }[]
  branches: { name: string; appointments: number; collected?: number }[]
}
const s = useSession()
const toast = useToast()
const from = ref(today(-29))
const to = ref(today())
const data = ref<Dash | null>(null)
const ai = ref<string | null>(null)
const aiBusy = ref(false)

async function load() { data.value = await api.get<Dash>('/dashboard', { from: from.value, to: to.value, branch_id: s.me!.branch_ids ? s.branchId : undefined }) }
onMounted(load)
watch([from, to], load)

const maxDaily = computed(() => Math.max(1, ...(data.value?.revenue?.daily ?? []).map(d => d.total)))

async function summarise() {
  aiBusy.value = true
  try { ai.value = (await api.post<{ draft: string }>('/ai/run', { feature: 'analytics_summary', input: { from: from.value, to: to.value } })).draft }
  catch (e) { toast.error(errorMessage(e)) } finally { aiBusy.value = false }
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <h1 class="text-xl font-semibold">Dashboard</h1>
      <div class="flex items-end gap-2">
        <div><label class="label">From</label><input v-model="from" type="date" class="input" /></div>
        <div><label class="label">To</label><input v-model="to" type="date" class="input" /></div>
        <button v-if="s.hasFeature('ai') && s.can('ai.use') && s.can('reports.financial')" class="btn-secondary" :disabled="aiBusy" @click="summarise">✨ AI summary</button>
      </div>
    </div>
    <div v-if="ai" class="card border-primary-100 bg-primary-50 p-4 text-sm whitespace-pre-line"><div class="mb-1 text-xs font-semibold text-primary-700">AI draft — review before acting</div>{{ ai }}</div>

    <template v-if="data">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatTile label="Today's appointments" :value="data.today.appointments" :hint="`${data.today.completed} completed · ${data.today.waiting} waiting`" />
        <StatTile v-if="data.today.revenue !== undefined" label="Collected today" :value="money(data.today.revenue)" />
        <StatTile label="Patients" :value="data.patients.total" :hint="`${data.patients.new} new in range`" />
        <StatTile label="Consultations completed" :value="data.consultations_completed" />
        <StatTile label="Appointments in range" :value="data.appointments.total" :hint="`${data.appointments.pending} pending · ${data.appointments.cancelled} cancelled · ${data.appointments.no_show} no-show`" />
        <template v-if="data.revenue">
          <StatTile label="Collected in range" :value="money(data.revenue.collected)" />
          <StatTile label="Billed in range" :value="money(data.revenue.billed)" />
          <StatTile label="Outstanding" :value="money(data.revenue.outstanding)" />
        </template>
      </div>

      <section v-if="data.revenue?.daily.length" class="card p-4">
        <h2 class="mb-3 text-sm font-semibold">Daily collections</h2>
        <div class="flex h-40 items-end gap-1" role="img" aria-label="Daily collections bar chart">
          <div v-for="d in data.revenue.daily" :key="d.date" class="group relative max-w-12 flex-1 rounded-t bg-primary-600/80 hover:bg-primary-700" :style="{ height: (d.total / maxDaily * 100) + '%' }">
            <span class="pointer-events-none absolute -top-8 left-1/2 hidden -translate-x-1/2 rounded bg-slate-800 px-2 py-1 text-xs whitespace-nowrap text-white group-hover:block">{{ d.date }} · {{ money(d.total) }}</span>
          </div>
        </div>
      </section>

      <div class="grid gap-4 lg:grid-cols-2">
        <section class="card overflow-x-auto">
          <h2 class="px-4 pt-4 text-sm font-semibold">Doctor performance</h2>
          <table class="table mt-2"><thead><tr><th>Doctor</th><th>Appts</th><th>Done</th><th>No-show</th><th v-if="data.revenue">Revenue</th></tr></thead>
            <tbody><tr v-for="d in data.doctors" :key="d.doctor_id"><td>{{ d.name }}</td><td class="tabular">{{ d.appointments }}</td><td class="tabular">{{ d.completed }}</td><td class="tabular">{{ d.no_show }}</td><td v-if="data.revenue" class="tabular">{{ money(d.revenue) }}</td></tr></tbody>
          </table>
        </section>
        <section v-if="data.services" class="card overflow-x-auto">
          <h2 class="px-4 pt-4 text-sm font-semibold">Top services by revenue</h2>
          <table class="table mt-2"><thead><tr><th>Service</th><th>Qty</th><th>Revenue</th></tr></thead>
            <tbody><tr v-for="sv in data.services.slice(0, 8)" :key="sv.description"><td>{{ sv.description }}</td><td class="tabular">{{ sv.quantity }}</td><td class="tabular">{{ money(sv.revenue) }}</td></tr></tbody>
          </table>
        </section>
        <section v-if="data.branches.length > 1" class="card overflow-x-auto">
          <h2 class="px-4 pt-4 text-sm font-semibold">Branch performance</h2>
          <table class="table mt-2"><thead><tr><th>Branch</th><th>Appointments</th><th v-if="data.revenue">Collected</th></tr></thead>
            <tbody><tr v-for="b in data.branches" :key="b.name"><td>{{ b.name }}</td><td class="tabular">{{ b.appointments }}</td><td v-if="data.revenue" class="tabular">{{ money(b.collected) }}</td></tr></tbody>
          </table>
        </section>
      </div>
    </template>
  </div>
</template>
