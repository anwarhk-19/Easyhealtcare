@extends('pdf._layout')
@section('title')
  <div><strong>{{ $rx->doctor->display_name }}</strong></div>
  <div class="muted">{{ implode(', ', $rx->doctor->qualifications ?? []) }}</div>
  <div class="muted">{{ $rx->doctor->specialities->pluck('name')->implode(', ') }}</div>
  @if($rx->doctor->registration_number)<div class="small">Reg. No. {{ $rx->doctor->registration_number }} ({{ $rx->doctor->registration_council }})</div>@endif
@endsection
@section('content')
@php($p = $rx->patient)
<div class="box"><table><tr>
  <td><strong>{{ $p->full_name }}</strong> · {{ ucfirst($p->gender ?? '') }} · {{ $p->age !== null ? $p->age.' yrs' : '' }}<br><span class="muted">ID {{ $p->patient_code }} @if($p->mobile) · {{ $p->mobile }} @endif</span></td>
  <td class="right">Date: {{ ($rx->signed_at ?? $rx->created_at)->setTimezone($branch->timezone ?: 'Asia/Kolkata')->format('d M Y') }}</td>
</tr></table></div>
@if($v = $rx->consultation->vitals->last())
<div class="small muted" style="margin-bottom:6px">Vitals: @if($v->bp_systolic) BP {{ $v->bp_systolic }}/{{ $v->bp_diastolic }} @endif @if($v->pulse_bpm) · Pulse {{ $v->pulse_bpm }} @endif @if($v->temperature_c) · Temp {{ $v->temperature_c }}°C @endif @if($v->spo2) · SpO₂ {{ $v->spo2 }}% @endif @if($v->weight_kg) · Wt {{ $v->weight_kg }} kg @endif</div>
@endif
@if($rx->consultation->chief_complaint)<div><strong>Complaints:</strong> {{ $rx->consultation->chief_complaint }}</div>@endif
@if($rx->consultation->diagnoses->isNotEmpty())<div style="margin-bottom:8px"><strong>Diagnosis:</strong> {{ $rx->consultation->diagnoses->map(fn($d) => $d->description.($d->icd10_code ? ' ('.$d->icd10_code.')' : ''))->implode('; ') }}</div>@endif
<div class="rx">℞</div>
<table class="lines">
  <thead><tr><th>#</th><th>Medicine</th><th>Dose</th><th>Frequency</th><th>Duration</th><th>Timing / instructions</th></tr></thead>
  <tbody>
  @foreach($rx->items as $i => $item)
    <tr><td>{{ $i + 1 }}</td><td><strong>{{ $item->medicine_name }}</strong> {{ $item->strength }} <span class="muted">{{ $item->form }}</span></td>
      <td>{{ $item->dose }}</td><td>{{ $item->frequency }}</td><td>{{ $item->duration_value ? $item->duration_value.' '.$item->duration_unit : '' }}</td>
      <td>{{ str_replace('_', ' ', $item->timing) }} @if($item->instructions)<br><span class="muted">{{ $item->instructions }}</span>@endif</td></tr>
  @endforeach
  </tbody>
</table>
@if($rx->advice)<div class="box" style="margin-top:10px"><strong>Advice:</strong> {!! nl2br(e($rx->advice)) !!}</div>@endif
@if($rx->consultation->follow_up_date)<div><strong>Follow-up:</strong> {{ $rx->consultation->follow_up_date->format('d M Y') }} {{ $rx->consultation->follow_up_notes }}</div>@endif
<table style="margin-top:30px"><tr><td></td><td class="right" style="width:40%">
  @if($rx->doctor->signature_path && \Illuminate\Support\Facades\Storage::disk(config('clinic.documents_disk'))->exists($rx->doctor->signature_path))
    <img src="data:image/png;base64,{{ base64_encode(\Illuminate\Support\Facades\Storage::disk(config('clinic.documents_disk'))->get($rx->doctor->signature_path)) }}" style="height:40px"><br>
  @endif
  <strong>{{ $rx->doctor->display_name }}</strong>@if($rx->status !== 'signed')<br><span class="flag-critical">DRAFT — not signed</span>@endif
</td></tr></table>
@endsection
