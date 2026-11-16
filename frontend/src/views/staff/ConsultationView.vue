<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, errorMessage } from '@/api/client'
import type { Consultation, RxItem } from '@/api/types'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { dateTime } from '@/utils/format'
import TemplateSection from '@/components/TemplateSection.vue'
import RxEditor from '@/components/RxEditor.vue'
import StatusPill from '@/components/StatusPill.vue'

const props = defineProps<{ id: string }>()
const s = useSession()
const toast = useToast()
const c = ref<Consultation | null>(null)
const history = ref<Consultation[]>([])
const warnings = ref<{ medicine: string; allergen: string; severity: string }[]>([])
const savedAt = ref('')
const core = reactive({ chief_complaint: '', notes: '', treatment_plan: '', follow_up_date: '' })
const vitals = reactive<Record<string, number | null>>({ height_cm: null, weight_kg: null, temperature_c: null, pulse_bpm: null, bp_systolic: null, bp_diastolic: null, spo2: null, blood_sugar: null })
const icdQ = ref('')
const icdResults = ref<{ code: string; description: string }[]>([])
const diagnoses = ref<Consultation['diagnoses']>([])
const labTests = ref<{ id: string; name: string; code: string }[]>([])
const selectedTests = ref<string[]>([])
const addendum = ref('')
const teeth = ref<{ teeth: number[]; current: Record<string, { condition: string; surfaces: string | null }> } | null>(null)
const eye = reactive<Record<string, unknown>>({ va_re_unaided: '', va_le_unaided: '', iop_re: null, iop_le: null, refraction: { re: { sph: null, cyl: null, axis: null }, le: { sph: null, cyl: null, axis: null } } })

const locked = computed(() => c.value?.status === 'signed')
const isMine = computed(() => c.value?.doctor.id === s.me?.doctor_profile_id)
const readonly = computed(() => locked.value || !isMine.value)
const sections = computed(() => c.value?.template?.definition.sections ?? [])
const sectionData = (key: string) => (c.value?.sections.find(x => x.section_key === key)?.data ?? {}) as Record<string, unknown>
const latestVitals = computed(() => c.value?.vitals.at(-1) ?? null)

let hydrated = false
let hydrating = false
async function load() {
  c.value = await api.get<Consultation>(`/consultations/${props.id}`)
  // Hydrate editable fields only once, so a reload never overwrites text the doctor has not autosaved yet.
  if (!hydrated) {
    hydrating = true
    Object.assign(core, { chief_complaint: c.value.chief_complaint ?? '', notes: c.value.notes ?? '', treatment_plan: c.value.treatment_plan ?? '', follow_up_date: c.value.follow_up_date?.slice(0, 10) ?? '' })
    diagnoses.value = c.value.diagnoses.map(d => ({ ...d }))
    hydrated = true
    setTimeout(() => (hydrating = false))
  }
  const all = await api.get<Consultation[]>(`/patients/${c.value.patient.id}/consultations`)
  history.value = all.filter(x => x.id !== props.id)
  if (s.hasFeature('lab')) labTests.value = (await api.list<{ id: string; name: string; code: string }>('/lab-tests', { per_page: 100 })).data
  if (s.hasFeature('specialities') && sections.value.some(x => x.widget === 'dental.chart')) teeth.value = await api.get(`/patients/${c.value.patient.id}/dental-chart`)
  if (s.hasFeature('specialities') && sections.value.some(x => x.widget === 'ophthalmology.eye_exam')) { const e = await api.get<Record<string, unknown> | null>(`/consultations/${props.id}/eye-exam`); if (e) Object.assign(eye, e) }
}
onMounted(load)

async function guarded(fn: () => Promise<unknown>, msg?: string) {
  try { await fn(); savedAt.value = new Date().toLocaleTimeString(); if (msg) toast.show(msg) } catch (e) { toast.error(errorMessage(e)) }
}
let t: ReturnType<typeof setTimeout> | undefined
const saveCore = () => guarded(() => api.patch(`/consultations/${props.id}`, { ...core, follow_up_date: core.follow_up_date || null }))
watch(core, () => {
  if (readonly.value || hydrating) return
  clearTimeout(t)
  t = setTimeout(saveCore, 800) // autosave
}, { deep: true })
/** Flush a pending autosave immediately (before sign or navigation-triggering actions). */
async function flush() { if (t) { clearTimeout(t); t = undefined; await saveCore() } }

