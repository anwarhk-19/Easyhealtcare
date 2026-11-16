<?php

namespace Database\Seeders;

use App\Models\ConsultationTemplate;
use App\Models\Icd10Code;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Speciality;
use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/** Platform-level reference data. Idempotent: safe to re-run on every deploy. */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        app(TenantContext::class)->bypass(function () {
            $this->permissionsAndRoles();
            $this->plans();
            $this->specialitiesAndTemplates();
            $this->masters();
            $this->notificationTemplates();
            if ($email = env('PLATFORM_ADMIN_EMAIL')) {
                User::firstOrCreate(['email' => $email], ['name' => 'Platform Admin', 'password' => env('PLATFORM_ADMIN_PASSWORD', str()->password(20))])
                    ->forceFill(['platform_role' => 'super_admin'])->save();
            }
        });
    }

    private function permissionsAndRoles(): void
    {
        foreach (PermissionCatalog::PERMISSIONS as $module => $names) {
            foreach ($names as $name) {
                Permission::firstOrCreate(['name' => $name], ['module' => $module]);
            }
        }
        $ids = Permission::pluck('id', 'name');
        foreach (PermissionCatalog::roles() as $key => $role) {
            $r = Role::firstOrCreate(['organization_id' => null, 'key' => $key], ['name' => $role['name'], 'is_system' => true]);
            $r->permissions()->sync($ids->only($role['permissions'])->values());
        }
    }

    private function plans(): void
    {
        $plans = [
            ['code' => 'starter', 'name' => 'Starter', 'description' => 'Single-doctor clinic', 'price_monthly' => 99900, 'price_annual' => 999000, 'limits' => ['max_doctors' => 1, 'max_branches' => 1, 'max_staff' => 3, 'whatsapp_quota' => 500], 'features' => ['lab'], 'sort' => 1],
            ['code' => 'professional', 'name' => 'Professional', 'description' => 'Multi-doctor clinic', 'price_monthly' => 299900, 'price_annual' => 2999000, 'limits' => ['max_doctors' => 10, 'max_branches' => 1, 'max_staff' => 25, 'whatsapp_quota' => 3000], 'features' => ['lab', 'inventory', 'specialities', 'patient_app'], 'sort' => 2],
            ['code' => 'business', 'name' => 'Business', 'description' => 'Multiple branches', 'price_monthly' => 699900, 'price_annual' => 6999000, 'limits' => ['max_doctors' => 50, 'max_branches' => 10, 'max_staff' => 150, 'whatsapp_quota' => 15000], 'features' => ['lab', 'inventory', 'specialities', 'patient_app', 'ai', 'hr'], 'sort' => 3],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'description' => 'Custom requirements', 'price_monthly' => 0, 'price_annual' => 0, 'limits' => [], 'features' => ['*'], 'is_public' => false, 'sort' => 4],
        ];
        foreach ($plans as $p) {
            Plan::updateOrCreate(['code' => $p['code']], $p);
        }
    }

    private function specialitiesAndTemplates(): void
    {
        $names = ['general' => 'General Physician', 'dental' => 'Dental', 'dermatology' => 'Dermatology', 'paediatrics' => 'Paediatrics', 'cardiology' => 'Cardiology', 'orthopaedics' => 'Orthopaedics', 'gynaecology' => 'Obstetrics & Gynaecology', 'ophthalmology' => 'Ophthalmology', 'ent' => 'ENT', 'physiotherapy' => 'Physiotherapy'];
        foreach ($names as $code => $name) {
            Speciality::updateOrCreate(['code' => $code], ['name' => $name, 'module_key' => in_array($code, ['dental', 'ophthalmology', 'physiotherapy', 'dermatology'], true) ? $code : null]);
        }
        foreach (require __DIR__.'/data/templates.php' as $t) {
            ConsultationTemplate::updateOrCreate(
                ['organization_id' => null, 'key' => $t['key'], 'version' => 1],
                ['name' => $t['name'], 'speciality_id' => Speciality::where('code', $t['speciality'])->value('id'), 'definition' => ['sections' => $t['sections']], 'is_default' => true],
            );
        }
    }

    private function masters(): void
    {
        $icd = [
            ['J06.9', 'Acute upper respiratory infection, unspecified'], ['J02.9', 'Acute pharyngitis, unspecified'], ['A09', 'Infectious gastroenteritis and colitis'],
            ['E11.9', 'Type 2 diabetes mellitus without complications'], ['I10', 'Essential (primary) hypertension'], ['E78.5', 'Hyperlipidaemia, unspecified'],
            ['K21.9', 'Gastro-oesophageal reflux disease without oesophagitis'], ['M54.5', 'Low back pain'], ['M17.9', 'Osteoarthritis of knee, unspecified'],
            ['J45.909', 'Asthma, unspecified'], ['N39.0', 'Urinary tract infection, site not specified'], ['R50.9', 'Fever, unspecified'],
            ['A90', 'Dengue fever'], ['B54', 'Unspecified malaria'], ['D64.9', 'Anaemia, unspecified'], ['E03.9', 'Hypothyroidism, unspecified'],
            ['K02.9', 'Dental caries, unspecified'], ['K05.1', 'Chronic gingivitis'], ['K04.0', 'Pulpitis'], ['L70.0', 'Acne vulgaris'],
            ['L20.9', 'Atopic dermatitis, unspecified'], ['B35.4', 'Tinea corporis'], ['H52.1', 'Myopia'], ['H25.9', 'Senile cataract, unspecified'],
            ['H10.9', 'Conjunctivitis, unspecified'], ['H66.9', 'Otitis media, unspecified'], ['J32.9', 'Chronic sinusitis, unspecified'], ['O80', 'Single spontaneous delivery'],
            ['Z34.9', 'Supervision of normal pregnancy, unspecified'], ['N94.6', 'Dysmenorrhoea, unspecified'], ['I25.10', 'Atherosclerotic heart disease'], ['M75.0', 'Adhesive capsulitis of shoulder'],
            ['S93.4', 'Sprain of ankle'], ['Z00.0', 'General adult medical examination'], ['Z23', 'Encounter for immunisation'], ['R51', 'Headache'],
        ];
        foreach ($icd as [$code, $description]) {
            Icd10Code::updateOrCreate(['code' => $code], ['description' => $description]);
        }

        $medicines = [
            ['Paracetamol', 'Dolo 650', 'tablet', '650 mg', 'analgesic'], ['Paracetamol', 'Calpol', 'syrup', '120 mg/5 ml', 'analgesic'],
            ['Ibuprofen', 'Brufen', 'tablet', '400 mg', 'nsaid'], ['Amoxicillin', 'Mox', 'capsule', '500 mg', 'penicillin'],
            ['Amoxicillin + Clavulanic acid', 'Augmentin', 'tablet', '625 mg', 'penicillin'], ['Azithromycin', 'Azithral', 'tablet', '500 mg', 'macrolide'],
            ['Cetirizine', 'Cetzine', 'tablet', '10 mg', 'antihistamine'], ['Pantoprazole', 'Pan 40', 'tablet', '40 mg', 'ppi'],
            ['Metformin', 'Glycomet', 'tablet', '500 mg', 'biguanide'], ['Amlodipine', 'Amlong', 'tablet', '5 mg', 'calcium channel blocker'],
            ['Telmisartan', 'Telma', 'tablet', '40 mg', 'arb'], ['Atorvastatin', 'Atorva', 'tablet', '10 mg', 'statin'],
            ['Levothyroxine', 'Thyronorm', 'tablet', '50 mcg', 'thyroid'], ['Ondansetron', 'Emeset', 'tablet', '4 mg', 'antiemetic'],
            ['Oral rehydration salts', 'Electral', 'powder', '21.8 g', 'electrolyte'], ['Salbutamol', 'Asthalin', 'inhaler', '100 mcg', 'bronchodilator'],
            ['Montelukast', 'Montair', 'tablet', '10 mg', 'leukotriene antagonist'], ['Diclofenac', 'Voveran', 'gel', '1%', 'nsaid'],
            ['Chlorhexidine', 'Hexidine', 'mouthwash', '0.2%', 'antiseptic'], ['Clotrimazole', 'Candid', 'cream', '1%', 'antifungal'],
            ['Adapalene', 'Adaferin', 'gel', '0.1%', 'retinoid'], ['Moxifloxacin', 'Vigamox', 'eye drops', '0.5%', 'fluoroquinolone'],
            ['Carboxymethylcellulose', 'Refresh Tears', 'eye drops', '0.5%', 'lubricant'], ['Folic acid', 'Folvite', 'tablet', '5 mg', 'vitamin'],
            ['Iron + Folic acid', 'Autrin', 'capsule', '', 'haematinic'], ['Calcium + Vitamin D3', 'Shelcal', 'tablet', '500 mg', 'supplement'],
        ];
        foreach ($medicines as [$generic, $brand, $form, $strength, $class]) {
            Medicine::updateOrCreate(['organization_id' => null, 'brand_name' => $brand, 'strength' => $strength], ['generic_name' => $generic, 'form' => $form, 'drug_class' => $class]);
        }

        $tests = [
            ['CBC', 'Complete blood count', 'blood', 30000, [['key' => 'hb', 'name' => 'Haemoglobin', 'unit' => 'g/dL', 'low' => 12, 'high' => 17, 'critical_low' => 7], ['key' => 'wbc', 'name' => 'WBC', 'unit' => '10^3/µL', 'low' => 4, 'high' => 11], ['key' => 'plt', 'name' => 'Platelets', 'unit' => '10^3/µL', 'low' => 150, 'high' => 450, 'critical_low' => 50]]],
            ['FBS', 'Fasting blood sugar', 'blood', 10000, [['key' => 'glucose', 'name' => 'Glucose (fasting)', 'unit' => 'mg/dL', 'low' => 70, 'high' => 100, 'critical_low' => 40, 'critical_high' => 400]]],
            ['HBA1C', 'HbA1c', 'blood', 45000, [['key' => 'hba1c', 'name' => 'HbA1c', 'unit' => '%', 'low' => 4, 'high' => 5.6]]],
            ['LIPID', 'Lipid profile', 'blood', 60000, [['key' => 'tc', 'name' => 'Total cholesterol', 'unit' => 'mg/dL', 'high' => 200], ['key' => 'ldl', 'name' => 'LDL', 'unit' => 'mg/dL', 'high' => 100], ['key' => 'hdl', 'name' => 'HDL', 'unit' => 'mg/dL', 'low' => 40], ['key' => 'tg', 'name' => 'Triglycerides', 'unit' => 'mg/dL', 'high' => 150]]],
            ['TSH', 'Thyroid stimulating hormone', 'blood', 35000, [['key' => 'tsh', 'name' => 'TSH', 'unit' => 'µIU/mL', 'low' => 0.4, 'high' => 4.0]]],
            ['URINE', 'Urine routine', 'urine', 15000, [['key' => 'protein', 'name' => 'Protein', 'unit' => ''], ['key' => 'pus_cells', 'name' => 'Pus cells', 'unit' => '/hpf', 'high' => 5]]],
            ['DENGUE', 'Dengue NS1 antigen', 'blood', 60000, [['key' => 'ns1', 'name' => 'NS1', 'unit' => '']]],
        ];
        foreach ($tests as [$code, $name, $sample, $price, $params]) {
            LabTest::updateOrCreate(['organization_id' => null, 'code' => $code], ['name' => $name, 'sample_type' => $sample, 'price' => $price, 'parameters' => $params]);
        }
    }

    private function notificationTemplates(): void
    {
        $t = [
            'appointment.booked' => 'Hello {{patient_name}}, your appointment with {{doctor_name}} at {{clinic_name}} ({{branch_name}}) is booked for {{date}} at {{time}}.',
            'appointment.confirmed' => 'Hello {{patient_name}}, your appointment with {{doctor_name}} on {{date}} at {{time}} is confirmed.',
            'appointment.reminder' => 'Reminder: {{patient_name}}, you have an appointment with {{doctor_name}} at {{clinic_name}} on {{date}} at {{time}}.',
            'appointment.cancelled' => 'Hello {{patient_name}}, your appointment with {{doctor_name}} on {{date}} at {{time}} has been cancelled. Please contact {{clinic_name}} to rebook.',
            'waitlist.slot_available' => 'Hello {{patient_name}}, a slot has opened at {{clinic_name}} on {{date}}. Reply or call us to book.',
            'followup.reminder' => 'Hello {{patient_name}}, {{doctor_name}} advised a follow-up on {{date}}. Please book your visit at {{clinic_name}}.',
            'prescription.shared' => 'Hello {{patient_name}}, your prescription from {{doctor_name}} is ready: {{link}}',
            'invoice.shared' => 'Hello {{patient_name}}, your invoice {{invoice_number}} from {{clinic_name}}: {{link}}',
            'payment.received' => 'Thank you {{patient_name}}. We received {{amount}} (receipt {{receipt_number}}) at {{clinic_name}}.',
            'lab.report_ready' => 'Hello {{patient_name}}, your lab report ({{order_number}}) from {{clinic_name}} is ready: {{link}}',
        ];
        foreach ($t as $event => $body) {
            foreach (['whatsapp', 'sms', 'email'] as $channel) {
                NotificationTemplate::updateOrCreate(
                    ['organization_id' => null, 'event_key' => $event, 'channel' => $channel, 'locale' => 'en'],
                    ['body' => $body, 'subject' => $channel === 'email' ? ucfirst(str_replace(['.', '_'], ' ', $event)) : null, 'provider_template_name' => $channel === 'whatsapp' ? str_replace('.', '_', $event) : null],
                );
            }
        }
    }
}
