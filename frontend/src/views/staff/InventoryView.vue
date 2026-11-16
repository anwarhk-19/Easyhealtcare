<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, errorMessage } from '@/api/client'
import { useSession } from '@/stores/session'
import { useToast } from '@/stores/toast'
import { date } from '@/utils/format'
import Drawer from '@/components/Drawer.vue'

interface Row { id: string; sku: string; name: string; unit: string; qty_on_hand: number; reorder_level: number; next_expiry: string | null; low_stock: boolean }
const s = useSession()
const toast = useToast()
const rows = ref<Row[]>([])
const lowOnly = ref(false)
const suppliers = ref<{ id: string; name: string }[]>([])
const receiving = ref(false)
const grn = reactive({ supplier_id: '', supplier_invoice_ref: '', lines: [{ item_id: '', batch_no: '', expiry_date: '', quantity: 1, cost: 0 }] })
const newItem = reactive({ sku: '', name: '', unit: 'strip', reorder_level: 10 })

async function load() { rows.value = await api.get<Row[]>('/inventory/stock', { branch_id: s.branchId, low_stock: lowOnly.value || undefined }) }
onMounted(async () => { await load(); suppliers.value = (await api.list<{ id: string; name: string }>('/inventory/suppliers')).data })
async function guarded(fn: () => Promise<unknown>, msg: string) { try { await fn(); toast.show(msg); await load() } catch (e) { toast.error(errorMessage(e)) } }
const addItem = () => guarded(() => api.post('/inventory/items', newItem), 'Item added').then(() => Object.assign(newItem, { sku: '', name: '' }))
const receive = () => guarded(() => api.post('/inventory/goods-receipts', { branch_id: s.branchId, supplier_id: grn.supplier_id, supplier_invoice_ref: grn.supplier_invoice_ref, lines: grn.lines.map(l => ({ item_id: l.item_id, batch_no: l.batch_no, expiry_date: l.expiry_date || undefined, quantity: l.quantity, cost_price: Math.round(l.cost * 100) })) }), 'Stock received').then(() => (receiving.value = false))
function dispense(r: Row) { const q = Number(prompt(`Dispense how many ${r.unit} of ${r.name}?`)); if (q > 0) guarded(() => api.post('/inventory/dispense', { item_id: r.id, branch_id: s.branchId, quantity: q }), 'Dispensed') }
async function addSupplier() { const name = prompt('Supplier name'); if (name) { await guarded(() => api.post('/inventory/suppliers', { name }), 'Supplier added'); suppliers.value = (await api.list<{ id: string; name: string }>('/inventory/suppliers')).data } }
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2"><h1 class="text-xl font-semibold">Inventory</h1>
      <div class="flex items-center gap-2"><label class="flex items-center gap-1 text-sm"><input v-model="lowOnly" type="checkbox" @change="load" /> Low stock only</label>
        <button v-if="s.can('inventory.purchase')" class="btn-primary" @click="receiving = true">Receive stock</button></div></div>
    <form v-if="s.can('inventory.manage')" class="card flex flex-wrap items-end gap-2 p-3" @submit.prevent="addItem">
      <div><label class="label">SKU</label><input v-model="newItem.sku" class="input !w-28" required /></div>
      <div class="flex-1"><label class="label">Item name</label><input v-model="newItem.name" class="input" required /></div>
      <div><label class="label">Unit</label><input v-model="newItem.unit" class="input !w-24" /></div>
      <div><label class="label">Reorder at</label><input v-model.number="newItem.reorder_level" type="number" class="input !w-24" /></div>
      <button class="btn-secondary">Add item</button>
    </form>
    <div class="card overflow-x-auto"><table class="table">
      <thead><tr><th>SKU</th><th>Item</th><th class="text-right">On hand</th><th class="text-right">Reorder level</th><th>Next expiry</th><th></th></tr></thead>
      <tbody><tr v-for="r in rows" :key="r.id" :class="r.low_stock ? 'bg-amber-50/60' : ''">
        <td class="tabular text-xs">{{ r.sku }}</td><td>{{ r.name }} <span v-if="r.low_stock" class="ml-1 rounded bg-amber-100 px-1 text-xs text-amber-900">Low</span></td>
        <td class="tabular text-right">{{ r.qty_on_hand }} {{ r.unit }}</td><td class="tabular text-right">{{ r.reorder_level }}</td><td>{{ date(r.next_expiry) }}</td>
        <td class="text-right"><button v-if="s.can('inventory.dispense') && r.qty_on_hand" class="btn-secondary btn-sm" @click="dispense(r)">Dispense</button></td></tr></tbody>
    </table></div>

    <Drawer :open="receiving" title="Receive stock (GRN)" @close="receiving = false">
      <form class="space-y-3" @submit.prevent="receive">
        <div class="flex items-end gap-2"><div class="flex-1"><label class="label">Supplier</label><select v-model="grn.supplier_id" class="input" required><option v-for="sp in suppliers" :key="sp.id" :value="sp.id">{{ sp.name }}</option></select></div><button type="button" class="btn-secondary btn-sm" @click="addSupplier">+ New</button></div>
        <div><label class="label">Supplier invoice no.</label><input v-model="grn.supplier_invoice_ref" class="input" /></div>
        <div v-for="(l, i) in grn.lines" :key="i" class="grid grid-cols-2 gap-2 rounded border border-slate-200 p-2">
          <select v-model="l.item_id" class="input col-span-2" required aria-label="Item"><option value="" disabled>Item</option><option v-for="r in rows" :key="r.id" :value="r.id">{{ r.name }}</option></select>
          <input v-model="l.batch_no" class="input" placeholder="Batch no." required aria-label="Batch" /><input v-model="l.expiry_date" type="date" class="input" aria-label="Expiry" />
          <input v-model.number="l.quantity" type="number" min="1" class="input" placeholder="Qty" aria-label="Quantity" /><input v-model.number="l.cost" type="number" step="0.01" class="input" placeholder="Cost per unit (₹)" aria-label="Cost" />
        </div>
        <button type="button" class="btn-secondary btn-sm" @click="grn.lines.push({ item_id: '', batch_no: '', expiry_date: '', quantity: 1, cost: 0 })">+ Line</button>
        <button class="btn-primary w-full">Receive</button>
      </form>
    </Drawer>
  </div>
</template>
