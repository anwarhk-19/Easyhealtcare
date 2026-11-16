export type ID = string
export type AppointmentStatus = 'booked' | 'confirmed' | 'checked_in' | 'waiting' | 'in_consultation' | 'completed' | 'cancelled' | 'no_show'

export interface Branch { id: ID; name: string; code: string; timezone: string }
export interface Patient {
  id: ID; patient_code: string; first_name: string; last_name: string | null; full_name: string; gender: string | null
  date_of_birth: string | null; age: number | null; mobile: string | null; email?: string | null; blood_group?: string | null
  allergies?: { id: ID; allergen: string; severity: string; allergen_type?: string; reaction?: string }[]
  conditions?: { id: ID; name: string; icd10_code?: string; status: string }[]
  medications?: { id: ID; name: string; dosage?: string }[]
}
export interface Doctor { id: ID; display_name: string; title: string; consultation_duration_min: number; user?: { id: ID; name: string }; specialities?: { id: ID; code: string; name: string }[]; branches?: Branch[] }
export interface Token { id: ID; token_label: string; token_number: number; status: string; priority: string; appointment_id: ID; appointment?: Appointment }
export interface Appointment {
  id: ID; patient_id: ID; doctor_profile_id: ID; branch_id: ID; type: string; source: string; status: AppointmentStatus
  scheduled_start: string; scheduled_end: string; notes?: string | null; patient?: Patient; doctor?: Doctor; token?: Token | null; cancel_reason?: string | null
}
export interface Slot { start: string; end: string; local_time: string; available: boolean; reason: string | null }
export interface TemplateField { key: string; label: string; type: 'text' | 'textarea' | 'number' | 'select' | 'multiselect' | 'boolean' | 'tags' | 'date'; options?: string[]; min?: number; max?: number; required?: boolean }
export interface TemplateSection { key: string; title: string; widget?: string; fields?: TemplateField[] }
export interface RxItem { id?: ID; medicine_id?: ID | null; medicine_name: string; strength?: string | null; dose?: string | null; frequency: string; duration_value?: number | null; duration_unit?: string; timing?: string; instructions?: string | null; quantity?: number | null }
export interface Consultation {
  id: ID; created_at: string; status: 'draft' | 'signed'; chief_complaint: string | null; notes: string | null; treatment_plan: string | null; follow_up_date: string | null
  patient: Patient; doctor: Doctor; appointment_id: ID | null
  template: { id: ID; key: string; name: string; definition: { sections: TemplateSection[] } } | null
  sections: { section_key: string; data: Record<string, unknown> }[]
  diagnoses: { id?: ID; description: string; icd10_code: string | null; type: string; is_primary: boolean }[]
  vitals: Record<string, number | null>[]
  prescription: { id: ID; status: string; advice: string | null; items: RxItem[] } | null
  addenda: { id: ID; content: string; created_at: string }[]
}
export interface InvoiceItem { id?: ID; item_type?: string; item_id?: ID | null; description: string; quantity: number; unit_price: number; discount_type?: 'flat' | 'percent' | null; discount_value?: number; tax_rate?: number; tax_amount?: number; discount_amount?: number; line_total?: number }
export interface Invoice {
  id: ID; invoice_number: string | null; invoice_date: string; status: string; patient_id: ID; branch_id: ID; patient?: Patient
  subtotal: number; discount_total: number; tax_total: number; round_off: number; grand_total: number; amount_paid: number; balance_due: number; discount_status: string; items: InvoiceItem[]
}
export interface Me {
  user: { id: ID; name: string; email: string | null }
  organization: { id: ID; name: string; slug: string; status: string; timezone: string }
  subscription: { plan: { code: string; name: string; features: string[]; limits: Record<string, number> } | null; status: string; trial_ends_at: string | null }
  roles: string[]; permissions: string[]; branch_ids: ID[] | null; branches: Branch[]; doctor_profile_id: ID | null
  settings: Record<string, unknown>; memberships: { organization_id: ID; name: string }[]
}
