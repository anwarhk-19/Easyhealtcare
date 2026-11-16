@extends('pdf._layout')
@section('title')
  <div style="font-size:16px"><strong>PAYMENT RECEIPT</strong></div>
  <div>{{ $payment->receipt_number }}</div><div class="muted">{{ $payment->received_at->setTimezone($branch->timezone ?: 'Asia/Kolkata')->format('d M Y, h:i A') }}</div>
@endsection
@section('content')
<div class="box">Received from <strong>{{ $payment->patient->full_name }}</strong> ({{ $payment->patient->patient_code }}) the sum of <strong>{{ \App\Support\Money::format($payment->amount) }}</strong> by {{ str_replace('_', ' ', $payment->method) }} @if($payment->reference_no) (ref. {{ $payment->reference_no }}) @endif.</div>
@if($payment->allocations->isNotEmpty())
<table class="lines"><thead><tr><th>Invoice</th><th class="right">Applied</th></tr></thead><tbody>
@foreach($payment->allocations as $a)<tr><td>{{ $a->invoice->invoice_number }}</td><td class="right">{{ \App\Support\Money::format($a->amount) }}</td></tr>@endforeach
</tbody></table>
@endif
@if($payment->refunded_amount)<div class="small muted" style="margin-top:6px">Refunded: {{ \App\Support\Money::format($payment->refunded_amount) }}</div>@endif
@endsection
