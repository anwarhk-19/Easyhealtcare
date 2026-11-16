<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api, errorMessage } from '@/api/client'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { label, money, today } from '@/utils/format'

const s = useSession()
const toast = useToast()
const reports = computed(() => [
  { key: 'appointments', financial: false }, { key: 'doctors', financial: false },
  { key: 'collections', financial: true }, { key: 'outstanding', financial: true }, { key: 'services', financial: true }, { key: 'gst', financial: true },
].filter(r => s.can(r.financial ? 'reports.financial' : 'reports.operational')))
const report = ref(reports.value[0]?.key ?? 'appointments')
const from = ref(today(-29))
const to = ref(today())
const rows = ref<Record<string, unknown>[]>([])
const moneyCols = new Set(['revenue', 'taxable_value', 'cgst', 'sgst', 'total_tax'])

async function load() {
  try { rows.value = await api.get<Record<string, unknown>[]>(`/reports/${report.value}`, { from: from.value, to: to.value, branch_id: s.me!.branch_ids ? s.branchId : undefined }) } catch (e) { toast.error(errorMessage(e)) }
}
watch([report, from, to], load, { immediate: true })
const cols = computed(() => Object.keys(rows.value[0] ?? {}).filter(c => !c.endsWith('_id')))
const fmt = (c: string, v: unknown) => (moneyCols.has(c) && typeof v === 'number' ? money(v) : Array.isArray(v) ? v.join(', ') : String(v ?? ''))
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end gap-2">
      <h1 class="mr-auto text-xl font-semibold">Reports</h1>
      <div><label class="label">Report</label><select v-model="report" class="input"><option v-for="r in reports" :key="r.key" :value="r.key">{{ label(r.key) }}</option></select></div>
      <div><label class="label">From</label><input v-model="from" type="date" class="input" /></div>
      <div><label class="label">To</label><input v-model="to" type="date" class="input" /></div>
      <button v-if="s.can('reports.export')" class="btn-secondary" @click="api.open(`/reports/${report}/export`, { from, to })">Export CSV</button>
    </div>
    <div class="card overflow-x-auto"><table class="table">
      <thead><tr><th v-for="c in cols" :key="c">{{ label(c) }}</th></tr></thead>
      <tbody><tr v-for="(r, i) in rows" :key="i"><td v-for="c in cols" :key="c" class="tabular">{{ fmt(c, r[c]) }}</td></tr>
        <tr v-if="!rows.length"><td :colspan="cols.length || 1" class="py-8 text-center text-slate-400">No data for this range</td></tr></tbody>
    </table></div>
  </div>
</template>
