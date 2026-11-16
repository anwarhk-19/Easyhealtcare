<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '@/api/client'
import type { RxItem } from '@/api/types'

const props = defineProps<{ items: RxItem[]; advice: string; readonly: boolean }>()
const emit = defineEmits<{ save: [items: RxItem[], advice: string] }>()
const rows = ref<RxItem[]>(props.items.map(i => ({ ...i })))
const adv = ref(props.advice)
const search = ref('')
const results = ref<{ id: string; generic_name: string; brand_name: string | null; strength: string | null; form: string | null }[]>([])
const FREQ = ['1-0-1', '1-1-1', '1-0-0', '0-0-1', '0-1-0', '1-1-1-1', 'SOS', 'STAT']
watch(() => props.items, v => (rows.value = v.map(i => ({ ...i }))))

let t: ReturnType<typeof setTimeout> | undefined
watch(search, v => {
  clearTimeout(t)
  if (v.length < 2) { results.value = []; return }
  t = setTimeout(async () => { results.value = (await api.list<(typeof results.value)[number]>('/medicines', { q: v, per_page: 8 })).data }, 200)
})
function add(m?: (typeof results.value)[number]) {
  rows.value.push({ medicine_id: m?.id ?? null, medicine_name: m ? `${m.brand_name ?? m.generic_name}` : search.value, strength: m?.strength ?? null, frequency: '1-0-1', duration_value: 5, duration_unit: 'days', timing: 'after_food' })
  search.value = ''; results.value = []
}
const save = () => emit('save', rows.value, adv.value)
</script>

<template>
  <div class="space-y-3">
    <div class="overflow-x-auto">
      <table class="table">
        <thead><tr><th>Medicine</th><th>Dose</th><th>Frequency</th><th>Days</th><th>Timing</th><th>Instructions</th><th v-if="!readonly"></th></tr></thead>
        <tbody>
          <tr v-for="(r, i) in rows" :key="i">
            <td class="min-w-40"><div class="font-medium">{{ r.medicine_name }}</div><div class="text-xs text-slate-500">{{ r.strength }}</div></td>
            <td><input v-model="r.dose" class="input !w-20" placeholder="1 tab" :disabled="readonly" aria-label="Dose" /></td>
            <td><select v-model="r.frequency" class="input !w-28" :disabled="readonly" aria-label="Frequency"><option v-for="f in FREQ" :key="f">{{ f }}</option></select></td>
            <td><input v-model.number="r.duration_value" type="number" min="1" class="input !w-16" :disabled="readonly" aria-label="Days" /></td>
            <td><select v-model="r.timing" class="input !w-32" :disabled="readonly" aria-label="Timing"><option value="after_food">After food</option><option value="before_food">Before food</option><option value="with_food">With food</option><option value="empty_stomach">Empty stomach</option><option value="bedtime">Bedtime</option><option value="any">Any time</option></select></td>
            <td><input v-model="r.instructions" class="input" :disabled="readonly" aria-label="Instructions" /></td>
            <td v-if="!readonly"><button class="btn-secondary btn-sm" aria-label="Remove" @click="rows.splice(i, 1)">✕</button></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="!readonly" class="relative">
      <input v-model="search" class="input" placeholder="Add medicine — search brand or generic" aria-label="Search medicine" @keydown.enter.prevent="add()" />
      <ul v-if="results.length" class="absolute z-20 mt-1 w-full rounded-md border border-slate-200 bg-white shadow-lg">
        <li v-for="m in results" :key="m.id"><button class="w-full px-3 py-2 text-left text-sm hover:bg-primary-50" @click="add(m)"><strong>{{ m.brand_name ?? m.generic_name }}</strong> {{ m.strength }} <span class="text-slate-500">{{ m.generic_name }} · {{ m.form }}</span></button></li>
      </ul>
    </div>
    <div><label class="label">Advice</label><textarea v-model="adv" class="input" rows="2" :disabled="readonly" /></div>
    <button v-if="!readonly" class="btn-secondary btn-sm" @click="save">Save prescription</button>
  </div>
</template>
