import { defineStore } from 'pinia'
import { api, auth } from '@/api/client'
import type { Me } from '@/api/types'

export const useSession = defineStore('session', {
  state: () => ({ me: null as Me | null, branchId: (localStorage.getItem('ehc.branch') ?? '') as string, loading: false }),
  getters: {
    can: (s) => (p: string) => !!s.me?.permissions.includes(p),
    hasRole: (s) => (r: string) => !!s.me?.roles.includes(r),
    hasFeature: (s) => (f: string) => { const fs = s.me?.subscription.plan?.features ?? []; return fs.includes('*') || fs.includes(f) },
    isDoctor: (s) => !!s.me?.doctor_profile_id,
    branch: (s) => s.me?.branches.find(b => b.id === s.branchId) ?? s.me?.branches[0] ?? null,
  },
  actions: {
    async login(email: string, password: string): Promise<{ challenge?: string }> {
      const r = await api.post<{ token?: string; two_factor_required?: boolean; challenge?: string }>('/auth/login', { email, password })
      if (r.two_factor_required) return { challenge: r.challenge }
      auth.token = r.token!
      await this.load()
      return {}
    },
    async verify2fa(challenge: string, code: string) {
      const r = await api.post<{ token: string }>('/auth/two-factor/challenge', { challenge, code })
      auth.token = r.token
      await this.load()
    },
    async load() {
      if (!auth.token) return
      this.loading = true
      try {
        this.me = await api.get<Me>('/auth/me')
        auth.organizationId = this.me.organization.id
        if (!this.me.branches.some(b => b.id === this.branchId)) this.setBranch(this.me.branches[0]?.id ?? '')
      } finally { this.loading = false }
    },
    setBranch(id: string) { this.branchId = id; localStorage.setItem('ehc.branch', id) },
    async switchOrganization(id: string) {
      await api.post('/auth/switch-organization', { organization_id: id })
      auth.organizationId = id
      await this.load()
    },
    async logout() {
      try { await api.post('/auth/logout') } catch { /* already expired */ }
      auth.token = null; auth.organizationId = null; this.me = null
    },
  },
})
