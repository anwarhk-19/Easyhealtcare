/** Indian number grouping for paise amounts: 1234567 -> ₹12,345.67 */
export function money(paise: number | null | undefined): string {
  const v = (paise ?? 0) / 100
  return '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
export const toPaise = (rupees: number | string) => Math.round(Number(rupees || 0) * 100)
export const fromPaise = (paise: number) => paise / 100

export function dateTime(iso: string | null | undefined, tz = 'Asia/Kolkata'): string {
  if (!iso) return ''
  return new Date(iso).toLocaleString('en-IN', { timeZone: tz, day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true })
}
export function time(iso: string | null | undefined, tz = 'Asia/Kolkata'): string {
  if (!iso) return ''
  return new Date(iso).toLocaleTimeString('en-IN', { timeZone: tz, hour: '2-digit', minute: '2-digit', hour12: true })
}
export function date(iso: string | null | undefined): string {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}
/** YYYY-MM-DD in the given timezone (defaults to IST). */
export function today(offsetDays = 0, tz = 'Asia/Kolkata'): string {
  const d = new Date(Date.now() + offsetDays * 86400000)
  return d.toLocaleDateString('en-CA', { timeZone: tz })
}
export const STATUS_STYLES: Record<string, string> = {
  booked: 'bg-slate-100 text-slate-700', confirmed: 'bg-blue-50 text-blue-700', checked_in: 'bg-amber-50 text-amber-800',
  waiting: 'bg-amber-50 text-amber-800', called: 'bg-amber-100 text-amber-900', in_consultation: 'bg-primary-50 text-primary-700',
  completed: 'bg-green-50 text-green-700', cancelled: 'bg-slate-100 text-slate-500 line-through', no_show: 'border border-red-300 text-red-700',
  draft: 'bg-slate-100 text-slate-700', issued: 'bg-blue-50 text-blue-700', partially_paid: 'bg-amber-50 text-amber-800', paid: 'bg-green-50 text-green-700', void: 'bg-slate-100 text-slate-500 line-through',
  signed: 'bg-green-50 text-green-700', ordered: 'bg-slate-100 text-slate-700', sample_collected: 'bg-blue-50 text-blue-700', in_progress: 'bg-amber-50 text-amber-800', report_ready: 'bg-primary-50 text-primary-700', released: 'bg-green-50 text-green-700',
  active: 'bg-green-50 text-green-700', trialing: 'bg-blue-50 text-blue-700', suspended: 'bg-red-50 text-red-700', pending: 'bg-amber-50 text-amber-800',
}
export const label = (s: string) => s.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase())
