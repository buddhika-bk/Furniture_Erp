<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 38px 38px 52px; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #24324a; }
h1 { font-size: 26px; margin: 0 0 8px; color: #192844; } h2 { font-size: 19px; margin: 0; color: #5254be; }
.muted { color: #64748b; } .header { border-bottom: 3px solid #6264d9; padding-bottom: 20px; margin-bottom: 20px; }
table { width: 100%; border-collapse: collapse; } td,th { padding: 9px 7px; vertical-align: top; text-align: left; }
.meta td { padding: 4px 0; } .right { text-align: right; } .items th { background: #192844; color: white; font-size: 9px; }
.items td { border-bottom: 1px solid #e2e8f0; } .items tr:nth-child(even) { background: #f7f8fc; }
thead { display: table-header-group; } tr { page-break-inside: avoid; } .totals { margin: 18px 0; } .total { font-size: 14px; font-weight: bold; background: #eef0ff; }
.note { background: #f3f5fa; padding: 12px; margin: 15px 0; white-space: pre-wrap; overflow-wrap: break-word; } .label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; }
.footer { position: fixed; bottom: -32px; font-size: 8px; color: #64748b; width: 100%; border-top: 1px solid #e2e8f0; padding-top: 7px; }
</style></head><body>
<div class="footer">{{ $shop['name'] ?? 'Webpoint One' }} · {{ $document->number }} · Internal factory copy</div>
<div class="header"><h1>{{ $shop['name'] ?? 'Webpoint One' }}</h1><h2>FACTORY / BOM REQUEST</h2><p>Invoice: <b>{{ $document->number }}</b> · Order date: {{ $document->created_at->format('d M Y') }}</p><div>{{ $shop['branch'] ?? '' }} · {{ $shop['phone'] ?? '' }}</div>@if($document->due_date)<p>Requested delivery: <b>{{ $document->due_date }}</b></p>@endif</div>
<p>Only products requiring factory production are listed. Confirm the recipe before starting special production.</p>
@foreach($requests as $request)
@php($item=$document->items->firstWhere('id',$request->invoice_item_id))
<table class="items" style="margin-top:18px"><thead><tr><th>Request / product</th><th class="right">Quantity to manufacture</th></tr></thead><tbody><tr><td><b>{{ $request->number }}</b><br>{{ $item?->description ?? $request->product?->name }}<br><span class="muted">{{ $request->is_special ? 'SPECIAL SPECIFICATION' : 'STOCK SHORTAGE' }}</span></td><td class="right">{{ number_format($request->qty,3) }}</td></tr></tbody></table>
@if($item?->customer_description)<div class="note"><b>Product description</b><br>{{ $item->customer_description }}</div>@endif
<div class="note"><b>Factory instructions</b><br>{{ $request->notes ?: 'Standard product specification.' }}</div>
<table class="meta"><tr><td>Status: <b>{{ ucwords(str_replace('_',' ',$request->progress_status)) }}</b></td><td>Production order: {{ $request->productionOrder?->number ?? 'Awaiting BOM approval' }}</td></tr><tr><td>BOM version: {{ $request->productionOrder?->bom?->version ?? 'To be confirmed' }}</td><td>Factory: {{ $request->factoryWarehouse?->name ?? 'Pending assignment' }}</td></tr></table>
@endforeach
<table style="margin-top:35px"><tr><td>Prepared / checked: __________________</td><td>Factory approval: __________________</td></tr></table>
</body></html>