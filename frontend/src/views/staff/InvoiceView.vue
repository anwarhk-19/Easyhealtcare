<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, errorMessage } from '@/api/client'
import type { Invoice, InvoiceItem } from '@/api/types'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { money, toPaise } from '@/utils/format'
import StatusPill from '@/components/StatusPill.vue'

const props = defineProps<{ id: string }>()
const s = useSession()
const toast = useToast()
const inv = ref<Invoice | null>(null)
const services = ref<{ id: string; name: string; price: number; tax_rate: { rate: number } | null }[]>([])
const lines = ref<(InvoiceItem & { rupees: number })[]>([])
const pay = reactive({ amount: 0, method: 'cash', reference_no: '' })
const draft = computed(() => inv.value?.status === 'draft')

async function load() {
  inv.value = await api.get<Invoice>(`/invoices/${props.id}`)
  lines.value = inv.value.items.map(i => ({ ...i, rupees: i.unit_price / 100 }))
  pay.amount = inv.value.balance_due / 100
}
onMounted(async () => { await load(); services.value = (await api.list<(typeof services.value)[number]>('/services', { per_page: 100 })).data })

function addService(id: string) {
  const sv = services.value.find(x => x.id === id)
  if (sv) lines.value.push({ item_type: 'service', item_id: sv.id, description: sv.name, quantity: 1, unit_price: sv.price, rupees: sv.price / 100, tax_rate: sv.tax_rate?.rate ?? 0 })
}
async function guarded(fn: () => Promise<unknown>, msg?: string) { try { await fn(); if (msg) toast.show(msg); await load() } catch (e) { toast.error(errorMessage(e)) } }
const saveLines = () => guarded(() => api.put(`/invoices/${props.id}/items`, { items: lines.value.map(l => ({ item_type: l.item_type, item_id: l.item_id, description: l.description, quantity: l.quantity, unit_price: toPaise(l.rupees), tax_rate: l.tax_rate ?? 0, discount_type: l.discount_type || null, discount_value: l.discount_type === 'flat' ? toPaise(l.discount_value ?? 0) : l.discount_value ?? 0 })) }), 'Saved')
const issue = () => guarded(() => api.post(`/invoices/${props.id}/issue`), 'Invoice issued')
const decide = (approve: boolean) => guarded(() => api.post(`/invoices/${props.id}/discount-decision`, { approve }))
const collect = () => guarded(() => api.post('/payments', { patient_id: inv.value!.patient_id, branch_id: inv.value!.branch_id, amount: toPaise(pay.amount), method: pay.method, reference_no: pay.reference_no || undefined, allocations: [{ invoice_id: props.id, amount: Math.min(toPaise(pay.amount), inv.value!.balance_due) }] }, false, { 'Idempotency-Key': crypto.randomUUID() }), 'Payment recorded')
const share = () => guarded(() => api.post(`/invoices/${props.id}/share`, { channels: ['whatsapp'] }), 'Invoice sent')
const voidIt = () => { const reason = prompt('Reason for voiding?'); if (reason) guarded(() => api.post(`/invoices/${props.id}/void`, { reason }), 'Voided') }
</script>

