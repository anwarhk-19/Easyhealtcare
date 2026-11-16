<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import type { Patient } from '@/api/types'
import { useSession } from '@/stores/session'
import Drawer from '@/components/Drawer.vue'
import QuickPatientForm from '@/components/QuickPatientForm.vue'

const s = useSession()
const router = useRouter()
const q = ref('')
const rows = ref<Patient[]>([])
const total = ref(0)
const page = ref(1)
const creating = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

async function load() {
  const r = await api.list<Patient>('/patients', { q: q.value, page: page.value })
  rows.value = r.data; total.value = r.meta.pagination.total
}
onMounted(load)
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load() }, 250) })
watch(page, load)
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-semibold">Patients <span class="text-sm font-normal text-slate-500">({{ total }})</span></h1>
      <div class="flex gap-2"><input v-model="q" class="input !w-72" type="search" placeholder="Name, mobile or patient ID" aria-label="Search" /><button v-if="s.can('patients.create')" class="btn-primary" @click="creating = true">+ Register</button></div>
    </div>
    <div class="card overflow-x-auto">
      <table class="table">
        <thead><tr><th>Patient ID</th><th>Name</th><th>Age / gender</th><th>Mobile</th><th>Alerts</th></tr></thead>
        <tbody>
          <tr v-for="p in rows" :key="p.id" class="cursor-pointer hover:bg-slate-50" @click="router.push(`/patients/${p.id}`)">
            <td class="tabular text-xs">{{ p.patient_code }}</td><td class="font-medium">{{ p.full_name }}</td><td>{{ p.age ?? '—' }} · {{ p.gender }}</td><td class="tabular">{{ p.mobile }}</td>
            <td><span v-for="a in p.allergies" :key="a.id" class="mr-1 rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-700">⚠ {{ a.allergen }}</span></td>
          </tr>
          <tr v-if="!rows.length"><td colspan="5" class="py-8 text-center text-slate-400">No patients found</td></tr>
        </tbody>
      </table>
    </div>
    <div class="flex justify-end gap-2"><button class="btn-secondary btn-sm" :disabled="page === 1" @click="page--">Previous</button><button class="btn-secondary btn-sm" :disabled="page * 25 >= total" @click="page++">Next</button></div>
    <Drawer :open="creating" title="Register patient" @close="creating = false"><QuickPatientForm @created="p => router.push(`/patients/${p.id}`)" /></Drawer>
  </div>
</template>
