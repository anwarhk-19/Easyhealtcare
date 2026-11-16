<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, errorMessage } from '@/api/client'
import type { Doctor, Patient, Slot } from '@/api/types'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { today } from '@/utils/format'
import Drawer from './Drawer.vue'
import PatientSearch from './PatientSearch.vue'
import QuickPatientForm from './QuickPatientForm.vue'

const props = defineProps<{ open: boolean; walkIn?: boolean; patient?: Patient | null }>()
const emit = defineEmits<{ close: []; done: [] }>()
const s = useSession()
const toast = useToast()
const doctors = ref<Doctor[]>([])
const slots = ref<Slot[]>([])
const creating = ref(false)
const form = reactive({ patient: null as Patient | null, doctorId: '', date: today(1), start: '', priority: 'normal', notes: '' })

onMounted(async () => { doctors.value = await api.get<Doctor[]>('/doctors', { branch_id: s.branchId }) })
watch(() => props.patient, (p) => { if (p) form.patient = p }, { immediate: true })
watch(() => [form.doctorId, form.date], async () => {
  form.start = ''
  slots.value = form.doctorId && !props.walkIn ? await api.get<Slot[]>(`/doctors/${form.doctorId}/availability`, { date: form.date, branch_id: s.branchId }) : []
})
const canSubmit = computed(() => form.patient && form.doctorId && (props.walkIn || form.start))

async function submit() {
  try {
    const base = { patient_id: form.patient!.id, doctor_profile_id: form.doctorId, branch_id: s.branchId, notes: form.notes || undefined }
    if (props.walkIn) await api.post('/appointments/walk-in', { ...base, priority: form.priority })
    else await api.post('/appointments', { ...base, scheduled_start: form.start })
    toast.show(props.walkIn ? 'Walk-in added to queue' : 'Appointment booked')
    form.start = ''; emit('done'); emit('close')
  } catch (e) { toast.error(errorMessage(e)) }
}
</script>

<template>
  <Drawer :open="open" :title="walkIn ? 'Walk-in' : 'Book appointment'" @close="emit('close')">
    <div class="space-y-4">
      <div>
        <label class="label">Patient</label>
        <div v-if="form.patient" class="flex items-center justify-between rounded-md border border-slate-200 p-2 text-sm">
          <span><strong>{{ form.patient.full_name }}</strong> · {{ form.patient.patient_code }} · {{ form.patient.mobile }}</span>
          <button class="btn-secondary btn-sm" @click="form.patient = null">Change</button>
        </div>
        <template v-else>
          <PatientSearch @select="p => (form.patient = p)" />
          <button class="mt-2 text-xs text-primary-700 underline" @click="creating = !creating">{{ creating ? 'Cancel' : '+ New patient' }}</button>
          <QuickPatientForm v-if="creating" class="mt-2" @created="p => { form.patient = p; creating = false }" />
        </template>
      </div>
      <div><label class="label">Doctor</label>
        <select v-model="form.doctorId" class="input"><option value="" disabled>Select doctor</option><option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.display_name }} — {{ d.specialities?.map(x => x.name).join(', ') }}</option></select></div>
      <template v-if="walkIn">
        <div><label class="label">Priority</label><select v-model="form.priority" class="input"><option value="normal">Normal</option><option value="priority">Priority (elderly / child)</option><option value="emergency">Emergency</option></select></div>
      </template>
      <template v-else>
        <div><label class="label">Date</label><input v-model="form.date" type="date" class="input" :min="today()" /></div>
        <div v-if="form.doctorId">
          <label class="label">Time</label>
          <p v-if="!slots.length" class="text-sm text-slate-500">No sessions on this date.</p>
          <div class="grid grid-cols-4 gap-1.5 sm:grid-cols-6">
            <button v-for="sl in slots" :key="sl.start" type="button" :disabled="!sl.available" :title="sl.reason ?? ''"
              class="tabular rounded-md border px-1 py-1.5 text-xs" :class="form.start === sl.start ? 'border-primary-600 bg-primary-600 text-white' : sl.available ? 'border-slate-300 hover:border-primary-600' : 'border-slate-100 bg-slate-50 text-slate-300 line-through'"
              @click="form.start = sl.start">{{ sl.local_time }}</button>
          </div>
        </div>
      </template>
      <div><label class="label">Notes</label><input v-model="form.notes" class="input" maxlength="500" /></div>
      <button class="btn-primary w-full" :disabled="!canSubmit" @click="submit">{{ walkIn ? 'Add to queue' : 'Book & notify patient' }}</button>
    </div>
  </Drawer>
</template>
