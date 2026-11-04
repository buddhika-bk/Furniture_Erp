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
<div class="footer">{{ $shop['name'] }} · {{ $document->number }} · {{ $quote ? 'Quotation — not a tax invoice or payment receipt' : 'Thank you for your business' }}</div>
<div class="header"><table class="meta"><tr><td width="60%"><h1>{{ $shop['name'] }}</h1><div>{{ $shop['branch'] ?? '' }}</div><div class="muted">{{ $shop['address'] ?? '' }}</div><div class="muted">{{ $shop['phone'] ?? '' }}</div></td><td class="right"><h2>{{ $quote ? 'QUOTATION' : 'INVOICE' }}</h2><p><b>{{ $document->number }}</b><br>{{ $document->created_at->format('d M Y') }}</p><span class="muted">Currency: {{ $shop['currency'] ?? 'LKR' }}</span></td></tr></table></div>
<table class="meta" style="margin-bottom:22px"><tr><td width="60%"><div class="label">{{ $quote ? 'Prepared for' : 'Bill to' }}</div><b>{{ $customer['name'] ?? '' }}</b><br>{{ $customer['phone'] ?? '' }}<br>{{ $customer['address'] ?? '' }}<br>{{ $customer['email'] ?? '' }}</td><td class="right">
@if($quote) Valid until: <b>{{ $document->valid_until }}</b>
@else @if($document->due_date)<div>Requested by: {{ $document->due_date }}</div>@endif<div>Payment: {{ ucfirst($document->payment_status) }}</div>@endif
</td></tr></table>
<table class="items"><thead><tr><th width="42%">Description</th><th class="right">Qty</th><th class="right">Unit price</th><th class="right">Discount</th><th class="right">Amount</th></tr></thead><tbody>
@foreach($document->items as $item)<tr><td><b>{{ $item->description ?? $item->product->name }}</b>@if($item->customer_description)<div style="white-space:pre-wrap">{{ $item->customer_description }}</div>@endif
@if($item->warranty_snapshot)<div class="muted">Warranty: {{ $item->warranty_snapshot['name'] }} · {{ $item->warranty_snapshot['duration_months'] }} months</div><div class="muted">{{ $item->warranty_snapshot['terms'] ?? '' }}</div>@endif</td><td class="right">{{ number_format($item->qty, 3) }}</td><td class="right">{{ number_format($item->unit_price, 2) }}</td><td class="right">{{ number_format(($item->discount_amount ?? 0)+($item->allocated_discount_amount ?? 0), 2) }}@if($item->discount_type === 'percentage')<br><span class="muted">Product: {{ number_format($item->discount_value, 2) }}%</span>@endif @if($item->allocated_discount_amount > 0)<br><span class="muted">Includes bill/loyalty share</span>@endif</td><td class="right">{{ number_format($item->line_total, 2) }}</td></tr>@endforeach
</tbody></table>
<table class="totals"><tr><td width="55%"></td><td>Subtotal</td><td class="right">{{ number_format($document->subtotal, 2) }}</td></tr>
@if(!$quote && ($document->discount + $document->loyalty_discount) > 0)<tr><td></td><td>Discount</td><td class="right">-{{ number_format($document->discount + $document->loyalty_discount, 2) }}</td></tr>@endif
@if(!$quote && $document->delivery_charge > 0)<tr><td></td><td>Delivery charge</td><td class="right">{{ number_format($document->delivery_charge,2) }}</td></tr>@endif
@if(!$quote)@foreach($document->service_charges ?? [] as $charge)<tr><td></td><td>{{ $charge['name'] }}</td><td class="right">{{ number_format($charge['amount'],2) }}</td></tr>@endforeach
@endif
<tr class="total"><td></td><td>Total ({{ $shop['currency'] ?? 'LKR' }})</td><td class="right">{{ number_format($document->total, 2) }}</td></tr>
@if(!$quote)<tr><td></td><td>Payments received</td><td class="right">{{ number_format($document->paid, 2) }}</td></tr><tr><td></td><td><b>Balance due</b></td><td class="right"><b>{{ number_format($document->is_order ? $document->balance_due : $document->credit_amount, 2) }}</b></td></tr>@endif</table>
@if(!$quote && $document->payments->count())<div class="label">Payment history</div><table class="items"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="right">Amount</th></tr></thead><tbody>@foreach($document->payments as $p)<tr><td>{{ $p->payment_date }}</td><td>{{ ['cash'=>'Cash','card'=>'Card','bank'=>'Bank transfer','koko'=>'Koko','other'=>'Other payment','credit'=>'Credit allocation'][$p->method] ?? $p->method }}{{ $p->direction === 'refund' ? ' (refund)' : '' }}</td><td>{{ $p->reference ?? '—' }}</td><td class="right">{{ number_format($p->amount, 2) }}</td></tr>@endforeach</tbody></table>@endif
@if($document->notes)<div class="note"><b>Notes / terms</b><br>{{ $document->notes }}</div>@endif
@if($quote)<p class="muted">This quotation does not reserve stock or record a payment. Availability and production lead times will be confirmed when your order is placed.</p>@endif
</body></html>
