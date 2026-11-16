import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { auth } from '@/api/client'
import { useSession } from '@/stores/session'

const staff: RouteRecordRaw[] = [
  { path: '', name: 'dashboard', component: () => import('@/views/staff/DashboardView.vue'), meta: { anyOf: ['reports.operational', 'reports.financial'] } },
  { path: 'front-desk', name: 'front-desk', component: () => import('@/views/staff/FrontDeskView.vue'), meta: { anyOf: ['queue.view'] } },
  { path: 'appointments', name: 'appointments', component: () => import('@/views/staff/AppointmentsView.vue'), meta: { anyOf: ['appointments.view'] } },
  { path: 'patients', name: 'patients', component: () => import('@/views/staff/PatientsView.vue'), meta: { anyOf: ['patients.view'] } },
  { path: 'patients/:id', name: 'patient', component: () => import('@/views/staff/PatientDetailView.vue'), props: true, meta: { anyOf: ['patients.view'] } },
  { path: 'my-queue', name: 'my-queue', component: () => import('@/views/staff/MyQueueView.vue'), meta: { doctor: true } },
  { path: 'consultations/:id', name: 'consultation', component: () => import('@/views/staff/ConsultationView.vue'), props: true, meta: { anyOf: ['consultations.view'] } },
  { path: 'billing', name: 'billing', component: () => import('@/views/staff/BillingView.vue'), meta: { anyOf: ['invoices.view'] } },
  { path: 'billing/:id', name: 'invoice', component: () => import('@/views/staff/InvoiceView.vue'), props: true, meta: { anyOf: ['invoices.view'] } },
  { path: 'lab', name: 'lab', component: () => import('@/views/staff/LabView.vue'), meta: { anyOf: ['lab.view'], feature: 'lab' } },
  { path: 'inventory', name: 'inventory', component: () => import('@/views/staff/InventoryView.vue'), meta: { anyOf: ['inventory.view'], feature: 'inventory' } },
  { path: 'reports', name: 'reports', component: () => import('@/views/staff/ReportsView.vue'), meta: { anyOf: ['reports.operational', 'reports.financial'] } },
  { path: 'settings', name: 'settings', component: () => import('@/views/staff/SettingsView.vue'), meta: { anyOf: ['organization.view', 'staff.view', 'doctors.view'] } },
]

const routes: RouteRecordRaw[] = [
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
  { path: '/reset-password', name: 'reset-password', component: () => import('@/views/ResetPasswordView.vue'), meta: { public: true } },
  { path: '/register', name: 'register', component: () => import('@/views/RegisterView.vue'), meta: { public: true } },
  { path: '/accept-invite/:token', name: 'accept-invite', component: () => import('@/views/AcceptInviteView.vue'), props: true, meta: { public: true } },
  { path: '/display/:token', name: 'display', component: () => import('@/views/DisplayView.vue'), props: true, meta: { public: true } },
  { path: '/admin', name: 'admin', component: () => import('@/views/admin/AdminView.vue'), meta: { public: true } },
  { path: '/patient', name: 'patient-app', component: () => import('@/views/patient/PatientAppView.vue'), meta: { public: true } },
  { path: '/', component: () => import('@/components/AppShell.vue'), children: staff },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({ history: createWebHistory(), routes })

/** Picks the landing page for the user's role (docs/06 §2). */
export function homeFor(s: ReturnType<typeof useSession>): string {
  if (s.isDoctor && !s.hasRole('clinic_owner')) return '/my-queue'
  if (s.hasRole('receptionist') || s.hasRole('nurse')) return '/front-desk'
  if (s.hasRole('lab_technician')) return '/lab'
  if (s.hasRole('accountant')) return '/billing'
  return '/'
}

router.beforeEach(async (to) => {
  if (to.meta.public) return true
  if (!auth.token) return { name: 'login', query: { next: to.fullPath } }
  const s = useSession()
  if (!s.me) {
    try { await s.load() } catch { return { name: 'login' } }
  }
  const anyOf = (to.meta.anyOf as string[] | undefined) ?? []
  const allowed = (anyOf.length === 0 || anyOf.some(p => s.can(p))) && (!to.meta.doctor || s.isDoctor) && (!to.meta.feature || s.hasFeature(to.meta.feature as string))
  if (!allowed) {
    const home = homeFor(s)
    return to.path === home ? true : home
  }
  return true
})
