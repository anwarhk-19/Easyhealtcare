<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, auth, errorMessage } from '@/api/client'
import { useSession } from '@/stores/session'

const router = useRouter()
const s = useSession()
// Static list at sign-up (no unauthenticated listing endpoint needed).
const specialities = ref<{ code: string; name: string }[]>([
  { code: 'general', name: 'General Physician' }, { code: 'dental', name: 'Dental' }, { code: 'dermatology', name: 'Dermatology' },
  { code: 'paediatrics', name: 'Paediatrics' }, { code: 'ophthalmology', name: 'Ophthalmology' }, { code: 'physiotherapy', name: 'Physiotherapy' },
  { code: 'gynaecology', name: 'Obstetrics & Gynaecology' }, { code: 'orthopaedics', name: 'Orthopaedics' }, { code: 'cardiology', name: 'Cardiology' }, { code: 'ent', name: 'ENT' },
])
const form = reactive({ clinic_name: '', owner_name: '', email: '', mobile: '', password: '', city: '', plan_code: 'starter', speciality_codes: ['general'] as string[], owner_is_doctor: true, registration_number: '' })
const error = ref('')
const busy = ref(false)

async function submit() {
  busy.value = true; error.value = ''
  try {
    const r = await api.post<{ token: string }>('/auth/register-clinic', form)
    auth.token = r.token
    await s.load()
    router.push('/settings')
  } catch (e) { error.value = errorMessage(e) } finally { busy.value = false }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center p-4">
    <form class="card w-full max-w-lg space-y-4 p-6" @submit.prevent="submit">
      <h1 class="text-lg font-semibold">Start your 14-day free trial</h1>
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2"><label class="label">Clinic name</label><input v-model="form.clinic_name" class="input" required /></div>
        <div><label class="label">Your name</label><input v-model="form.owner_name" class="input" required /></div>
        <div><label class="label">City</label><input v-model="form.city" class="input" /></div>
        <div><label class="label">Email</label><input v-model="form.email" type="email" class="input" required /></div>
        <div><label class="label">Mobile</label><input v-model="form.mobile" class="input" inputmode="tel" /></div>
        <div class="sm:col-span-2"><label class="label">Password (min 10 characters, letters and numbers)</label><input v-model="form.password" type="password" class="input" autocomplete="new-password" required /></div>
        <div class="sm:col-span-2"><label class="label">Plan</label>
          <select v-model="form.plan_code" class="input"><option value="starter">Starter — single doctor</option><option value="professional">Professional — multiple doctors</option><option value="business">Business — multiple branches</option></select></div>
        <fieldset class="sm:col-span-2"><legend class="label">Specialities</legend>
          <div class="flex flex-wrap gap-2"><label v-for="sp in specialities" :key="sp.code" class="flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-xs"><input v-model="form.speciality_codes" type="checkbox" :value="sp.code" />{{ sp.name }}</label></div>
        </fieldset>
        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="form.owner_is_doctor" type="checkbox" /> I am also a consulting doctor</label>
        <div v-if="form.owner_is_doctor" class="sm:col-span-2"><label class="label">Medical registration number</label><input v-model="form.registration_number" class="input" /></div>
      </div>
      <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy">Create clinic</button>
      <p class="text-center text-xs text-slate-500">Already registered? <RouterLink to="/login" class="underline">Sign in</RouterLink></p>
    </form>
  </main>
</template>
