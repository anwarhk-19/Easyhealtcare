import { defineStore } from 'pinia'

export const useToast = defineStore('toast', {
  state: () => ({ items: [] as { id: number; text: string; kind: 'ok' | 'error' }[] }),
  actions: {
    show(text: string, kind: 'ok' | 'error' = 'ok') {
      const id = Date.now() + Math.random()
      this.items.push({ id, text, kind })
      setTimeout(() => (this.items = this.items.filter(i => i.id !== id)), 4500)
    },
    error(text: string) { this.show(text, 'error') },
  },
})
