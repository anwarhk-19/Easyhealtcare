@extends('pdf._layout')
@section('title')
  <div style="font-size:16px"><strong>LABORATORY REPORT</strong></div>
  <div>{{ $order->order_number }}</div><div class="muted">{{ ($order->released_at ?? $order->updated_at)->format('d M Y') }}</div>
@endsection
@section('content')
<div class="box"><strong>{{ $order->patient->full_name }}</strong> · {{ ucfirst($order->patient->gender ?? '') }} · {{ $order->patient->age !== null ? $order->patient->age.' yrs' : '' }} · ID {{ $order->patient->patient_code }}</div>
@foreach($order->items as $item)
  <h3 style="margin:10px 0 4px">{{ $item->test->name }}</h3>
  <table class="lines"><thead><tr><th>Parameter</th><th>Result</th><th>Unit</th><th>Reference range</th></tr></thead><tbody>
  @foreach($item->results ?? [] as $r)
    <tr><td>{{ $r['name'] }}</td><td class="flag-{{ $r['flag'] }}">{{ $r['value'] }} @if($r['flag'] !== 'normal')({{ strtoupper($r['flag']) }})@endif</td><td>{{ $r['unit'] }}</td><td>{{ $r['range'] }}</td></tr>
  @endforeach
  </tbody></table>
@endforeach
@if($order->status !== 'released')<p class="flag-critical">Provisional — not yet released.</p>@endif
@endsection
