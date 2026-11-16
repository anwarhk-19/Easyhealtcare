<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSession } from '@/stores/session'
import { errorMessage } from '@/api/client'
import { homeFor } from '@/router'

const s = useSession()
const router = useRouter()
const route = useRoute()
const email = ref('')
const password = ref('')
const code = ref('')
const challenge = ref<string | null>(null)
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''; busy.value = true
  try {
    if (challenge.value) await s.verify2fa(challenge.value, code.value)
    else {
      const r = await s.login(email.value, password.value)
      if (r.challenge) { challenge.value = r.challenge; return }
    }
    router.push((route.query.next as string) || homeFor(s))
  } catch (e) { error.value = errorMessage(e) } finally { busy.value = false }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center p-4">
    <form class="card w-full max-w-sm space-y-4 p-6" @submit.prevent="submit">
      <div class="flex items-center gap-2"><span class="flex h-8 w-8 items-center justify-center rounded-md bg-primary-600 font-bold text-white">E</span><h1 class="text-lg font-semibold">Sign in to EasyHealthcare</h1></div>
      <template v-if="!challenge">
        <div><label class="label" for="email">Email or mobile</label><input id="email" v-model="email" class="input" autocomplete="username" required /></div>
        <div><label class="label" for="password">Password</label><input id="password" v-model="password" type="password" class="input" autocomplete="current-password" required /></div>
      </template>
      <div v-else><label class="label" for="code">6-digit authenticator code</label><input id="code" v-model="code" class="input tabular tracking-widest" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required /></div>
      <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy">{{ challenge ? 'Verify' : 'Sign in' }}</button>
      <p class="text-center text-xs text-slate-500"><RouterLink to="/reset-password" class="underline">Forgot password?</RouterLink> · New clinic? <RouterLink to="/register" class="text-primary-700 underline">Start a free trial</RouterLink> · <RouterLink to="/patient" class="underline">Patient app</RouterLink></p>
    </form>
  </main>
</template>
