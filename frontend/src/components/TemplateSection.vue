<script setup lang="ts">
import { reactive, watch } from 'vue'
import type { TemplateSection } from '@/api/types'

const props = defineProps<{ section: TemplateSection; modelValue: Record<string, unknown>; readonly: boolean }>()
const emit = defineEmits<{ save: [data: Record<string, unknown>] }>()
const data = reactive<Record<string, unknown>>({ ...props.modelValue })
watch(() => props.modelValue, v => Object.assign(data, v))

function tags(key: string): string { return ((data[key] as string[] | undefined) ?? []).join(', ') }
function setTags(key: string, v: string) { data[key] = v.split(',').map(x => x.trim()).filter(Boolean) }
function toggle(key: string, opt: string) {
  const cur = new Set((data[key] as string[] | undefined) ?? [])
  if (cur.has(opt)) cur.delete(opt); else cur.add(opt)
  data[key] = [...cur]
}
function save() {
  const clean: Record<string, unknown> = {}
  for (const f of props.section.fields ?? []) {
    const v = data[f.key]
    if (v === '' || v === undefined) continue
    clean[f.key] = f.type === 'number' ? Number(v) : v
  }
  emit('save', clean)
}
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2" @focusout="!readonly && save()">
    <div v-for="f in section.fields" :key="f.key" :class="['textarea', 'tags', 'multiselect'].includes(f.type) ? 'sm:col-span-2' : ''">
      <label class="label" :for="`${section.key}-${f.key}`">{{ f.label }}<span v-if="f.required" class="text-red-600"> *</span></label>
      <textarea v-if="f.type === 'textarea'" :id="`${section.key}-${f.key}`" v-model="data[f.key] as string" class="input" rows="2" :disabled="readonly" />
      <select v-else-if="f.type === 'select'" :id="`${section.key}-${f.key}`" v-model="data[f.key]" class="input" :disabled="readonly"><option value="">—</option><option v-for="o in f.options" :key="o" :value="o">{{ o }}</option></select>
      <div v-else-if="f.type === 'multiselect'" class="flex flex-wrap gap-1">
        <button v-for="o in f.options" :key="o" type="button" class="rounded-full border px-2 py-0.5 text-xs" :class="((data[f.key] as string[]) ?? []).includes(o) ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-slate-300'" :disabled="readonly" @click="toggle(f.key, o); save()">{{ o }}</button>
      </div>
      <input v-else-if="f.type === 'tags'" :id="`${section.key}-${f.key}`" class="input" :value="tags(f.key)" placeholder="Comma separated" :disabled="readonly" @change="setTags(f.key, ($event.target as HTMLInputElement).value)" />
      <input v-else-if="f.type === 'boolean'" :id="`${section.key}-${f.key}`" v-model="data[f.key]" type="checkbox" :disabled="readonly" @change="save" />
      <input v-else :id="`${section.key}-${f.key}`" v-model="data[f.key] as string" class="input" :type="f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'" :min="f.min" :max="f.max" step="any" :disabled="readonly" />
    </div>
  </div>
</template>
