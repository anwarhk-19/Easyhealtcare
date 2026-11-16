<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, errorMessage } from '@/api/client'

const route = useRoute()
const router = useRouter()
const email = ref((route.query.email as string) ?? '')
const password = ref('')
const confirm = ref('')
const msg = ref('')
const token = route.query.token as string | undefined

async function submit() {
  try {
    if (token) {
      await api.post('/auth/reset-password', { token, email: email.value, password: password.value, password_confirmation: confirm.value })
      router.push('/login')
    } else {
      msg.value = (await api.post<{ message: string }>('/auth/forgot-password', { email: email.value })).message
    }
  } catch (e) { msg.value = errorMessage(e) }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center p-4">
    <form class="card w-full max-w-sm space-y-4 p-6" @submit.prevent="submit">
      <h1 class="text-lg font-semibold">{{ token ? 'Choose a new password' : 'Reset your password' }}</h1>
      <div><label class="label" for="email">Email</label><input id="email" v-model="email" type="email" class="input" required /></div>
      <template v-if="token">
        <div><label class="label" for="pw">New password</label><input id="pw" v-model="password" type="password" class="input" autocomplete="new-password" minlength="10" required /></div>
        <div><label class="label" for="pw2">Confirm password</label><input id="pw2" v-model="confirm" type="password" class="input" autocomplete="new-password" required /></div>
      </template>
      <p v-if="msg" class="text-sm text-slate-700" role="status">{{ msg }}</p>
      <button class="btn-primary w-full">{{ token ? 'Set password' : 'Send reset link' }}</button>
    </form>
  </main>
</template>
