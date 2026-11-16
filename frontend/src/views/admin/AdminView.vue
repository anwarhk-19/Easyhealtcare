<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, auth, errorMessage } from '@/api/client'
import { useToast } from '@/stores/toast'
import { date, money } from '@/utils/format'
import StatusPill from '@/components/StatusPill.vue'

/** Platform super-admin console (separate plane from clinic staff). */
const toast = useToast()
const authed = ref(!!sessionStorage.getItem('ehc.platform'))
const cred = ref({ email: '', password: '', code: '' })
interface Org { id: string; name: string; slug: string; status: string; created_at: string; branches_count: number; memberships_count: number; subscription: { status: string; plan: { name: string } } | null }
const orgs = ref<Org[]>([])
const metrics = ref<{ mrr: number; arr: number; signups_30d: number; trials_ending_7d: number; churn_30d_percent: number; organizations: Record<string, number> } | null>(null)
const plans = ref<{ id: string; code: string; name: string; price_monthly: number; price_annual: number; trial_days: number; features: string[] }[]>([])

function withPlatformToken<T>(fn: () => Promise<T>): Promise<T> {
  const prev = auth.token
  auth.token = sessionStorage.getItem('ehc.platform')
  return fn().finally(() => { auth.token = prev })
}
async function login() {
  try {
    const r = await api.post<{ token: string }>('/api/platform/v1/auth/login', cred.value)
    sessionStorage.setItem('ehc.platform', r.token); authed.value = true; await load()
  } catch (e) { toast.error(errorMessage(e)) }
}
async function load() {
  await withPlatformToken(async () => {
    metrics.value = await api.get('/api/platform/v1/metrics')
    orgs.value = (await api.list<Org>('/api/platform/v1/organizations', { per_page: 50 })).data
    plans.value = await api.get('/api/platform/v1/plans')
  })
}
onMounted(() => { if (authed.value) load().catch(() => { authed.value = false }) })
async function setStatus(o: Org, action: string) {
  const reason = action === 'suspend' ? prompt('Reason for suspension?') : undefined
  if (action === 'suspend' && !reason) return
  try { await withPlatformToken(() => api.post(`/api/platform/v1/organizations/${o.id}/${action}`, { reason })); await load() } catch (e) { toast.error(errorMessage(e)) }
}
async function savePlan(p: (typeof plans.value)[number]) {
  try { await withPlatformToken(() => api.patch(`/api/platform/v1/plans/${p.id}`, { price_monthly: p.price_monthly, price_annual: p.price_annual, trial_days: p.trial_days })); toast.show('Plan saved') } catch (e) { toast.error(errorMessage(e)) }
}
</script>

<template>
  <main class="mx-auto max-w-6xl space-y-6 p-6">
    <h1 class="text-xl font-semibold">Platform admin</h1>
    <form v-if="!authed" class="card max-w-sm space-y-3 p-4" @submit.prevent="login">
      <input v-model="cred.email" class="input" placeholder="Email" aria-label="Email" /><input v-model="cred.password" type="password" class="input" placeholder="Password" aria-label="Password" />
      <input v-model="cred.code" class="input" placeholder="2FA code" aria-label="2FA code" /><button class="btn-primary w-full">Sign in</button>
    </form>
    <template v-else>
      <div v-if="metrics" class="grid grid-cols-2 gap-3 md:grid-cols-5">
        <div class="card p-4"><div class="text-xs text-slate-500">MRR</div><div class="text-xl font-semibold">{{ money(metrics.mrr) }}</div></div>
        <div class="card p-4"><div class="text-xs text-slate-500">ARR</div><div class="text-xl font-semibold">{{ money(metrics.arr) }}</div></div>
        <div class="card p-4"><div class="text-xs text-slate-500">Sign-ups (30d)</div><div class="text-xl font-semibold">{{ metrics.signups_30d }}</div></div>
        <div class="card p-4"><div class="text-xs text-slate-500">Trials ending (7d)</div><div class="text-xl font-semibold">{{ metrics.trials_ending_7d }}</div></div>
        <div class="card p-4"><div class="text-xs text-slate-500">Churn (30d)</div><div class="text-xl font-semibold">{{ metrics.churn_30d_percent }}%</div></div>
      </div>
      <section class="card overflow-x-auto"><table class="table">
        <thead><tr><th>Clinic</th><th>Created</th><th>Plan</th><th>Branches</th><th>Staff</th><th>Status</th><th></th></tr></thead>
        <tbody><tr v-for="o in orgs" :key="o.id"><td>{{ o.name }}<div class="text-xs text-slate-500">{{ o.slug }}</div></td><td>{{ date(o.created_at) }}</td>
          <td>{{ o.subscription?.plan.name }} <StatusPill v-if="o.subscription" :status="o.subscription.status" /></td><td>{{ o.branches_count }}</td><td>{{ o.memberships_count }}</td><td><StatusPill :status="o.status" /></td>
          <td class="space-x-1 text-right"><button v-if="o.status === 'pending'" class="btn-primary btn-sm" @click="setStatus(o, 'approve')">Approve</button>
            <button v-if="o.status === 'active'" class="btn-danger btn-sm" @click="setStatus(o, 'suspend')">Suspend</button>
            <button v-if="o.status === 'suspended'" class="btn-secondary btn-sm" @click="setStatus(o, 'reactivate')">Reactivate</button></td></tr></tbody>
      </table></section>
      <section class="card overflow-x-auto"><h2 class="px-4 pt-4 text-sm font-semibold">Plans (prices in paise)</h2><table class="table">
        <thead><tr><th>Plan</th><th>Monthly</th><th>Annual</th><th>Trial days</th><th>Features</th><th></th></tr></thead>
        <tbody><tr v-for="p in plans" :key="p.id"><td>{{ p.name }}</td><td><input v-model.number="p.price_monthly" type="number" class="input !w-28" /></td><td><input v-model.number="p.price_annual" type="number" class="input !w-28" /></td>
          <td><input v-model.number="p.trial_days" type="number" class="input !w-20" /></td><td class="text-xs">{{ p.features?.join(', ') }}</td><td><button class="btn-secondary btn-sm" @click="savePlan(p)">Save</button></td></tr></tbody>
      </table></section>
    </template>
  </main>
</template>
