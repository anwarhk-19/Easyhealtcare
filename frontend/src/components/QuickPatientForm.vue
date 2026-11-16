<script setup lang="ts">
import { reactive, ref } from 'vue'
import { api, ApiException, errorMessage } from '@/api/client'
import type { Patient } from '@/api/types'
import { useSession } from '@/stores/session'

const emit = defineEmits<{ created: [p: Patient] }>()
const s = useSession()
const f = reactive({ first_name: '', last_name: '', gender: 'female', age_years: '' as string | number, date_of_birth: '', mobile: '', consents: ['data_processing', 'whatsapp', 'sms'] as string[] })
const error = ref('')
const duplicates = ref<Patient[]>([])

async function save(allowDuplicate = false) {
  error.value = ''
  try {
    const p = await api.post<Patient>('/patients', { ...f, age_years: f.date_of_birth ? undefined : Number(f.age_years) || undefined, date_of_birth: f.date_of_birth || undefined, branch_id: s.branchId, allow_duplicate: allowDuplicate })
    emit('created', p)
  } catch (e) {
    if (e instanceof ApiException && e.error.code === 'POSSIBLE_DUPLICATE') duplicates.value = (e.error.context as { matches: Patient[] }).matches
    else error.value = errorMessage(e)
  }
}
</script>

<template>
  <form class="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3" @submit.prevent="save()">
    <div class="grid grid-cols-2 gap-2">
      <input v-model="f.first_name" class="input" placeholder="First name" required aria-label="First name" />
      <input v-model="f.last_name" class="input" placeholder="Last name" aria-label="Last name" />
      <input v-model="f.mobile" class="input" placeholder="Mobile" inputmode="tel" aria-label="Mobile" />
      <select v-model="f.gender" class="input" aria-label="Gender"><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option></select>
      <input v-model="f.age_years" class="input" placeholder="Age (years)" inputmode="numeric" aria-label="Age" />
      <input v-model="f.date_of_birth" type="date" class="input" aria-label="Date of birth" />
    </div>
    <fieldset class="flex flex-wrap gap-3 text-xs"><legend class="label">Patient consents</legend>
      <label v-for="c in ['data_processing', 'whatsapp', 'sms', 'email']" :key="c" class="flex items-center gap-1"><input v-model="f.consents" type="checkbox" :value="c" />{{ c.replace('_', ' ') }}</label>
    </fieldset>
    <div v-if="duplicates.length" class="rounded border border-amber-300 bg-amber-50 p-2 text-xs">
      Possible duplicate:
      <button v-for="d in duplicates" :key="d.id" type="button" class="ml-1 underline" @click="emit('created', d)">{{ d.full_name }} ({{ d.patient_code }})</button>
      <button type="button" class="ml-2 font-medium underline" @click="save(true)">Create anyway</button>
    </div>
    <p v-if="error" class="text-xs text-red-700">{{ error }}</p>
    <button class="btn-primary btn-sm">Register patient</button>
  </form>
</template>