const saveSection = (key: string, data: Record<string, unknown>) => guarded(() => api.put(`/consultations/${props.id}/sections/${key}`, { data }))
const saveVitals = () => guarded(() => api.post(`/consultations/${props.id}/vitals`, Object.fromEntries(Object.entries(vitals).filter(([, v]) => v !== null && v !== ('' as unknown)))), 'Vitals recorded').then(load)
watch(icdQ, async v => { icdResults.value = v.length > 1 ? await api.get('/icd10', { q: v }) : [] })
function addDx(code: string | null, description: string) { diagnoses.value.push({ icd10_code: code, description, type: 'provisional', is_primary: diagnoses.value.length === 0 }); icdQ.value = ''; saveDx() }
const saveDx = () => guarded(() => api.put(`/consultations/${props.id}/diagnoses`, { diagnoses: diagnoses.value }))
async function saveRx(items: RxItem[], advice: string) {
  await flush()
  await guarded(async () => {
    const r = await api.put<{ warnings: typeof warnings.value }>(`/consultations/${props.id}/prescription`, { items, advice })
    warnings.value = r.warnings
  }, 'Prescription saved')
  await load()
}
async function copyLastRx() {
  const prev = history.value.find(h => h.prescription?.id)
  if (!prev?.prescription) return toast.error('No previous prescription')
  await guarded(() => api.post(`/consultations/${props.id}/prescription/copy-from/${prev.prescription!.id}`), 'Copied previous prescription')
  await load()
}
const orderLabs = () => guarded(() => api.post('/lab-orders', { patient_id: c.value!.patient.id, branch_id: s.branchId, test_ids: selectedTests.value, consultation_id: props.id }), 'Lab tests ordered').then(() => (selectedTests.value = []))
async function toothClick(n: number) {
  const condition = prompt(`Tooth ${n} condition (caries, missing, filled, rct, crown, healthy…)`)
  if (!condition) return
  const surfaces = prompt('Surfaces (e.g. O,D) — optional') || undefined
  await guarded(() => api.post(`/patients/${c.value!.patient.id}/dental-chart`, { consultation_id: props.id, entries: [{ tooth_number: n, condition, surfaces }] }))
  teeth.value = await api.get(`/patients/${c.value!.patient.id}/dental-chart`)
}
const saveEye = () => guarded(() => api.put(`/consultations/${props.id}/eye-exam`, eye), 'Eye exam saved')
async function sign() {
  if (!confirm('Sign and lock this consultation? You can only add addenda afterwards.')) return
  await flush()
  await guarded(() => api.post(`/consultations/${props.id}/sign`), 'Consultation signed')
  await load()
  const token = c.value?.appointment_id ? (await api.get<{ token: { id: string; status: string } | null }>(`/appointments/${c.value.appointment_id}`)).token : null
  if (token?.status === 'in_consultation') await guarded(() => api.post(`/queue/${token.id}/complete`))
}
const share = () => guarded(() => api.post(`/prescriptions/${c.value!.prescription!.id}/share`, { channels: ['whatsapp', 'sms'] }), 'Prescription sent to patient')
const addAddendum = () => guarded(() => api.post(`/consultations/${props.id}/addenda`, { content: addendum.value }), 'Addendum added').then(() => { addendum.value = ''; load() })
</script>

