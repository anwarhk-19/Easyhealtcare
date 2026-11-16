<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, errorMessage } from '@/api/client'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { dateTime } from '@/utils/format'
import StatusPill from '@/components/StatusPill.vue'
import Drawer from '@/components/Drawer.vue'

interface Item { id: string; status: string; sample_barcode: string | null; results: { key: string; name: string; value: string; unit: string; flag: string; range: string | null }[] | null; test: { id: string; name: string; parameters: { key: string; name: string; unit: string }[] | null } }
interface Order { id: string; order_number: string; status: string; priority: string; created_at: string; patient: { full_name: string; patient_code: string }; items: Item[] }
const s = useSession()
const toast = useToast()
const orders = ref<Order[]>([])
const active = ref<Order | null>(null)
const values = reactive<Record<string, Record<string, string>>>({})
const filter = ref('')

async function load() { orders.value = (await api.list<Order>('/lab-orders', { status: filter.value || undefined, per_page: 50 })).data }
onMounted(load)
async function open(o: Order) { active.value = await api.get<Order>(`/lab-orders/${o.id}`); for (const i of active.value.items) values[i.id] = Object.fromEntries((i.results ?? []).map(r => [r.key, r.value])) }
async function act(fn: () => Promise<unknown>, msg: string) {
  try { await fn(); toast.show(msg); await load(); if (active.value) await open(active.value) } catch (e) { toast.error(errorMessage(e)) }
}
const collect = (i: Item) => act(() => api.post(`/lab-orders/${active.value!.id}/items/${i.id}/collect-sample`), 'Sample collected')
const saveResults = (i: Item) => act(() => api.put(`/lab-orders/${active.value!.id}/items/${i.id}/results`, { values: values[i.id] }), 'Results saved')
const release = () => act(() => api.post(`/lab-orders/${active.value!.id}/release`), 'Report released')
const share = () => act(() => api.post(`/lab-orders/${active.value!.id}/share`, { channels: ['whatsapp'] }), 'Report sent to patient')
function upload(ev: Event) {
  const f = (ev.target as HTMLInputElement).files?.[0]; if (!f) return
  const fd = new FormData(); fd.append('file', f)
  act(() => api.post(`/lab-orders/${active.value!.id}/report`, fd), 'Report uploaded')
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between"><h1 class="text-xl font-semibold">Lab worklist</h1>
      <select v-model="filter" class="input !w-48" aria-label="Status" @change="load"><option value="">Open orders</option><option value="released">Released</option></select></div>
    <div class="card overflow-x-auto"><table class="table">
      <thead><tr><th>Order</th><th>Patient</th><th>Tests</th><th>Ordered</th><th>Status</th></tr></thead>
      <tbody>
        <tr v-for="o in orders" :key="o.id" class="cursor-pointer hover:bg-slate-50" @click="open(o)">
          <td class="tabular">{{ o.order_number }} <span v-if="o.priority === 'urgent'" class="ml-1 rounded bg-red-50 px-1 text-xs text-red-700">URGENT</span></td>
          <td>{{ o.patient.full_name }}<div class="text-xs text-slate-500">{{ o.patient.patient_code }}</div></td>
          <td>{{ o.items.map(i => i.test.name).join(', ') }}</td><td>{{ dateTime(o.created_at) }}</td><td><StatusPill :status="o.status" /></td>
        </tr>
        <tr v-if="!orders.length"><td colspan="5" class="py-8 text-center text-slate-400">Worklist is empty</td></tr>
      </tbody>
    </table></div>

    <Drawer :open="!!active" :title="active ? `${active.order_number} · ${active.patient.full_name}` : ''" @close="active = null">
      <div v-if="active" class="space-y-4">
        <div v-for="i in active.items" :key="i.id" class="rounded-md border border-slate-200 p-3">
          <div class="mb-2 flex items-center justify-between"><strong class="text-sm">{{ i.test.name }}</strong><StatusPill :status="i.status" /></div>
          <button v-if="i.status === 'ordered' && s.can('lab.results.enter')" class="btn-secondary btn-sm" @click="collect(i)">Collect sample</button>
          <form v-else-if="s.can('lab.results.enter') && active.status !== 'released'" class="grid grid-cols-2 gap-2" @submit.prevent="saveResults(i)">
            <div v-for="p in i.test.parameters ?? []" :key="p.key"><label class="label">{{ p.name }} <span class="text-slate-400">{{ p.unit }}</span></label><input v-model="values[i.id][p.key]" class="input" /></div>
            <button class="btn-secondary btn-sm col-span-2">Save results</button>
          </form>
          <ul v-if="i.results" class="mt-2 text-sm"><li v-for="r in i.results" :key="r.key">{{ r.name }}: <strong :class="r.flag === 'critical' ? 'text-red-700' : r.flag !== 'normal' ? 'text-amber-700' : ''">{{ r.value }} {{ r.unit }}</strong> <span class="text-xs text-slate-500">({{ r.range }})</span></li></ul>
        </div>
        <div class="flex flex-wrap gap-2">
          <label v-if="s.can('lab.results.enter') && active.status !== 'released'" class="btn-secondary btn-sm cursor-pointer">Upload report PDF<input type="file" accept="application/pdf,image/*" class="hidden" @change="upload" /></label>
          <button v-if="['report_ready', 'released'].includes(active.status)" class="btn-secondary btn-sm" @click="api.open(`/lab-orders/${active.id}/pdf`)">Preview report</button>
          <button v-if="active.status === 'report_ready' && s.can('lab.results.release')" class="btn-primary btn-sm" @click="release">Verify & release</button>
          <button v-if="active.status === 'released' && s.can('lab.results.release')" class="btn-secondary btn-sm" @click="share">Send to patient</button>
        </div>
      </div>
    </Drawer>
  </div>
</template>
