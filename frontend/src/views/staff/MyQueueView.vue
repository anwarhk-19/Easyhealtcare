<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, errorMessage } from '@/api/client'
import type { Token } from '@/api/types'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import StatusPill from '@/components/StatusPill.vue'

const s = useSession()
const toast = useToast()
const router = useRouter()
const data = ref<{ tokens: Token[]; estimates: { average_consultation_minutes: number } } | null>(null)
let timer: ReturnType<typeof setInterval> | undefined

async function load() { data.value = await api.get('/queue/my', { branch_id: s.branchId }) }
onMounted(() => { load(); timer = setInterval(load, 15000) })
onUnmounted(() => clearInterval(timer))

async function callNext() {
  try { const t = await api.post<Token | null>('/queue/call-next', { branch_id: s.branchId }); toast.show(t ? `Calling ${t.token_label}` : 'Queue is empty'); await load() } catch (e) { toast.error(errorMessage(e)) }
}
/** Start: moves token to consultation and opens (or creates) the consultation workspace. */
async function start(t: Token) {
  try {
    if (t.status !== 'in_consultation') await api.post(`/queue/${t.id}/start`)
    const c = await api.post<{ id: string }>('/consultations', { appointment_id: t.appointment_id })
    router.push(`/consultations/${c.id}`)
  } catch (e) { toast.error(errorMessage(e)) }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-semibold">My queue <span v-if="data" class="text-sm font-normal text-slate-500">· avg {{ data.estimates.average_consultation_minutes }} min</span></h1>
      <button class="btn-primary" @click="callNext">📣 Call next</button>
    </div>
    <div class="card divide-y divide-slate-100">
      <div v-for="t in data?.tokens ?? []" :key="t.id" class="flex items-center gap-3 px-4 py-3" :class="t.status === 'completed' ? 'opacity-50' : ''">
        <span class="tabular w-16 text-lg font-semibold" :class="t.priority === 'emergency' ? 'text-red-700' : ''">{{ t.token_label }}</span>
        <div class="flex-1"><div class="font-medium">{{ t.appointment?.patient?.full_name }}</div><div class="text-xs text-slate-500">{{ t.appointment?.patient?.patient_code }} · {{ t.appointment?.patient?.age }}y {{ t.appointment?.patient?.gender }} · {{ t.priority }}</div></div>
        <StatusPill :status="t.status" />
        <button v-if="['waiting', 'called', 'in_consultation'].includes(t.status)" class="btn-primary btn-sm" @click="start(t)">{{ t.status === 'in_consultation' ? 'Open' : 'Start' }}</button>
      </div>
      <p v-if="!data?.tokens.length" class="p-8 text-center text-slate-400">No patients in your queue today.</p>
    </div>
  </div>
</template>