<template>
  <div v-if="c" class="space-y-4">
    <header class="card sticky top-14 z-10 flex flex-wrap items-center gap-3 p-3">
      <div class="min-w-0 flex-1">
        <div class="font-semibold">{{ c.patient.full_name }} <span class="font-normal text-slate-500">· {{ c.patient.age }}y {{ c.patient.gender }} · {{ c.patient.patient_code }}</span></div>
        <div class="mt-1 flex flex-wrap gap-1">
          <span v-for="a in c.patient.allergies" :key="a.id" class="rounded bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">⚠ {{ a.allergen }}</span>
          <span v-for="x in c.patient.conditions" :key="x.id" class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ x.name }}</span>
        </div>
      </div>
      <StatusPill :status="c.status" />
      <span v-if="savedAt && !locked" class="text-xs text-slate-500">Saved {{ savedAt }}</span>
      <button v-if="c.prescription" class="btn-secondary btn-sm" @click="api.open(`/prescriptions/${c.prescription.id}/pdf`)">Rx PDF</button>
      <button v-if="locked && c.prescription" class="btn-secondary btn-sm" @click="share">Send Rx</button>
      <button v-if="!readonly" class="btn-primary" @click="sign">Sign & finish</button>
    </header>
    <p v-if="!isMine" class="rounded bg-slate-100 px-3 py-2 text-sm text-slate-600">Read-only: this consultation belongs to {{ c.doctor.display_name }}.</p>

    <div class="grid gap-4 xl:grid-cols-[260px_1fr]">
      <aside class="card h-fit p-3">
        <h2 class="mb-2 text-sm font-semibold">Previous visits</h2>
        <details v-for="h in history" :key="h.id" class="border-b border-slate-100 py-2 text-sm">
          <summary class="cursor-pointer">{{ dateTime(h.created_at) }} · {{ h.doctor?.display_name }}</summary>
          <p class="mt-1 text-xs">{{ h.chief_complaint }}</p>
          <p class="text-xs text-slate-500">{{ h.diagnoses.map(d => d.description).join(', ') }}</p>
          <ul class="text-xs text-slate-600"><li v-for="i in h.prescription?.items ?? []" :key="i.id">• {{ i.medicine_name }} {{ i.frequency }} × {{ i.duration_value }}</li></ul>
        </details>
        <p v-if="!history.length" class="text-xs text-slate-400">First visit</p>
      </aside>

      <div class="space-y-4">
        <section v-for="sec in sections" :key="sec.key" class="card p-4">
          <h2 class="mb-3 text-sm font-semibold">{{ sec.title }}</h2>
          <template v-if="sec.widget === 'core.vitals'">
            <div v-if="latestVitals" class="mb-2 text-xs text-slate-600">Latest: BP {{ latestVitals.bp_systolic }}/{{ latestVitals.bp_diastolic }} · Pulse {{ latestVitals.pulse_bpm }} · Temp {{ latestVitals.temperature_c }} · SpO₂ {{ latestVitals.spo2 }} · BMI {{ latestVitals.bmi }}</div>
            <div v-if="!locked" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <div v-for="(_, k) in vitals" :key="k"><label class="label">{{ String(k).replace('_', ' ') }}</label><input v-model.number="vitals[k]" type="number" step="any" class="input" /></div>
              <button class="btn-secondary btn-sm col-span-2 sm:col-span-4" @click="saveVitals">Record vitals</button>
            </div>
          </template>
          <template v-else-if="sec.widget === 'core.diagnosis'">
            <ul class="mb-2 space-y-1 text-sm"><li v-for="(d, i) in diagnoses" :key="i" class="flex items-center gap-2"><span class="tabular text-xs text-slate-500">{{ d.icd10_code }}</span>{{ d.description }}<button v-if="!readonly" class="btn-secondary btn-sm" @click="diagnoses.splice(i, 1); saveDx()">✕</button></li></ul>
            <div v-if="!readonly" class="relative">
              <input v-model="icdQ" class="input" placeholder="Search ICD-10 or type a diagnosis and press Enter" aria-label="Diagnosis" @keydown.enter.prevent="icdQ && addDx(null, icdQ)" />
              <ul v-if="icdResults.length" class="absolute z-20 mt-1 w-full rounded-md border border-slate-200 bg-white shadow-lg"><li v-for="r in icdResults" :key="r.code"><button class="w-full px-3 py-1.5 text-left text-sm hover:bg-primary-50" @click="addDx(r.code, r.description)"><span class="tabular text-xs text-slate-500">{{ r.code }}</span> {{ r.description }}</button></li></ul>
            </div>
          </template>
          <template v-else-if="sec.widget === 'core.plan'">
            <div class="grid gap-3">
              <div><label class="label">Chief complaint</label><textarea v-model="core.chief_complaint" class="input" rows="2" :disabled="readonly" /></div>
              <div><label class="label">Treatment plan</label><textarea v-model="core.treatment_plan" class="input" rows="2" :disabled="readonly" /></div>
              <div><label class="label">Private notes (encrypted)</label><textarea v-model="core.notes" class="input" rows="2" :disabled="readonly" /></div>
              <div class="flex flex-wrap items-end gap-3">
                <div><label class="label">Follow-up date</label><input v-model="core.follow_up_date" type="date" class="input" :disabled="readonly" /></div>
                <template v-if="labTests.length && !readonly">
                  <div class="min-w-48 flex-1"><label class="label">Order lab tests</label><select v-model="selectedTests" multiple class="input h-20"><option v-for="lt in labTests" :key="lt.id" :value="lt.id">{{ lt.name }}</option></select></div>
                  <button class="btn-secondary btn-sm" :disabled="!selectedTests.length" @click="orderLabs">Order</button>
                </template>
              </div>
            </div>
          </template>
          <template v-else-if="sec.widget === 'core.prescription'">
            <div v-if="warnings.length" class="mb-2 rounded border border-red-300 bg-red-50 p-2 text-sm text-red-800" role="alert">
              <strong>Allergy check (advisory):</strong> <span v-for="w in warnings" :key="w.medicine + w.allergen" class="block">{{ w.medicine }} — recorded {{ w.severity }} allergy to {{ w.allergen }}</span>
            </div>
            <button v-if="!readonly && history.some(h => h.prescription)" class="btn-secondary btn-sm mb-2" @click="copyLastRx">Copy last prescription</button>
            <RxEditor :items="c.prescription?.items ?? []" :advice="c.prescription?.advice ?? ''" :readonly="readonly" @save="saveRx" />
          </template>
          <template v-else-if="sec.widget === 'dental.chart' && teeth">
            <p class="mb-2 text-xs text-slate-500">FDI notation. Click a tooth to record a finding.</p>
            <div class="grid grid-cols-8 gap-1 sm:grid-cols-16">
              <button v-for="n in teeth.teeth.filter(x => x < 50)" :key="n" class="tabular rounded border py-1 text-xs" :class="teeth.current[n] ? (teeth.current[n].condition === 'healthy' ? 'border-green-400 bg-green-50' : 'border-amber-400 bg-amber-50') : 'border-slate-200'" :title="teeth.current[n]?.condition" :disabled="readonly" @click="toothClick(n)">{{ n }}</button>
            </div>
          </template>
          <template v-else-if="sec.widget === 'ophthalmology.eye_exam'">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 text-sm">
              <div><label class="label">VA RE</label><input v-model="eye.va_re_unaided as string" class="input" placeholder="6/6" :disabled="readonly" /></div>
              <div><label class="label">VA LE</label><input v-model="eye.va_le_unaided as string" class="input" placeholder="6/9" :disabled="readonly" /></div>
              <div><label class="label">IOP RE</label><input v-model.number="eye.iop_re" type="number" class="input" :disabled="readonly" /></div>
              <div><label class="label">IOP LE</label><input v-model.number="eye.iop_le" type="number" class="input" :disabled="readonly" /></div>
              <template v-for="side in (['re', 'le'] as const)" :key="side">
                <div v-for="k in ['sph', 'cyl', 'axis']" :key="side + k"><label class="label">{{ side.toUpperCase() }} {{ k }}</label><input v-model.number="(eye.refraction as Record<string, Record<string, number | null>>)[side][k]" type="number" step="0.25" class="input" :disabled="readonly" /></div>
              </template>
            </div>
            <button v-if="!readonly" class="btn-secondary btn-sm mt-2" @click="saveEye">Save eye exam</button>
          </template>
          <template v-else-if="sec.widget">
            <p class="text-sm text-slate-500">Manage this from the patient's record (treatment plans, sessions and clinical photos).</p>
          </template>
          <TemplateSection v-else :section="sec" :model-value="sectionData(sec.key)" :readonly="readonly" @save="d => saveSection(sec.key, d)" />
        </section>

        <section v-if="locked" class="card p-4">
          <h2 class="mb-2 text-sm font-semibold">Addenda</h2>
          <ul class="mb-2 space-y-1 text-sm"><li v-for="a in c.addenda" :key="a.id"><span class="text-xs text-slate-500">{{ dateTime(a.created_at) }}</span> {{ a.content }}</li></ul>
          <div v-if="isMine" class="flex gap-2"><input v-model="addendum" class="input" placeholder="Add an addendum" /><button class="btn-secondary" :disabled="!addendum" @click="addAddendum">Add</button></div>
        </section>
      </div>
    </div>
  </div>
</template>
