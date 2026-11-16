@extends('pdf._layout')
@php($m = fn($p) => \App\Support\Money::format((int) $p))
@section('title')
  <div style="font-size:16px"><strong>{{ $invoice->tax_total > 0 ? 'TAX INVOICE' : 'INVOICE' }}</strong></div>
  <div>{{ $invoice->invoice_number }}</div><div class="muted">{{ $invoice->invoice_date->format('d M Y') }}</div>
  @if($invoice->status === 'void')<div class="flag-critical">VOID</div>@endif
@endsection
@section('content')
<div class="box"><strong>Billed to:</strong> {{ $invoice->patient->full_name }} ({{ $invoice->patient->patient_code }}) @if($invoice->patient->mobile) · {{ $invoice->patient->mobile }} @endif</div>
<table class="lines">
  <thead><tr><th>#</th><th>Description</th><th>HSN/SAC</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Discount</th><th class="right">Tax</th><th class="right">Amount</th></tr></thead>
  <tbody>
  @foreach($invoice->items as $i => $line)
    <tr><td>{{ $i + 1 }}</td><td>{{ $line->description }}</td><td>{{ $line->hsn_sac }}</td><td class="right">{{ rtrim(rtrim(number_format($line->quantity, 2), '0'), '.') }}</td>
      <td class="right">{{ $m($line->unit_price) }}</td><td class="right">{{ $line->discount_amount ? $m($line->discount_amount) : '—' }}</td>
      <td class="right">{{ $line->tax_rate > 0 ? $m($line->tax_amount).' ('.$line->tax_rate.'%)' : '—' }}</td><td class="right">{{ $m($line->line_total) }}</td></tr>
  @endforeach
  </tbody>
</table>
<table style="margin-top:8px"><tr><td style="width:55%">
  @if($invoice->tax_total > 0)<div class="small muted">CGST {{ $m(intdiv($invoice->tax_total, 2)) }} · SGST {{ $m($invoice->tax_total - intdiv($invoice->tax_total, 2)) }}</div>@endif
  @if($invoice->notes)<div class="small">{{ $invoice->notes }}</div>@endif
</td><td><table>
  <tr><td>Subtotal</td><td class="right">{{ $m($invoice->subtotal) }}</td></tr>
  @if($invoice->discount_total)<tr><td>Discount</td><td class="right">− {{ $m($invoice->discount_total) }}</td></tr>@endif
  @if($invoice->tax_total)<tr><td>Tax</td><td class="right">{{ $m($invoice->tax_total) }}</td></tr>@endif
  @if($invoice->round_off)<tr><td>Round off</td><td class="right">{{ $m($invoice->round_off) }}</td></tr>@endif
  <tr><td><strong>Total</strong></td><td class="right"><strong>{{ $m($invoice->grand_total) }}</strong></td></tr>
  <tr><td>Paid</td><td class="right">{{ $m($invoice->amount_paid) }}</td></tr>
  <tr><td><strong>Balance due</strong></td><td class="right"><strong>{{ $m($invoice->balance_due) }}</strong></td></tr>
</table></td></tr></table>
@endsection
