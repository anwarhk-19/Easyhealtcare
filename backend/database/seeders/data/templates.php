<?php

// Platform consultation templates. `widget` sections are rendered by registered speciality components;
// `fields` sections are generic and validated server-side by ConsultationService::validateSection().
$core = [
    ['key' => 'vitals', 'title' => 'Vitals', 'widget' => 'core.vitals'],
    ['key' => 'complaints', 'title' => 'Chief complaints & symptoms', 'fields' => [
        ['key' => 'complaints', 'label' => 'Complaints', 'type' => 'tags'],
        ['key' => 'duration', 'label' => 'Duration', 'type' => 'text'],
        ['key' => 'history', 'label' => 'History of present illness', 'type' => 'textarea'],
    ]],
    ['key' => 'examination', 'title' => 'Examination', 'fields' => [
        ['key' => 'general', 'label' => 'General examination', 'type' => 'textarea'],
        ['key' => 'systemic', 'label' => 'Systemic examination', 'type' => 'textarea'],
    ]],
    ['key' => 'diagnosis', 'title' => 'Diagnosis', 'widget' => 'core.diagnosis'],
    ['key' => 'plan', 'title' => 'Treatment plan & advice', 'widget' => 'core.plan'],
    ['key' => 'prescription', 'title' => 'Prescription', 'widget' => 'core.prescription'],
];
$withSpecial = fn (array $extra) => array_merge(array_slice($core, 0, 3), $extra, array_slice($core, 3));

