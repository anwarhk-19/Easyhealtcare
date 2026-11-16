<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useSession } from '@/stores/session'
import PatientSearch from './PatientSearch.vue'
import type { Patient } from '@/api/types'

const s = useSession()
const router = useRouter()
const mobileNav = ref(false)

const nav = computed(() => [
  { to: '/', label: 'Dashboard', icon: '▦', show: s.can('reports.operational') || s.can('reports.financial') },
  { to: '/front-desk', label: 'Front desk', icon: '☰', show: s.can('queue.view') },
  { to: '/my-queue', label: 'My queue', icon: '⚕', show: s.isDoctor },
  { to: '/appointments', label: 'Appointments', icon: '◷', show: s.can('appointments.view') },
  { to: '/patients', label: 'Patients', icon: '☺', show: s.can('patients.view') },
  { to: '/billing', label: 'Billing', icon: '₹', show: s.can('invoices.view') },
  { to: '/lab', label: 'Lab', icon: '⚗', show: s.can('lab.view') && s.hasFeature('lab') },
  { to: '/inventory', label: 'Inventory', icon: '▤', show: s.can('inventory.view') && s.hasFeature('inventory') },
  { to: '/reports', label: 'Reports', icon: '↗', show: s.can('reports.operational') || s.can('reports.financial') },
  { to: '/settings', label: 'Settings', icon: '⚙', show: s.can('organization.view') || s.can('staff.view') || s.can('doctors.view') },
].filter(i => i.show))

function openPatient(p: Patient) { router.push(`/patients/${p.id}`) }
async function logout() { await s.logout(); router.push('/login') }
</script>

<template>
  <div v-if="s.me" class="flex min-h-screen">
    <aside class="fixed inset-y-0 left-0 z-30 w-56 shrink-0 border-r border-slate-200 bg-white transition-transform lg:static lg:translate-x-0" :class="mobileNav ? 'translate-x-0' : '-translate-x-full'">
      <div class="flex h-14 items-center gap-2 border-b border-slate-200 px-4">
        <span class="flex h-7 w-7 items-center justify-center rounded-md bg-primary-600 font-bold text-white">E</span>
        <span class="truncate text-sm font-semibold">{{ s.me.organization.name }}</span>
      </div>
      <nav class="space-y-0.5 p-2" aria-label="Main">
        <RouterLink v-for="i in nav" :key="i.to" :to="i.to" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100" active-class="!bg-primary-50 !text-primary-700 font-medium" :exact-active-class="i.to === '/' ? '!bg-primary-50 !text-primary-700 font-medium' : ''" @click="mobileNav = false">
          <span class="w-4 text-center" aria-hidden="true">{{ i.icon }}</span>{{ i.label }}
        </RouterLink>
      </nav>
      <div v-if="s.me.subscription.status === 'trialing'" class="m-3 rounded-md bg-blue-50 p-3 text-xs text-blue-800">
        Trial · {{ s.me.subscription.plan?.name }} plan<br />ends {{ s.me.subscription.trial_ends_at?.slice(0, 10) }}
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4">
        <button class="btn-secondary btn-sm lg:hidden" aria-label="Menu" @click="mobileNav = !mobileNav">☰</button>
        <div class="w-full max-w-md"><PatientSearch @select="openPatient" /></div>
        <div class="ml-auto flex items-center gap-2">
          <select v-if="s.me.branches.length > 1" class="input !w-auto !py-1.5" :value="s.branchId" aria-label="Branch" @change="s.setBranch(($event.target as HTMLSelectElement).value)">
            <option v-for="b in s.me.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
          <select v-if="s.me.memberships.length > 1" class="input !w-auto !py-1.5" :value="s.me.organization.id" aria-label="Organisation" @change="s.switchOrganization(($event.target as HTMLSelectElement).value)">
            <option v-for="m in s.me.memberships" :key="m.organization_id" :value="m.organization_id">{{ m.name }}</option>
          </select>
          <span class="hidden text-sm text-slate-600 sm:inline">{{ s.me.user.name }}</span>
          <button class="btn-secondary btn-sm" @click="logout">Sign out</button>
        </div>
      </header>
      <div v-if="s.me.organization.status !== 'active'" class="bg-amber-50 px-4 py-2 text-sm text-amber-900">Your clinic account is {{ s.me.organization.status }}. It is read-only until reactivated.</div>
      <main class="flex-1 p-4 lg:p-6"><RouterView :key="s.branchId" /></main>
    </div>
  </div>
  <div v-else class="p-10 text-center text-slate-500">Loading…</div>
</template>
