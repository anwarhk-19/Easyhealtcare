<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, errorMessage } from '@/api/client'
import type { Branch, Doctor } from '@/api/types'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { dateTime, label, money } from '@/utils/format'
import StatusPill from '@/components/StatusPill.vue'

const s = useSession()
const toast = useToast()
const tabs = computed(() => [
  { key: 'clinic', show: s.can('organization.view') }, { key: 'branches', show: s.can('branches.view') }, { key: 'doctors', show: s.can('doctors.view') },
  { key: 'services', show: s.can('services.view') }, { key: 'staff', show: s.can('staff.view') }, { key: 'subscription', show: s.can('subscription.view') }, { key: 'audit', show: s.can('audit.view') },
].filter(t => t.show).map(t => t.key))
const tab = ref(tabs.value[0])
async function guarded(fn: () => Promise<unknown>, msg = 'Saved') { try { await fn(); toast.show(msg); await load() } catch (e) { toast.error(errorMessage(e)) } }

// Clinic
const org = reactive<Record<string, unknown>>({})
const settings = reactive<Record<string, unknown>>({})
// Branches / doctors / services / staff
const branches = ref<Branch[]>([])
const doctors = ref<Doctor[]>([])
const services = ref<{ id: string; code: string; name: string; type: string; price: number }[]>([])
const staff = ref<{ id: string; status: string; user: { name: string; email: string }; roles: { key: string; name: string }[]; branches: { name: string }[] }[]>([])
const specialities = ref<{ id: string; name: string }[]>([])
const sub = ref<{ subscription: { status: string; plan: { name: string } }; invoices: { number: string; total: number; status: string }[]; available_plans: { code: string; name: string; price_monthly: number; price_annual: number; description: string }[] } | null>(null)
const audit = ref<{ id: string; event: string; auditable_type: string; created_at: string; user_id: string }[]>([])
const nb = reactive({ name: '', code: '' })
const nd = reactive({ name: '', email: '', password: '', registration_number: '', speciality_id: '', fee: 500 })
const ns = reactive({ code: '', name: '', type: 'procedure', price: 0 })
const inv = reactive({ name: '', email: '', role: 'receptionist' })
const sched = reactive({ doctorId: '', days: [1, 2, 3, 4, 5, 6] as number[], start: '09:00', end: '13:00', slot: 15 })
const inviteUrl = ref('')

async function load() {
  const t = tab.value
  if (t === 'clinic') { Object.assign(org, await api.get('/organization')); Object.assign(settings, await api.get('/organization/settings')) }
  if (t === 'branches') branches.value = (await api.list<Branch>('/branches')).data
  if (t === 'doctors') { doctors.value = await api.get<Doctor[]>('/doctors'); specialities.value = await api.get('/specialities') }
  if (t === 'services') services.value = (await api.list<(typeof services.value)[number]>('/services', { per_page: 100 })).data
  if (t === 'staff') staff.value = (await api.list<(typeof staff.value)[number]>('/staff')).data
  if (t === 'subscription') sub.value = await api.get('/subscription')
  if (t === 'audit') audit.value = (await api.list<(typeof audit.value)[number]>('/audit-logs')).data
}
onMounted(load)
watch(tab, load)