<template>
  <div v-if="inv" class="space-y-4">
    <header class="flex flex-wrap items-center gap-3">
      <h1 class="text-xl font-semibold">{{ inv.invoice_number ?? 'Draft invoice' }}</h1><StatusPill :status="inv.status" />
      <span class="text-sm text-slate-600">{{ inv.patient?.full_name }} · {{ inv.patient?.patient_code }}</span>
      <div class="ml-auto flex gap-2">
        <button v-if="!draft" class="btn-secondary btn-sm" @click="api.open(`/invoices/${props.id}/pdf`)">PDF</button>
        <button v-if="!draft && inv.status !== 'void'" class="btn-secondary btn-sm" @click="share">Send</button>
        <button v-if="s.can('invoices.void') && inv.status !== 'void' && !inv.amount_paid" class="btn-secondary btn-sm" @click="voidIt">Void</button>
      </div>
    </header>
    <div v-if="inv.discount_status === 'pending'" class="flex items-center gap-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm">
      Discount of {{ money(inv.discount_total) }} awaits approval.
      <template v-if="s.can('discounts.approve')"><button class="btn-primary btn-sm" @click="decide(true)">Approve</button><button class="btn-secondary btn-sm" @click="decide(false)">Reject</button></template>
    </div>

    <section class="card overflow-x-auto p-2">
      <table class="table">
        <thead><tr><th>Description</th><th>Qty</th><th>Rate (₹)</th><th>Discount</th><th>GST %</th><th class="text-right">Amount</th><th v-if="draft"></th></tr></thead>
        <tbody>
          <tr v-for="(l, i) in lines" :key="i">
            <td><input v-model="l.description" class="input" :disabled="!draft" aria-label="Description" /></td>
            <td><input v-model.number="l.quantity" type="number" min="1" class="input !w-16" :disabled="!draft" aria-label="Quantity" /></td>
            <td><input v-model.number="l.rupees" type="number" step="0.01" class="input !w-28" :disabled="!draft" aria-label="Rate" /></td>
            <td class="whitespace-nowrap"><select v-model="l.discount_type" class="input !inline-block !w-20" :disabled="!draft || !s.can('discounts.apply')" aria-label="Discount type"><option :value="null">—</option><option value="percent">%</option><option value="flat">₹</option></select>
              <input v-if="l.discount_type" v-model.number="l.discount_value" type="number" class="input !inline-block !w-20" :disabled="!draft" aria-label="Discount" /></td>
            <td><input v-model.number="l.tax_rate" type="number" class="input !w-16" :disabled="!draft" aria-label="GST" /></td>
            <td class="tabular text-right">{{ l.line_total !== undefined ? money(l.line_total) : '—' }}</td>
            <td v-if="draft"><button class="btn-secondary btn-sm" aria-label="Remove line" @click="lines.splice(i, 1)">✕</button></td>
          </tr>
        </tbody>
      </table>
      <div v-if="draft" class="flex flex-wrap gap-2 p-2">
        <select class="input !w-64" aria-label="Add service" @change="addService(($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''"><option value="">+ Add service…</option><option v-for="sv in services" :key="sv.id" :value="sv.id">{{ sv.name }} — {{ money(sv.price) }}</option></select>
        <button class="btn-secondary btn-sm" @click="lines.push({ description: '', quantity: 1, unit_price: 0, rupees: 0, tax_rate: 0 })">+ Custom line</button>
        <button class="btn-secondary btn-sm" @click="saveLines">Save & recalculate</button>
        <button class="btn-primary btn-sm ml-auto" :disabled="!inv.items.length" @click="issue">Issue invoice</button>
      </div>
    </section>

    <div class="grid gap-4 md:grid-cols-2">
      <section class="card p-4 text-sm">
        <dl class="grid grid-cols-2 gap-y-1 tabular">
          <dt>Subtotal</dt><dd class="text-right">{{ money(inv.subtotal) }}</dd>
          <dt>Discount</dt><dd class="text-right">− {{ money(inv.discount_total) }}</dd>
          <dt>GST</dt><dd class="text-right">{{ money(inv.tax_total) }}</dd>
          <dt>Round off</dt><dd class="text-right">{{ money(inv.round_off) }}</dd>
          <dt class="font-semibold">Total</dt><dd class="text-right font-semibold">{{ money(inv.grand_total) }}</dd>
          <dt>Paid</dt><dd class="text-right">{{ money(inv.amount_paid) }}</dd>
          <dt class="font-semibold">Balance due</dt><dd class="text-right font-semibold">{{ money(inv.balance_due) }}</dd>
        </dl>
      </section>
      <form v-if="['issued', 'partially_paid'].includes(inv.status) && s.can('payments.collect')" class="card space-y-3 p-4" @submit.prevent="collect">
        <h2 class="text-sm font-semibold">Collect payment</h2>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="label">Amount (₹)</label><input v-model.number="pay.amount" type="number" step="0.01" min="0.01" class="input" required /></div>
          <div><label class="label">Method</label><select v-model="pay.method" class="input"><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="wallet">Wallet</option></select></div>
          <div class="col-span-2"><label class="label">Reference (UPI ref / card last 4)</label><input v-model="pay.reference_no" class="input" /></div>
        </div>
        <button class="btn-primary w-full">Record payment</button>
        <p class="text-xs text-slate-500">Amounts above the balance are kept as advance credit.</p>
      </form>
    </div>
  </div>
</template>
