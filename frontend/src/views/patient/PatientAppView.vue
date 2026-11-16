<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, auth, errorMessage } from '@/api/client'
import { useToast } from '@/stores/toast'
import { dateTime, money, today } from '@/utils/format'
import StatusPill from '@/components/StatusPill.vue'

/** Patient app (PWA-ready): OTP login, clinic discovery & linking, booking, records, consent centre. */
const toast = useToast()
const step = ref<'login' | 'home'>(auth.patientToken ? 'home' : 'login')
const otp = reactive({ mobile: '', code: '', sent: false })
const tab = ref<'appointments' | 'book' | 'records' | 'privacy'>('appointments')
const appts = ref<{ id: string; clinic: string; doctor: string; branch: string; scheduled_start: string; status: string }[]>([])
const records = ref<{ prescriptions: { id: string; clinic: string; doctor: string; date: string }[]; lab_reports: { id: string; clinic: string; order_number: string; tests: string[] }[]; receipts: { id: string; clinic: string; receipt_number: string; amount: number; received_at: string }[] }>({ prescriptions: [], lab_reports: [], receipts: [] })
const me = ref<{ clinics: { organization: { name: string; slug: string } }[] } | null>(null)
const clinics = ref<{ id: string; name: string; slug: string }[]>([])
const clinic = ref<{ clinic: { slug: string; name: string }; branches: { id: string; name: string }[]; doctors: { id: string; title: string; user: { name: string }; specialities: { name: string }[] }[] } | null>(null)
const book = reactive({ branch_id: '', doctor_id: '', date: today(1), slot: '', patient_id: '' })
const slots = ref<{ start: string; local_time: string }[]>([])
const consents = ref<{ clinic: { name: string; slug: string }; consents: { purpose: string; status: string }[] }[]>([])
const P = true

async function guarded(fn: () => Promise<unknown>, msg?: string) { try { await fn(); if (msg) toast.show(msg) } catch (e) { toast.error(errorMessage(e)) } }
const sendOtp = () => guarded(async () => { await api.post('/api/patient/v1/auth/otp/request', { mobile: otp.mobile }); otp.sent = true }, 'Code sent by SMS')
const verify = () => guarded(async () => { const r = await api.post<{ token: string }>('/api/patient/v1/auth/otp/verify', { mobile: otp.mobile, code: otp.code }); auth.patientToken = r.token; step.value = 'home'; await load() })
async function load() {
  me.value = await api.get('/api/patient/v1/me', undefined, P)
  appts.value = await api.get('/api/patient/v1/appointments', undefined, P)
  records.value = await api.get('/api/patient/v1/records', undefined, P)
  consents.value = await api.get('/api/patient/v1/consents', undefined, P)
  clinics.value = await api.get('/api/patient/v1/clinics')
}
onMounted(() => { if (step.value === 'home') load().catch(() => { auth.patientToken = null; step.value = 'login' }) })

const openClinic = (slug: string) => guarded(async () => { clinic.value = await api.get(`/api/patient/v1/clinics/${slug}`); book.branch_id = clinic.value!.branches[0]?.id ?? '' })
const link = () => guarded(async () => {
  const first_name = prompt('Your first name (if you are new to this clinic)') ?? undefined
  const r = await api.post<{ id: string }[]>(`/api/patient/v1/clinics/${clinic.value!.clinic.slug}/link`, { consent: true, first_name }, P)
  book.patient_id = r[0]?.id ?? ''
  await load()
}, 'Clinic linked')
const loadSlots = () => guarded(async () => { slots.value = await api.get(`/api/patient/v1/clinics/${clinic.value!.clinic.slug}/doctors/${book.doctor_id}/availability`, { date: book.date, branch_id: book.branch_id }) })
const confirmBooking = () => guarded(async () => { await api.post('/api/patient/v1/appointments', { patient_id: book.patient_id, doctor_profile_id: book.doctor_id, branch_id: book.branch_id, scheduled_start: book.slot }, P); tab.value = 'appointments'; await load() }, 'Appointment booked')
const cancel = (id: string) => guarded(async () => { await api.post(`/api/patient/v1/appointments/${id}/cancel`, {}, P); await load() }, 'Cancelled')
const setConsent = (slug: string, purpose: string, status: string) => guarded(async () => { await api.post(`/api/patient/v1/clinics/${slug}/consents`, { purpose, status }, P); await load() }, 'Updated')
const unlink = (slug: string) => guarded(async () => { await api.del(`/api/patient/v1/clinics/${slug}/link`, P); await load() }, 'Clinic access revoked')
const logout = () => { auth.patientToken = null; step.value = 'login' }
</script>

