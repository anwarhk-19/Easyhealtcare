<!doctype html>
<html><head><meta charset="utf-8"><style>
  @page { margin: 28px 32px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; }
  .head { border-bottom: 2px solid #0f766e; padding-bottom: 8px; margin-bottom: 12px; }
  .head h1 { margin: 0; font-size: 18px; color: #0f766e; }
  .muted { color: #64748b; } .right { text-align: right; } .small { font-size: 9px; }
  table { width: 100%; border-collapse: collapse; } th, td { padding: 5px 6px; vertical-align: top; }
  table.lines th { background: #f1f5f9; text-align: left; border-bottom: 1px solid #cbd5e1; }
  table.lines td { border-bottom: 1px solid #e2e8f0; }
  .box { border: 1px solid #e2e8f0; padding: 8px; border-radius: 4px; margin-bottom: 10px; }
  .rx { font-size: 22px; font-weight: bold; color: #0f766e; }
  .flag-high, .flag-low { color: #b45309; font-weight: bold; } .flag-critical { color: #b91c1c; font-weight: bold; }
  .foot { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 4px; }
</style></head><body>
<div class="head"><table><tr>
  <td><h1>{{ $org->name }}</h1><div class="muted">{{ $branch?->name }} @if(!empty($branch?->address['line1'])) · {{ $branch->address['line1'] }} @endif @if(!empty($branch?->address['city'])) · {{ $branch->address['city'] }} @endif</div>
      <div class="muted">{{ $branch?->phone ?? $org->phone }} @if($org->gstin) · GSTIN {{ $org->gstin }} @endif</div></td>
  <td class="right">@yield('title')</td>
</tr></table></div>
@yield('content')
<div class="foot">Generated {{ now()->setTimezone($branch?->timezone ?: 'Asia/Kolkata')->format('d M Y, h:i A') }} · {{ $org->name }} · Powered by EasyHealthcare</div>
</body></html>
