<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, errorMessage } from '@/api/client'
import type { Patient } from '@/api/types'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { dateTime, label, money } from '@/utils/format'
import StatusPill from '@/components/StatusPill.vue'
import BookingDrawer from '@/components/BookingDrawer.vue'

const props = defineProps<{ id: string }>()
const s = useSession()
const toast = useToast()
const router = useRouter()
interface Ev { type: string; id: string; at: string; status: string; doctor?: string; chief_complaint?: string; diagnoses?: string[]; tests?: string[]; number?: string; total?: number; balance_due?: number }
const patient = ref<Patient | null>(null)
const timeline = ref<{ clinical_access: boolean; events: Ev[] }>({ clinical_access: false, events: [] })
const docs = ref<{ id: string; title: string; category: string; created_at: string }[]>([])
const consents = ref<{ purpose: string; status: string; created_at: string }[]>([])
const balance = ref<{ outstanding: number; advance_credit: number } | null>(null)
const tab = ref<'timeline' | 'history' | 'documents' | 'consents'>('timeline')
const booking = ref<'book' | 'walkin' | null>(null)
const allergy = reactive({ allergen: '', allergen_type: 'drug', severity: 'moderate', reaction: '' })
const condition = reactive({ name: '', type: 'chronic' })
const upload = reactive({ title: '', category: 'report', file: null as File | null })

async function load() {
  patient.value = await api.get<Patient>(`/patients/${props.id}`)
  timeline.value = await api.get(`/patients/${props.id}/timeline`)
  if (s.can('patients.documents.view')) docs.value = await api.get(`/patients/${props.id}/documents`)
  consents.value = await api.get(`/patients/${props.id}/consents`)
  if (s.can('invoices.view')) balance.value = await api.get(`/patients/${props.id}/balance`)
}
onMounted(load)

async function addHistory(kind: 'allergies' | 'conditions', body: object) {
  try { await api.post(`/patients/${props.id}/${kind}`, body); await load(); toast.show('Saved') } catch (e) { toast.error(errorMessage(e)) }
}
async function uploadDoc() {
  if (!upload.file) return
  const fd = new FormData(); fd.append('file', upload.file); fd.append('title', upload.title || upload.file.name); fd.append('category', upload.category); fd.append('branch_id', s.branchId)
  try { await api.post(`/patients/${props.id}/documents`, fd); upload.file = null; upload.title = ''; await load() } catch (e) { toast.error(errorMessage(e)) }
}
async function setConsent(purpose: string, status: 'granted' | 'revoked') {
  try { await api.post(`/patients/${props.id}/consents`, { purpose, status }); await load() } catch (e) { toast.error(errorMessage(e)) }
}
const latestConsent = (purpose: string) => consents.value.find(c => c.purpose === purpose)?.status ?? 'not recorded'
async function startConsult() {
  try { const c = await api.post<{ id: string }>('/consultations', { patient_id: props.id, branch_id: s.branchId }); router.push(`/consultations/${c.id}`) } catch (e) { toast.error(errorMessage(e)) }
}
</script>

