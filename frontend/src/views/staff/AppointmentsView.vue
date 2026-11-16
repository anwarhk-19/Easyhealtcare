<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api, errorMessage } from '@/api/client'
import type { Appointment } from '@/api/types'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { time, today } from '@/utils/format'
import BookingDrawer from '@/components/BookingDrawer.vue'
import StatusPill from '@/components/StatusPill.vue'

const s = useSession()
const toast = useToast()
const date = ref(today())
const status = ref('')
const rows = ref<Appointment[]>([])
const booking = ref(false)

async function load() { rows.value = (await api.list<Appointment>('/appointments', { date: date.value, branch_id: s.branchId, status: status.value, per_page: 100 })).data }
onMounted(load)
watch([date, status], load)

async function run(a: Appointment, action: 'confirm' | 'cancel' | 'no-show' | 'check-in') {
  let body = {}
  if (action === 'cancel') { const reason = prompt('Reason for cancellation?'); if (!reason) return; body = { reason } }
  try { await api.post(`/appointments/${a.id}/${action}`, body); await load() } catch (e) { toast.error(errorMessage(e)) }
}
async function reschedule(a: Appointment) {
  const when = prompt('New start (YYYY-MM-DD HH:MM, local time)')
  if (!when) return
  try { await api.post(`/appointments/${a.id}/reschedule`, { scheduled_start: new Date(when.replace(' ', 'T')).toISOString() }); toast.show('Rescheduled'); await load() } catch (e) { toast.error(errorMessage(e)) }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <h1 class="text-xl font-semibold">Appointments</h1>
      <div class="flex items-end gap-2">
        <div><label class="label">Date</label><input v-model="date" type="date" class="input" /></div>
        <div><label class="label">Status</label><select v-model="status" class="input"><option value="">All</option><option v-for="st in ['booked', 'confirmed', 'waiting', 'in_consultation', 'completed', 'cancelled', 'no_show']" :key="st" :value="st">{{ st.replace('_', ' ') }}</option></select></div>
        <button v-if="s.can('appointments.create')" class="btn-primary" @click="booking = true">Book</button>
      </div>
    </div>
    <div class="card overflow-x-auto">
      <table class="table">
        <thead><tr><th>Time</th><th>Patient</th><th>Doctor</th><th>Type</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
          <tr v-for="a in rows" :key="a.id">
            <td class="tabular">{{ time(a.scheduled_start) }}</td>
            <td><RouterLink :to="`/patients/${a.patient_id}`" class="font-medium text-primary-700 hover:underline">{{ a.patient?.full_name }}</RouterLink><div class="text-xs text-slate-500">{{ a.patient?.patient_code }}</div></td>
            <td>{{ a.doctor?.display_name }}</td>
            <td class="text-xs">{{ a.type.replace('_', ' ') }}<span v-if="a.token" class="ml-1 font-semibold">{{ a.token.token_label }}</span></td>
            <td><StatusPill :status="a.status" /></td>
            <td class="space-x-1 text-right whitespace-nowrap">
              <template v-if="['booked', 'confirmed'].includes(a.status)">
                <button v-if="a.status === 'booked' && s.can('appointments.update')" class="btn-secondary btn-sm" @click="run(a, 'confirm')">Confirm</button>
                <button v-if="s.can('queue.manage')" class="btn-primary btn-sm" @click="run(a, 'check-in')">Check in</button>
                <button v-if="s.can('appointments.update')" class="btn-secondary btn-sm" @click="reschedule(a)">Reschedule</button>
                <button v-if="s.can('appointments.cancel')" class="btn-secondary btn-sm" @click="run(a, 'cancel')">Cancel</button>
              </template>
            </td>
          </tr>
          <tr v-if="!rows.length"><td colspan="6" class="py-8 text-center text-slate-400">No appointments</td></tr>
        </tbody>
      </table>
    </div>
    <BookingDrawer v-if="booking" :open="true" @close="booking = false" @done="load" />
  </div>
</template>