const saveOrg = () => guarded(() => api.patch('/organization', { name: org.name, email: org.email, phone: org.phone, gstin: org.gstin || null, registration_no: org.registration_no }))
const saveSettings = () => guarded(() => api.patch('/organization/settings', Object.fromEntries(Object.entries(settings).filter(([k]) => s.can('ai.manage') || !k.startsWith('ai_')))))
const addBranch = () => guarded(() => api.post('/branches', nb))
const addDoctor = () => guarded(async () => {
  const d = await api.post<Doctor>('/doctors', { name: nd.name, email: nd.email, password: nd.password, registration_number: nd.registration_number, speciality_ids: [nd.speciality_id], branch_ids: [s.branchId] })
  await api.put(`/doctors/${d.id}/fees`, { fees: [{ fee_type: 'new', amount: nd.fee * 100 }, { fee_type: 'follow_up', amount: Math.round(nd.fee * 50) }] })
}, 'Doctor added')
const saveSchedule = () => guarded(() => api.put(`/doctors/${sched.doctorId}/schedules`, { schedules: sched.days.map(d => ({ branch_id: s.branchId, weekday: d, start_time: sched.start, end_time: sched.end, slot_minutes: sched.slot })) }), 'Schedule saved')
const addService = () => guarded(() => api.post('/services', { ...ns, price: ns.price * 100 }))
async function invite() {
  try { const r = await api.post<{ accept_url: string }>('/staff/invitations', { name: inv.name, email: inv.email, role_keys: [inv.role], branch_ids: [s.branchId] }); inviteUrl.value = r.accept_url; toast.show('Invitation sent'); await load() } catch (e) { toast.error(errorMessage(e)) }
}
const deactivate = (id: string) => guarded(() => api.patch(`/staff/${id}`, { status: 'disabled' }), 'Deactivated')
const changePlan = (code: string, cycle: string) => guarded(() => api.post('/subscription/change-plan', { plan_code: code, billing_cycle: cycle }), 'Plan changed')
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">Settings</h1>
    <nav class="flex flex-wrap gap-1 border-b border-slate-200">
      <button v-for="t in tabs" :key="t" class="-mb-px border-b-2 px-3 py-2 text-sm" :class="tab === t ? 'border-primary-600 font-medium text-primary-700' : 'border-transparent text-slate-500'" @click="tab = t">{{ label(t!) }}</button>
    </nav>

    <div v-if="tab === 'clinic'" class="grid gap-4 lg:grid-cols-2">
      <form class="card space-y-3 p-4" @submit.prevent="saveOrg">
        <h2 class="text-sm font-semibold">Clinic profile</h2>
        <div v-for="k in ['name', 'email', 'phone', 'gstin', 'registration_no']" :key="k"><label class="label">{{ label(k) }}</label><input v-model="org[k] as string" class="input" :disabled="!s.can('organization.update')" /></div>
        <button v-if="s.can('organization.update')" class="btn-primary">Save profile</button>
      </form>
      <form class="card space-y-3 p-4" @submit.prevent="saveSettings">
        <h2 class="text-sm font-semibold">Preferences & policies</h2>
        <div><label class="label">Patient ID prefix</label><input v-model="settings.patient_code_prefix as string" class="input" /></div>
        <div><label class="label">Discount approval above (%)</label><input v-model.number="settings.discount_approval_threshold_percent" type="number" class="input" /></div>
        <div><label class="label">Follow-up fee window (days)</label><input v-model.number="settings.follow_up_window_days" type="number" class="input" /></div>
        <div><label class="label">Clinical visibility between doctors</label><select v-model="settings.clinical_visibility" class="input"><option value="all_doctors">All doctors in the clinic</option><option value="treating_doctor_only">Treating doctor only</option></select></div>
        <label class="flex items-center gap-2 text-sm"><input v-model="settings.admins_can_view_clinical" type="checkbox" /> Owners/admins may read clinical notes</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="settings.lab_tech_can_release" type="checkbox" /> Lab technicians may release reports</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="settings.public_listing" type="checkbox" /> List clinic in the patient app</label>
        <fieldset v-if="s.can('ai.manage')" class="space-y-1 rounded border border-slate-200 p-2"><legend class="px-1 text-xs font-medium">AI assistance (optional)</legend>
          <label class="flex items-center gap-2 text-sm"><input v-model="settings.ai_enabled" type="checkbox" /> Enable admin AI features (summaries, drafts)</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="settings.ai_phi_allowed" type="checkbox" /> I accept the AI data-processing terms for features that may include patient information</label>
          <p class="text-xs text-slate-500">AI never diagnoses or prescribes. Every output is a draft that staff must review.</p>
        </fieldset>
        <button v-if="s.can('organization.update')" class="btn-primary">Save preferences</button>
      </form>
    </div>

    <div v-if="tab === 'branches'" class="space-y-3">
      <form v-if="s.can('branches.manage')" class="card flex flex-wrap items-end gap-2 p-3" @submit.prevent="addBranch">
        <div class="flex-1"><label class="label">Branch name</label><input v-model="nb.name" class="input" required /></div><div><label class="label">Code</label><input v-model="nb.code" class="input !w-28" required /></div><button class="btn-primary">Add branch</button>
      </form>
      <div class="card divide-y divide-slate-100"><div v-for="b in branches" :key="b.id" class="flex justify-between px-4 py-2 text-sm"><span>{{ b.name }}</span><span class="text-slate-500">{{ b.code }} · {{ b.timezone }}</span></div></div>
    </div>

    <div v-if="tab === 'doctors'" class="grid gap-4 lg:grid-cols-2">
      <div class="card divide-y divide-slate-100"><div v-for="d in doctors" :key="d.id" class="px-4 py-2 text-sm"><strong>{{ d.display_name }}</strong> <span class="text-slate-500">{{ d.specialities?.map(x => x.name).join(', ') }}</span></div></div>
      <div class="space-y-4">
        <form v-if="s.can('doctors.manage')" class="card grid grid-cols-2 gap-2 p-4" @submit.prevent="addDoctor">
          <h2 class="col-span-2 text-sm font-semibold">Add doctor</h2>
          <input v-model="nd.name" class="input" placeholder="Full name" required aria-label="Name" /><input v-model="nd.email" type="email" class="input" placeholder="Email" required aria-label="Email" />
          <input v-model="nd.password" type="password" class="input" placeholder="Initial password" required minlength="10" aria-label="Password" /><input v-model="nd.registration_number" class="input" placeholder="Registration no." aria-label="Registration number" />
          <select v-model="nd.speciality_id" class="input" required aria-label="Speciality"><option value="" disabled>Speciality</option><option v-for="sp in specialities" :key="sp.id" :value="sp.id">{{ sp.name }}</option></select>
          <input v-model.number="nd.fee" type="number" class="input" placeholder="Consultation fee ₹" aria-label="Fee" />
          <button class="btn-primary col-span-2">Add doctor</button>
        </form>
        <form v-if="s.can('doctors.manage') || s.can('doctors.manage_own')" class="card space-y-2 p-4" @submit.prevent="saveSchedule">
          <h2 class="text-sm font-semibold">Weekly schedule (this branch)</h2>
          <select v-model="sched.doctorId" class="input" required aria-label="Doctor"><option value="" disabled>Doctor</option><option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.display_name }}</option></select>
          <div class="flex flex-wrap gap-2 text-xs"><label v-for="(n, i) in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']" :key="n" class="flex items-center gap-1"><input v-model="sched.days" type="checkbox" :value="i" />{{ n }}</label></div>
          <div class="grid grid-cols-3 gap-2"><input v-model="sched.start" type="time" class="input" aria-label="Start" /><input v-model="sched.end" type="time" class="input" aria-label="End" /><input v-model.number="sched.slot" type="number" class="input" aria-label="Slot minutes" /></div>
          <button class="btn-secondary">Save schedule</button>
        </form>
      </div>
    </div>

    <div v-if="tab === 'services'" class="space-y-3">
      <form v-if="s.can('services.manage')" class="card flex flex-wrap items-end gap-2 p-3" @submit.prevent="addService">
        <div><label class="label">Code</label><input v-model="ns.code" class="input !w-28" required /></div><div class="flex-1"><label class="label">Name</label><input v-model="ns.name" class="input" required /></div>
        <div><label class="label">Type</label><select v-model="ns.type" class="input"><option v-for="t in ['consultation', 'procedure', 'diagnostic', 'package', 'other']" :key="t">{{ t }}</option></select></div>
        <div><label class="label">Price ₹</label><input v-model.number="ns.price" type="number" class="input !w-28" /></div><button class="btn-primary">Add</button>
      </form>
      <div class="card overflow-x-auto"><table class="table"><thead><tr><th>Code</th><th>Name</th><th>Type</th><th class="text-right">Price</th></tr></thead>
        <tbody><tr v-for="sv in services" :key="sv.id"><td>{{ sv.code }}</td><td>{{ sv.name }}</td><td>{{ sv.type }}</td><td class="tabular text-right">{{ money(sv.price) }}</td></tr></tbody></table></div>
    </div>

    <div v-if="tab === 'staff'" class="space-y-3">
      <form v-if="s.can('staff.manage')" class="card flex flex-wrap items-end gap-2 p-3" @submit.prevent="invite">
        <div><label class="label">Name</label><input v-model="inv.name" class="input" required /></div><div><label class="label">Email</label><input v-model="inv.email" type="email" class="input" required /></div>
        <div><label class="label">Role</label><select v-model="inv.role" class="input"><option v-for="r in ['receptionist', 'nurse', 'lab_technician', 'accountant', 'branch_manager', 'clinic_admin']" :key="r" :value="r">{{ label(r) }}</option></select></div>
        <button class="btn-primary">Send invitation</button>
      </form>
      <p v-if="inviteUrl" class="rounded bg-primary-50 p-2 text-xs break-all">Invitation link (also emailed): {{ inviteUrl }}</p>
      <div class="card overflow-x-auto"><table class="table"><thead><tr><th>Name</th><th>Roles</th><th>Branches</th><th>Status</th><th></th></tr></thead>
        <tbody><tr v-for="m in staff" :key="m.id"><td>{{ m.user.name }}<div class="text-xs text-slate-500">{{ m.user.email }}</div></td><td>{{ m.roles.map(r => r.name).join(', ') }}</td><td>{{ m.branches.map(b => b.name).join(', ') || 'All' }}</td><td><StatusPill :status="m.status" /></td>
          <td class="text-right"><button v-if="s.can('staff.manage') && m.status === 'active'" class="btn-secondary btn-sm" @click="deactivate(m.id)">Deactivate</button></td></tr></tbody></table></div>
    </div>

    <div v-if="tab === 'subscription' && sub" class="space-y-4">
      <div class="card p-4 text-sm">Current plan: <strong>{{ sub.subscription.plan.name }}</strong> <StatusPill :status="sub.subscription.status" /></div>
      <div class="grid gap-3 md:grid-cols-3">
        <div v-for="p in sub.available_plans" :key="p.code" class="card space-y-2 p-4"><h3 class="font-semibold">{{ p.name }}</h3><p class="text-xs text-slate-500">{{ p.description }}</p>
          <p class="text-sm">{{ money(p.price_monthly) }}/month · {{ money(p.price_annual) }}/year</p>
          <div v-if="s.can('subscription.manage')" class="flex gap-2"><button class="btn-secondary btn-sm" @click="changePlan(p.code, 'monthly')">Monthly</button><button class="btn-secondary btn-sm" @click="changePlan(p.code, 'annual')">Annual</button></div></div>
      </div>
      <div class="card overflow-x-auto"><table class="table"><thead><tr><th>Invoice</th><th>Total</th><th>Status</th></tr></thead><tbody><tr v-for="i in sub.invoices" :key="i.number"><td>{{ i.number }}</td><td>{{ money(i.total) }}</td><td><StatusPill :status="i.status" /></td></tr></tbody></table></div>
    </div>

    <div v-if="tab === 'audit'" class="card overflow-x-auto"><table class="table"><thead><tr><th>When</th><th>Event</th><th>Record</th><th>User</th></tr></thead>
      <tbody><tr v-for="a in audit" :key="a.id"><td class="text-xs">{{ dateTime(a.created_at) }}</td><td>{{ a.event }}</td><td>{{ a.auditable_type }}</td><td class="text-xs text-slate-500">{{ a.user_id?.slice(-6) }}</td></tr></tbody></table></div>
  </div>
</template>
