<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, auth, errorMessage } from '@/api/client'
import { useSession } from '@/stores/session'
import { homeFor } from '@/router'

const props = defineProps<{ token: string }>()
const router = useRouter()
const s = useSession()
const password = ref('')
const error = ref('')

async function accept() {
  try {
    const r = await api.post<{ token: string }>(`/invitations/${props.token}/accept`, { password: password.value })
    auth.token = r.token
    await s.load()
    router.push(homeFor(s))
  } catch (e) { error.value = errorMessage(e) }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center p-4">
    <form class="card w-full max-w-sm space-y-4 p-6" @submit.prevent="accept">
      <h1 class="text-lg font-semibold">Join your clinic</h1>
      <div><label class="label">Choose a password</label><input v-model="password" type="password" class="input" autocomplete="new-password" required minlength="10" /></div>
      <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
      <button class="btn-primary w-full">Accept invitation</button>
    </form>
  </main>
</template>
