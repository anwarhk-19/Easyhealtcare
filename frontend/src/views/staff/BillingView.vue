<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, errorMessage } from '@/api/client'
import type { Invoice, Patient } from '@/api/types'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { date, money, today } from '@/utils/format'
import StatusPill from '@/components/StatusPill.vue'
import PatientSearch from '@/components/PatientSearch.vue'
import Drawer from '@/components/Drawer.vue'

const s = useSession()
const toast = useToast()
const router = useRouter()
const tab = ref<'invoices' | 'approvals' | 'refunds' | 'collection'>('invoices')
const status = ref('')
const invoices = ref<Invoice[]>([])
const refunds = ref<{ id: string; amount: number; reason: string; status: string; payment: { receipt_number: string } }[]>([])
const collection = ref<{ by_method: { method: string; count: number; total: number }[]; gross: number; refunds: number; net: number; cash_expected: number } | null>(null)
const newOpen = ref(false)
const counted = ref<number | null>(null)

async function load() {
  if (tab.value === 'invoices') invoices.value = (await api.list<Invoice>('/invoices', { status: status.value, branch_id: s.branchId })).data
  if (tab.value === 'approvals') invoices.value = (await api.list<Invoice>('/invoices', { discount_status: 'pending' })).data
  if (tab.value === 'refunds') refunds.value = (await api.list<(typeof refunds.value)[number]>('/refunds')).data
  if (tab.value === 'collection') collection.value = await api.get('/collections/daily', { branch_id: s.branchId, date: today() })
}
onMounted(load)
watch([tab, status], load)

async function newInvoice(p: Patient) {
  try { const inv = await api.post<Invoice>('/invoices', { patient_id: p.id, branch_id: s.branchId, items: [] }); router.push(`/billing/${inv.id}`) } catch (e) { toast.error(errorMessage(e)) }
}
async function refundAct(id: string, action: 'approve' | 'reject' | 'process') {
  try { await api.post(action === 'process' ? `/refunds/${id}/process` : `/refunds/${id}/decide`, action === 'process' ? {} : { approve: action === 'approve' }); await load() } catch (e) { toast.error(errorMessage(e)) }
}
async function closeCash() {
  try { const r = await api.post<{ difference: number }>('/cash-closings', { branch_id: s.branchId, counted_cash: Math.round((counted.value ?? 0) * 100) }); toast.show(`Cash closed. Difference ${money(r.difference)}`) } catch (e) { toast.error(errorMessage(e)) }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-semibold">Billing</h1>
      <button v-if="s.can('invoices.create')" class="btn-primary" @click="newOpen = true">+ New invoice</button>
    </div>
    <nav class="flex gap-1 border-b border-slate-200">
      <button v-for="t in (['invoices', 'approvals', 'refunds', 'collection'] as const)" :key="t" class="-mb-px border-b-2 px-3 py-2 text-sm capitalize" :class="tab === t ? 'border-primary-600 font-medium text-primary-700' : 'border-transparent text-slate-500'" @click="tab = t">{{ t === 'collection' ? "Today's collection" : t }}</button>
    </nav>

    <template v-if="tab === 'invoices' || tab === 'approvals'">
      <select v-if="tab === 'invoices'" v-model="status" class="input !w-48" aria-label="Status"><option value="">All statuses</option><option v-for="st in ['draft', 'issued', 'partially_paid', 'paid', 'void']" :key="st" :value="st">{{ st.replace('_', ' ') }}</option></select>
      <div class="card overflow-x-auto"><table class="table">
        <thead><tr><th>Invoice</th><th>Date</th><th>Patient</th><th class="text-right">Total</th><th class="text-right">Balance</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="i in invoices" :key="i.id" class="cursor-pointer hover:bg-slate-50" @click="router.push(`/billing/${i.id}`)">
            <td class="tabular">{{ i.invoice_number ?? 'Draft' }}</td><td>{{ date(i.invoice_date) }}</td><td>{{ i.patient?.full_name }}</td>
            <td class="tabular text-right">{{ money(i.grand_total) }}</td><td class="tabular text-right">{{ money(i.balance_due) }}</td>
            <td><StatusPill :status="i.status" /> <StatusPill v-if="i.discount_status === 'pending'" status="pending" /></td>
          </tr>
          <tr v-if="!invoices.length"><td colspan="6" class="py-8 text-center text-slate-400">Nothing here</td></tr>
        </tbody>
      </table></div>
    </template>

    <div v-if="tab === 'refunds'" class="card overflow-x-auto"><table class="table">
      <thead><tr><th>Receipt</th><th>Amount</th><th>Reason</th><th>Status</th><th></th></tr></thead>
      <tbody><tr v-for="r in refunds" :key="r.id"><td>{{ r.payment?.receipt_number }}</td><td class="tabular">{{ money(r.amount) }}</td><td>{{ r.reason }}</td><td><StatusPill :status="r.status" /></td>
        <td class="space-x-1 text-right"><template v-if="s.can('refunds.approve')">
          <template v-if="r.status === 'requested'"><button class="btn-secondary btn-sm" @click="refundAct(r.id, 'approve')">Approve</button><button class="btn-secondary btn-sm" @click="refundAct(r.id, 'reject')">Reject</button></template>
          <button v-if="r.status === 'approved'" class="btn-primary btn-sm" @click="refundAct(r.id, 'process')">Mark paid out</button></template></td></tr></tbody>
    </table></div>

    <div v-if="tab === 'collection' && collection" class="grid gap-4 md:grid-cols-2">
      <div class="card p-4"><table class="table"><thead><tr><th>Method</th><th>Count</th><th class="text-right">Total</th></tr></thead>
        <tbody><tr v-for="m in collection.by_method" :key="m.method"><td class="capitalize">{{ m.method.replace('_', ' ') }}</td><td>{{ m.count }}</td><td class="tabular text-right">{{ money(m.total) }}</td></tr>
        <tr><td colspan="2">Refunds</td><td class="tabular text-right">− {{ money(collection.refunds) }}</td></tr><tr class="font-semibold"><td colspan="2">Net</td><td class="tabular text-right">{{ money(collection.net) }}</td></tr></tbody></table></div>
      <form class="card space-y-3 p-4" @submit.prevent="closeCash">
        <h2 class="text-sm font-semibold">Close cash drawer</h2>
        <p class="text-sm">Expected cash: <strong>{{ money(collection.cash_expected) }}</strong></p>
        <div><label class="label">Counted cash (₹)</label><input v-model.number="counted" type="number" step="0.01" class="input" required /></div>
        <button class="btn-primary">Close for today</button>
      </form>
    </div>

    <Drawer :open="newOpen" title="New invoice — choose patient" @close="newOpen = false"><PatientSearch @select="newInvoice" /></Drawer>
  </div>
</template>