return [
    ['speciality' => 'general', 'key' => 'general', 'name' => 'General consultation', 'sections' => $core],
    ['speciality' => 'dental', 'key' => 'dental', 'name' => 'Dental consultation', 'sections' => $withSpecial([
        ['key' => 'dental_chart', 'title' => 'Dental chart', 'widget' => 'dental.chart'],
        ['key' => 'oral_exam', 'title' => 'Oral examination', 'fields' => [
            ['key' => 'oral_hygiene', 'label' => 'Oral hygiene', 'type' => 'select', 'options' => ['Good', 'Fair', 'Poor']],
            ['key' => 'gingiva', 'label' => 'Gingiva', 'type' => 'select', 'options' => ['Healthy', 'Gingivitis', 'Periodontitis']],
            ['key' => 'occlusion', 'label' => 'Occlusion', 'type' => 'text'],
        ]],
        ['key' => 'treatment_plan', 'title' => 'Treatment plan', 'widget' => 'dental.treatment_plan'],
    ])],
    ['speciality' => 'dermatology', 'key' => 'dermatology', 'name' => 'Dermatology consultation', 'sections' => $withSpecial([
        ['key' => 'skin_exam', 'title' => 'Skin examination', 'fields' => [
            ['key' => 'skin_type', 'label' => 'Fitzpatrick skin type', 'type' => 'select', 'options' => ['I', 'II', 'III', 'IV', 'V', 'VI']],
            ['key' => 'lesion_morphology', 'label' => 'Lesion morphology', 'type' => 'multiselect', 'options' => ['Macule', 'Papule', 'Plaque', 'Nodule', 'Vesicle', 'Pustule', 'Scale', 'Erosion']],
            ['key' => 'distribution', 'label' => 'Distribution', 'type' => 'text'],
            ['key' => 'body_sites', 'label' => 'Body sites', 'type' => 'tags'],
        ]],
        ['key' => 'clinical_photos', 'title' => 'Clinical photographs', 'widget' => 'dermatology.photos'],
    ])],
    ['speciality' => 'ophthalmology', 'key' => 'ophthalmology', 'name' => 'Eye examination', 'sections' => $withSpecial([
        ['key' => 'eye_exam', 'title' => 'Vision & refraction', 'widget' => 'ophthalmology.eye_exam'],
        ['key' => 'anterior_segment', 'title' => 'Slit lamp', 'fields' => [
            ['key' => 'lids_re', 'label' => 'Lids (RE)', 'type' => 'text'], ['key' => 'lids_le', 'label' => 'Lids (LE)', 'type' => 'text'],
            ['key' => 'cornea_re', 'label' => 'Cornea (RE)', 'type' => 'text'], ['key' => 'cornea_le', 'label' => 'Cornea (LE)', 'type' => 'text'],
            ['key' => 'lens_re', 'label' => 'Lens (RE)', 'type' => 'select', 'options' => ['Clear', 'Immature cataract', 'Mature cataract', 'Pseudophakia', 'Aphakia']],
            ['key' => 'lens_le', 'label' => 'Lens (LE)', 'type' => 'select', 'options' => ['Clear', 'Immature cataract', 'Mature cataract', 'Pseudophakia', 'Aphakia']],
        ]],
        ['key' => 'fundus', 'title' => 'Fundus', 'fields' => [['key' => 'fundus_re', 'label' => 'Fundus (RE)', 'type' => 'textarea'], ['key' => 'fundus_le', 'label' => 'Fundus (LE)', 'type' => 'textarea']]],
    ])],
    ['speciality' => 'physiotherapy', 'key' => 'physiotherapy', 'name' => 'Physiotherapy assessment', 'sections' => $withSpecial([
        ['key' => 'assessment', 'title' => 'Assessment', 'fields' => [
            ['key' => 'pain_score', 'label' => 'Pain (VAS 0–10)', 'type' => 'number', 'min' => 0, 'max' => 10],
            ['key' => 'rom', 'label' => 'Range of motion', 'type' => 'textarea'],
            ['key' => 'muscle_power', 'label' => 'Muscle power (MRC 0–5)', 'type' => 'number', 'min' => 0, 'max' => 5],
            ['key' => 'functional_limitation', 'label' => 'Functional limitation', 'type' => 'textarea'],
        ]],
        ['key' => 'therapy_plan', 'title' => 'Therapy plan & sessions', 'widget' => 'physiotherapy.sessions'],
    ])],
    ['speciality' => 'paediatrics', 'key' => 'paediatrics', 'name' => 'Paediatric consultation', 'sections' => $withSpecial([
        ['key' => 'growth', 'title' => 'Growth & development', 'fields' => [
            ['key' => 'head_circumference_cm', 'label' => 'Head circumference (cm)', 'type' => 'number', 'min' => 20, 'max' => 70],
            ['key' => 'milestones', 'label' => 'Milestones', 'type' => 'select', 'options' => ['Age appropriate', 'Delayed', 'Advanced']],
            ['key' => 'feeding', 'label' => 'Feeding', 'type' => 'text'],
        ]],
        ['key' => 'immunisation', 'title' => 'Immunisation', 'fields' => [['key' => 'up_to_date', 'label' => 'Up to date', 'type' => 'boolean'], ['key' => 'due', 'label' => 'Vaccines due', 'type' => 'tags']]],
    ])],
    ['speciality' => 'gynaecology', 'key' => 'gynaecology', 'name' => 'Obstetrics & gynaecology', 'sections' => $withSpecial([
        ['key' => 'obstetric', 'title' => 'Menstrual & obstetric history', 'fields' => [
            ['key' => 'lmp', 'label' => 'LMP', 'type' => 'date'],
            ['key' => 'gravida', 'label' => 'Gravida', 'type' => 'number', 'min' => 0, 'max' => 20], ['key' => 'para', 'label' => 'Para', 'type' => 'number', 'min' => 0, 'max' => 20],
            ['key' => 'living', 'label' => 'Living', 'type' => 'number', 'min' => 0, 'max' => 20], ['key' => 'abortions', 'label' => 'Abortions', 'type' => 'number', 'min' => 0, 'max' => 20],
            ['key' => 'cycle', 'label' => 'Cycle', 'type' => 'select', 'options' => ['Regular', 'Irregular', 'Amenorrhoea', 'Menopause']],
        ]],
    ])],
    ['speciality' => 'cardiology', 'key' => 'cardiology', 'name' => 'Cardiology consultation', 'sections' => $withSpecial([
        ['key' => 'cardiac', 'title' => 'Cardiac evaluation', 'fields' => [
            ['key' => 'nyha_class', 'label' => 'NYHA class', 'type' => 'select', 'options' => ['I', 'II', 'III', 'IV']],
            ['key' => 'heart_sounds', 'label' => 'Heart sounds', 'type' => 'text'],
            ['key' => 'ecg', 'label' => 'ECG findings', 'type' => 'textarea'],
            ['key' => 'echo_ef', 'label' => 'Echo EF (%)', 'type' => 'number', 'min' => 5, 'max' => 90],
        ]],
    ])],
    ['speciality' => 'orthopaedics', 'key' => 'orthopaedics', 'name' => 'Orthopaedic consultation', 'sections' => $withSpecial([
        ['key' => 'ortho_exam', 'title' => 'Musculoskeletal examination', 'fields' => [
            ['key' => 'site', 'label' => 'Site', 'type' => 'text'],
            ['key' => 'gait', 'label' => 'Gait', 'type' => 'select', 'options' => ['Normal', 'Antalgic', 'Trendelenburg', 'Unable to walk']],
            ['key' => 'rom_deg', 'label' => 'ROM (degrees)', 'type' => 'number', 'min' => 0, 'max' => 180],
            ['key' => 'imaging', 'label' => 'Imaging findings', 'type' => 'textarea'],
        ]],
    ])],
    ['speciality' => 'ent', 'key' => 'ent', 'name' => 'ENT consultation', 'sections' => $withSpecial([
        ['key' => 'ent_exam', 'title' => 'ENT examination', 'fields' => [
            ['key' => 'ear_re', 'label' => 'Ear (R)', 'type' => 'text'], ['key' => 'ear_le', 'label' => 'Ear (L)', 'type' => 'text'],
            ['key' => 'nose', 'label' => 'Nose', 'type' => 'text'], ['key' => 'throat', 'label' => 'Throat', 'type' => 'text'],
            ['key' => 'hearing', 'label' => 'Hearing', 'type' => 'select', 'options' => ['Normal', 'Conductive loss', 'Sensorineural loss', 'Mixed']],
        ]],
    ])],
];