<template>
  <main class="mx-auto max-w-lg space-y-4 p-4">
    <header class="flex items-center justify-between"><h1 class="text-lg font-semibold text-primary-700">My Health</h1><button v-if="step === 'home'" class="btn-secondary btn-sm" @click="logout">Sign out</button></header>
    <form v-if="step === 'login'" class="card space-y-3 p-4" @submit.prevent="otp.sent ? verify() : sendOtp()">
      <div><label class="label">Mobile number</label><input v-model="otp.mobile" class="input" inputmode="tel" required /></div>
      <div v-if="otp.sent"><label class="label">6-digit code</label><input v-model="otp.code" class="input tabular tracking-widest" inputmode="numeric" maxlength="6" required /></div>
      <button class="btn-primary w-full">{{ otp.sent ? 'Verify' : 'Send code' }}</button>
    </form>
    <template v-else>
      <nav class="grid grid-cols-4 gap-1 rounded-lg bg-slate-100 p-1 text-sm"><button v-for="t in (['appointments', 'book', 'records', 'privacy'] as const)" :key="t" class="rounded-md py-1.5 capitalize" :class="tab === t ? 'bg-white shadow-sm' : ''" @click="tab = t">{{ t }}</button></nav>

      <section v-if="tab === 'appointments'" class="space-y-2">
        <div v-for="a in appts" :key="a.id" class="card flex items-center justify-between p-3 text-sm"><div><strong>{{ a.doctor }}</strong><div class="text-xs text-slate-500">{{ a.clinic }} · {{ a.branch }} · {{ dateTime(a.scheduled_start) }}</div></div>
          <div class="flex items-center gap-2"><StatusPill :status="a.status" /><button v-if="['booked', 'confirmed'].includes(a.status)" class="btn-secondary btn-sm" @click="cancel(a.id)">Cancel</button></div></div>
        <p v-if="!appts.length" class="text-center text-sm text-slate-400">No appointments yet.</p>
      </section>

      <section v-if="tab === 'book'" class="space-y-3">
        <select class="input" aria-label="Clinic" @change="openClinic(($event.target as HTMLSelectElement).value)"><option value="">Choose a clinic</option><option v-for="c in clinics" :key="c.id" :value="c.slug">{{ c.name }}</option></select>
        <template v-if="clinic">
          <button v-if="!book.patient_id" class="btn-secondary w-full" @click="link">Link my records with {{ clinic.clinic.name }}</button>
          <select v-model="book.branch_id" class="input" aria-label="Branch"><option v-for="b in clinic.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
          <select v-model="book.doctor_id" class="input" aria-label="Doctor" @change="loadSlots"><option value="">Choose doctor</option><option v-for="d in clinic.doctors" :key="d.id" :value="d.id">{{ d.title }} {{ d.user.name }} — {{ d.specialities.map(x => x.name).join(', ') }}</option></select>
          <input v-model="book.date" type="date" class="input" :min="today()" aria-label="Date" @change="loadSlots" />
          <div class="grid grid-cols-4 gap-1.5"><button v-for="sl in slots" :key="sl.start" class="rounded border py-1.5 text-xs" :class="book.slot === sl.start ? 'border-primary-600 bg-primary-600 text-white' : 'border-slate-300'" @click="book.slot = sl.start">{{ sl.local_time }}</button></div>
          <button class="btn-primary w-full" :disabled="!book.slot || !book.patient_id" @click="confirmBooking">Book appointment</button>
        </template>
      </section>

      <section v-if="tab === 'records'" class="space-y-3 text-sm">
        <h2 class="font-semibold">Prescriptions</h2>
        <div v-for="r in records.prescriptions" :key="r.id" class="card flex justify-between p-3"><span>{{ r.doctor }} · {{ r.clinic }}</span><button class="btn-secondary btn-sm" @click="api.open(`/api/patient/v1/records/prescriptions/${r.id}/pdf`, undefined, true)">View</button></div>
        <h2 class="font-semibold">Lab reports</h2>
        <div v-for="r in records.lab_reports" :key="r.id" class="card p-3">{{ r.tests.join(', ') }} · {{ r.clinic }}</div>
        <h2 class="font-semibold">Receipts</h2>
        <div v-for="r in records.receipts" :key="r.id" class="card flex justify-between p-3"><span>{{ r.receipt_number }} · {{ money(r.amount) }}</span><button class="btn-secondary btn-sm" @click="api.open(`/api/patient/v1/records/receipts/${r.id}/pdf`, undefined, true)">View</button></div>
        <p class="text-xs text-slate-500">Only records your clinic has shared with you appear here.</p>
      </section>

      <section v-if="tab === 'privacy'" class="space-y-3 text-sm">
        <div v-for="c in consents" :key="c.clinic.slug" class="card space-y-2 p-3">
          <h3 class="font-semibold">{{ c.clinic.name }}</h3>
          <div v-for="p in ['whatsapp', 'sms', 'email', 'ai_processing']" :key="p" class="flex items-center justify-between">
            <span class="capitalize">{{ p.replace('_', ' ') }}</span>
            <span class="space-x-1"><button class="btn-secondary btn-sm" @click="setConsent(c.clinic.slug, p, 'granted')">Allow</button><button class="btn-secondary btn-sm" @click="setConsent(c.clinic.slug, p, 'revoked')">Stop</button></span>
          </div>
          <button class="btn-danger btn-sm" @click="unlink(c.clinic.slug)">Stop sharing my records with this clinic</button>
        </div>
      </section>
    </template>
  </main>
</template>
