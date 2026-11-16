<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '@/api/client'
import type { Patient } from '@/api/types'

const emit = defineEmits<{ select: [p: Patient] }>()
defineProps<{ placeholder?: string }>()
const q = ref('')
const results = ref<Patient[]>([])
const open = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

watch(q, (v) => {
  clearTimeout(timer)
  if (v.trim().length < 2) { results.value = []; return }
  timer = setTimeout(async () => {
    results.value = (await api.list<Patient>('/patients', { q: v, per_page: 8 })).data
    open.value = true
  }, 200)
})
function pick(p: Patient) { emit('select', p); q.value = ''; results.value = []; open.value = false }
</script>

<template>
  <div class="relative">
    <input v-model="q" class="input" type="search" :placeholder="placeholder ?? 'Search by name, mobile or patient ID'" aria-label="Search patients" @focus="open = true" @keydown.esc="open = false" />
    <ul v-if="open && results.length" class="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-md border border-slate-200 bg-white shadow-lg">
      <li v-for="p in results" :key="p.id">
        <button class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-primary-50" @click="pick(p)">
          <span><strong>{{ p.full_name }}</strong> <span class="text-slate-500">· {{ p.gender }} · {{ p.age ?? '?' }}y</span>
            <span v-if="p.allergies?.length" class="ml-1 rounded bg-red-50 px-1 text-xs text-red-700">⚠ allergy</span></span>
          <span class="tabular text-xs text-slate-500">{{ p.patient_code }} · {{ p.mobile }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>