<template>
  <div v-if="patient" class="space-y-4">
    <header class="card flex flex-wrap items-start justify-between gap-4 p-4">
      <div>
        <h1 class="text-xl font-semibold">{{ patient.full_name }}</h1>
        <p class="text-sm text-slate-600">{{ patient.patient_code }} · {{ patient.age ?? '?' }} yrs · {{ patient.gender }} · {{ patient.mobile }} <span v-if="patient.blood_group">· {{ patient.blood_group }}</span></p>
        <div class="mt-2 flex flex-wrap gap-1">
          <span v-for="a in patient.allergies" :key="a.id" class="rounded bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">⚠ {{ a.allergen }} ({{ a.severity }})</span>
          <span v-for="c in patient.conditions" :key="c.id" class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ c.name }}</span>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span v-if="balance && balance.outstanding" class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-900">Due {{ money(balance.outstanding) }}</span>
        <span v-if="balance && balance.advance_credit" class="rounded bg-green-50 px-2 py-1 text-xs text-green-800">Credit {{ money(balance.advance_credit) }}</span>
        <button v-if="s.can('appointments.create')" class="btn-secondary" @click="booking = 'book'">Book</button>
        <button v-if="s.can('appointments.create')" class="btn-secondary" @click="booking = 'walkin'">Walk-in</button>
        <button v-if="s.isDoctor" class="btn-primary" @click="startConsult">Start consultation</button>
      </div>
    </header>

    <nav class="flex gap-1 border-b border-slate-200" role="tablist">
      <button v-for="t in (['timeline', 'history', 'documents', 'consents'] as const)" :key="t" role="tab" :aria-selected="tab === t" class="-mb-px border-b-2 px-3 py-2 text-sm" :class="tab === t ? 'border-primary-600 font-medium text-primary-700' : 'border-transparent text-slate-500'" @click="tab = t">{{ label(t) }}</button>
    </nav>

    <section v-if="tab === 'timeline'" class="card divide-y divide-slate-100">
      <p v-if="!timeline.clinical_access" class="bg-slate-50 px-4 py-2 text-xs text-slate-500">Clinical notes are hidden for your role.</p>
      <div v-for="e in timeline.events" :key="e.type + e.id" class="flex items-start gap-3 px-4 py-3 text-sm">
        <span class="w-36 shrink-0 text-xs text-slate-500">{{ dateTime(e.at) }}</span>
        <span class="w-28 shrink-0 font-medium">{{ label(e.type) }}</span>
        <div class="min-w-0 flex-1">
          <template v-if="e.type === 'consultation'"><RouterLink :to="`/consultations/${e.id}`" class="text-primary-700 hover:underline">{{ e.chief_complaint || 'Consultation' }}</RouterLink> <span class="text-slate-500">· {{ e.doctor }} · {{ e.diagnoses?.join(', ') }}</span></template>
          <template v-else-if="e.type === 'invoice'"><RouterLink :to="`/billing/${e.id}`" class="text-primary-700 hover:underline">{{ e.number }}</RouterLink> · {{ money(e.total) }} <span v-if="e.balance_due">· due {{ money(e.balance_due) }}</span></template>
          <template v-else-if="e.type === 'lab_order'">{{ e.tests?.join(', ') }}</template>
          <template v-else>{{ e.doctor }}</template>
        </div>
        <StatusPill :status="e.status" />
      </div>
      <p v-if="!timeline.events.length" class="p-6 text-center text-sm text-slate-400">No history yet</p>
    </section>

    <section v-if="tab === 'history'" class="grid gap-4 md:grid-cols-2">
      <div class="card space-y-2 p-4">
        <h2 class="text-sm font-semibold">Allergies</h2>
        <ul class="text-sm"><li v-for="a in patient.allergies" :key="a.id">{{ a.allergen }} — {{ a.allergen_type }}, {{ a.severity }} <span class="text-slate-500">{{ a.reaction }}</span></li></ul>
        <form v-if="s.can('patients.update')" class="grid grid-cols-2 gap-2" @submit.prevent="addHistory('allergies', allergy)">
          <input v-model="allergy.allergen" class="input" placeholder="Allergen" required aria-label="Allergen" />
          <select v-model="allergy.allergen_type" class="input" aria-label="Type"><option>drug</option><option>food</option><option>environmental</option></select>
          <select v-model="allergy.severity" class="input" aria-label="Severity"><option>mild</option><option>moderate</option><option>severe</option></select>
          <input v-model="allergy.reaction" class="input" placeholder="Reaction" aria-label="Reaction" />
          <button class="btn-secondary btn-sm col-span-2">Add allergy</button>
        </form>
      </div>
      <div class="card space-y-2 p-4">
        <h2 class="text-sm font-semibold">Conditions & history</h2>
        <ul class="text-sm"><li v-for="c in patient.conditions" :key="c.id">{{ c.name }} <span class="text-slate-500">{{ c.icd10_code }} · {{ c.status }}</span></li></ul>
        <form v-if="s.can('patients.update')" class="flex gap-2" @submit.prevent="addHistory('conditions', condition)">
          <input v-model="condition.name" class="input" placeholder="Condition" required aria-label="Condition" />
          <select v-model="condition.type" class="input !w-36" aria-label="Type"><option>chronic</option><option>past_illness</option><option>surgery</option><option>family</option></select>
          <button class="btn-secondary btn-sm">Add</button>
        </form>
      </div>
    </section>

    <section v-if="tab === 'documents'" class="card p-4">
      <form v-if="s.can('patients.documents.upload')" class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="uploadDoc">
        <input class="input !w-auto" type="file" accept="application/pdf,image/*" aria-label="File" @change="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
        <input v-model="upload.title" class="input !w-48" placeholder="Title" aria-label="Title" />
        <select v-model="upload.category" class="input !w-44" aria-label="Category"><option v-for="c in ['report', 'prescription_external', 'imaging', 'clinical_photo', 'consent', 'id_proof', 'other']" :key="c" :value="c">{{ label(c) }}</option></select>
        <button class="btn-primary btn-sm" :disabled="!upload.file">Upload</button>
      </form>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="d in docs" :key="d.id" class="flex items-center justify-between py-2"><span>{{ d.title }} <span class="text-xs text-slate-500">{{ label(d.category) }} · {{ dateTime(d.created_at) }}</span></span><button class="btn-secondary btn-sm" @click="api.open(`/documents/${d.id}/download`)">Open</button></li>
        <li v-if="!docs.length" class="py-4 text-center text-slate-400">No documents</li>
      </ul>
    </section>

    <section v-if="tab === 'consents'" class="card divide-y divide-slate-100">
      <div v-for="p in ['data_processing', 'whatsapp', 'sms', 'email', 'clinical_photos', 'ai_processing']" :key="p" class="flex items-center justify-between px-4 py-2 text-sm">
        <span>{{ label(p) }}</span>
        <span class="flex items-center gap-2"><StatusPill :status="latestConsent(p) === 'granted' ? 'active' : 'draft'" /><span class="text-xs text-slate-500">{{ latestConsent(p) }}</span>
          <template v-if="s.can('patients.consents.manage')"><button class="btn-secondary btn-sm" @click="setConsent(p, 'granted')">Grant</button><button class="btn-secondary btn-sm" @click="setConsent(p, 'revoked')">Revoke</button></template></span>
      </div>
    </section>
    <BookingDrawer v-if="booking" :open="true" :walk-in="booking === 'walkin'" :patient="patient" @close="booking = null" @done="load" />
  </div>
</template>
