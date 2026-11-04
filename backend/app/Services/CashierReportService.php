<?php
namespace App\Services;

use App\Models\{CashierSession, Invoice, Payment, SalesReturn};
use App\Support\Settings;
use Carbon\CarbonImmutable;

class CashierReportService
{
    public function snapshot(CashierSession $session): array
    {
        $session->load('user','branch');
        $zone = 'Asia/Colombo';
        $end = CarbonImmutable::parse($session->closed_at, config('app.timezone'));
        $start = $end->setTimezone($zone)->startOfDay()->setTimezone(config('app.timezone'));
        $invoices = Invoice::with('items.product','customer')->where('created_by',$session->user_id)->where('branch_id',$session->branch_id)
            ->whereBetween('created_at',[$start,$end])->orderBy('id')->get();
        $payments = Payment::where('user_id',$session->user_id)->where('branch_id',$session->branch_id)
            ->whereBetween('created_at',[$start,$end])->orderBy('id')->get();
        $invoiceRefs = Invoice::whereIn('id',$payments->pluck('invoice_id')->filter())->pluck('number','id');
        $customers = \App\Models\Customer::whereIn('id',$payments->pluck('customer_id')->filter())->pluck('name','id');
        $returns = SalesReturn::with('items')->where('user_id',$session->user_id)->whereBetween('created_at',[$start,$end])
            ->whereIn('invoice_id',Invoice::where('branch_id',$session->branch_id)->select('id'))->orderBy('id')->get();
        $returnRefs = Invoice::whereIn('id',$returns->pluck('invoice_id'))->pluck('number','id');
        $time = fn ($v) => CarbonImmutable::parse($v,config('app.timezone'))->setTimezone($zone)->format('Y-m-d H:i:s');
        $methods=[];
        foreach (['cash'=>'Cash','card'=>'Card','bank'=>'Bank transfer','koko'=>'Koko','other'=>'Other payment','credit'=>'Credit (non-cash)'] as $key=>$label) {
            $rows=$payments->where('method',$key);
            $receipts=round((float)$rows->where('direction','receipt')->sum('amount'),2);
            $refunds=round((float)$rows->where('direction','refund')->sum('amount'),2);
            // Credit returns reduce receivables rather than creating a cash payment row.
            if ($key==='credit') $refunds=round((float)$returns->where('refund_method','credit')->sum('refund_amount'),2);
            $methods[]=['method'=>$key,'label'=>$label,'count'=>$rows->count()+($key==='credit'?$returns->where('refund_method','credit')->count():0),'receipts'=>$receipts,'refunds'=>$refunds,'net'=>round($receipts-$refunds,2)];
        }
        return [
            'version'=>1,'session_id'=>$session->id,'date'=>$end->setTimezone($zone)->toDateString(),'timezone'=>$zone,
            'cutoff'=>$time($end),'opened_at'=>$time($session->opened_at),'closed_at'=>$time($end),
            'cashier'=>$session->user->name,'cashier_email'=>$session->user->email,'closed_by'=>auth_user()?->name,
            'company'=>Settings::get('company_name',config('app.name')),'currency'=>Settings::get('currency','LKR'),
            'branch'=>$session->branch->name,'address'=>$session->branch->address,'phone'=>$session->branch->phone,
            'opening_cash'=>(float)$session->opening_cash,'expected_cash'=>(float)$session->expected_cash,
            'counted_cash'=>(float)$session->closing_cash,'variance'=>(float)$session->variance,'notes'=>$session->notes,
            'methods'=>$methods,'invoice_total'=>round((float)$invoices->sum('total'),2),
            'net_collected'=>round(collect($methods)->where('method','!=','credit')->sum('net'),2),
            'invoices'=>$invoices->map(fn ($i)=>['number'=>$i->number,'time'=>$time($i->created_at),'customer'=>$i->customer_snapshot['name']??$i->customer?->name??'Walk-in customer',
                'total'=>(float)$i->total,'paid'=>(float)$i->paid,'balance'=>(float)($i->is_order?$i->balance_due:$i->credit_amount),
                'discount'=>(float)$i->discount+(float)$i->loyalty_discount,'status'=>$i->payment_status,
                'items'=>$i->items->map(fn ($l)=>['name'=>$l->description??$l->product?->name,'qty'=>(float)$l->qty,'price'=>(float)$l->unit_price,'total'=>(float)$l->line_total])->all()])->all(),
            'payments'=>$payments->map(fn ($p)=>['id'=>$p->id,'payment_date'=>$p->payment_date,'time'=>$time($p->created_at),'invoice'=>$invoiceRefs[$p->invoice_id]??'Customer credit collection',
                'customer'=>$customers[$p->customer_id]??'','method'=>$p->method,'direction'=>$p->direction,'amount'=>(float)$p->amount,'reference'=>$p->reference])->all(),
            'returns'=>$returns->map(fn ($r)=>['number'=>$r->number,'invoice'=>$returnRefs[$r->invoice_id]??'','time'=>$time($r->created_at),'method'=>$r->refund_method,'amount'=>(float)$r->refund_amount,'reason'=>$r->reason])->all(),
        ];
    }
}
