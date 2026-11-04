<?php
namespace App\Http\Controllers;

use App\Models\{Bom, Invoice, ManufacturingRequest, Product, Quotation, StockBalance, Warehouse};
use App\Services\SalesDocumentService;
use App\Support\Settings;
use Illuminate\Http\Request;

class SalesDocumentController extends Controller
{
    public function __construct(private SalesDocumentService $service) {}
    private function branch(Request $r): int {
        $scope = auth_user()->branchScope();
        $id = $scope ?: (int) $r->input('branch_id');
        abort_if(!$id, 422, 'Select a branch.');
        abort_if($scope && $r->filled('branch_id') && (int) $r->input('branch_id') !== $scope, 403);
        abort_unless(\App\Models\Branch::whereKey($id)->where('is_active', true)->exists(), 422, 'Select an active branch.');
        return $id;
    }
    private function document(string $type, int $id) {
        $model = $type === 'invoices' ? Invoice::class : Quotation::class;
        return $model::with('items.product', 'branch')->when(auth_user()->branchScope(), fn ($q, $b) => $q->where('branch_id', $b))->findOrFail($id);
    }
    public function catalog(Request $r) {
        $branch = $this->branch($r);
        $wh = Warehouse::where('branch_id', $branch)->where('type', 'showroom')->where('is_active', true)->first();
        $q = Product::with('category','warranty')->where('is_active', true);
        if (!$r->boolean('quotation')) $q->where('type', 'finished_good');
        if ($r->filled('search')) $q->where(fn ($w) => $w->where('name', 'like', '%'.$r->input('search').'%')->orWhere('sku', 'like', '%'.$r->input('search').'%'));
        if ($r->filled('category_id')) $q->where('category_id', $r->input('category_id'));
        $rows = $q->orderBy('name')->paginate(50);
        $stock = $wh ? StockBalance::where('warehouse_id', $wh->id)->whereIn('product_id', $rows->pluck('id'))->pluck('qty', 'product_id') : collect();
        $rows->setCollection($rows->getCollection()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'category' => $p->category?->name,
            'warranty'=>$p->warranty, 'loyalty_points'=>(int)$p->loyalty_points, 'price' => (float) $p->sale_price, 'stock' => (float) ($stock[$p->id] ?? 0)]));
        return response()->json(['currency' => Settings::get('currency', 'LKR'), 'products' => $rows, 'categories' => \App\Models\Category::orderBy('name')->get(['id','name'])]);
    }
    public function create(Request $r, string $type) {
        $quote = $type === 'quotations';
        $rules = ['branch_id' => 'nullable|exists:branches,id', 'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => ($quote?'required_without:customer_id|':'').'nullable|string|max:255', 'customer_phone' => 'nullable|string|max:40', 'customer_address' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:5000', 'items' => 'required|array|min:1|max:200',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id', 'items.*.qty' => 'required|numeric|min:0.001|max:1000000|decimal:0,3'];
        if ($quote) $rules['valid_until'] = 'required|date|after_or_equal:today';
        else $rules += ['is_special'=>'prohibited',
            'items.*.unit_price'=>'sometimes|numeric|min:0|max:999999999|decimal:0,2',
            'items.*.discount_type'=>'sometimes|in:fixed,percentage', 'items.*.discount_value'=>'sometimes|numeric|min:0|max:999999999|decimal:0,2',
            'items.*.customer_description'=>'nullable|string|max:2000','items.*.special_bom'=>'sometimes|boolean','items.*.manufacturing_notes'=>'nullable|string|max:5000',
            'delivery_charge'=>'sometimes|numeric|min:0|max:999999999|decimal:0,2', 'service_charges'=>'sometimes|array|max:30',
            'service_charges.*.name'=>'required|string|max:120','service_charges.*.amount'=>'required|numeric|min:0|max:999999999|decimal:0,2', 'due_date' => 'nullable|date|after_or_equal:today', 'payments' => 'present|array|max:20',
            'payments.*.method' => 'required|in:cash,card,bank,koko,other', 'payments.*.amount' => 'required|numeric|min:0.01|max:999999999|decimal:0,2', 'payments.*.reference' => 'nullable|string|max:255'];
        $d = $r->validate($rules);
        if (!$quote) foreach ($d['items'] as $i=>$item) {
            if (!empty($item['special_bom']) && !trim($item['manufacturing_notes']??'')) throw \Illuminate\Validation\ValidationException::withMessages(["items.$i.manufacturing_notes"=>['Describe the special factory requirements.']]);
        }
        $d['branch_id'] = $this->branch($r);
        return response()->json(['data' => $quote ? $this->service->quotation($d) : $this->service->invoice($d)], 201);
    }
    public function customer(Request $r) {
        $d=$r->validate(['credit_limit'=>'required|numeric|min:0|max:999999999|decimal:0,2','name'=>'required|string|max:255','phone'=>'nullable|string|max:40','email'=>'nullable|email|max:255','address'=>'nullable|string|max:1000','notes'=>'nullable|string|max:2000']);
        $customer=\Illuminate\Support\Facades\DB::transaction(fn()=>\App\Models\Customer::create($d+['code'=>\App\Support\DocNumber::next('CUS'),'status'=>'active','credit_limit'=>0]));
        return response()->json(['data'=>$customer],201);
    }
    public function customerCredit(int $id) {
        $customer=\App\Models\Customer::findOrFail($id);
        return response()->json(['data'=>['id'=>$customer->id,'credit_limit'=>(float)$customer->credit_limit]]);
    }
    public function factoryPdf(int $id) {
        abort_unless(auth_user()->hasPermission('sales.view') || auth_user()->hasPermission('manufacturing.view'),403);
        $document=Invoice::with('branch','items.product','manufacturingRequests.product','manufacturingRequests.productionOrder.bom')->findOrFail($id);
        $requests=$document->manufacturingRequests;
        if ($branch=auth_user()->branchScope()) {
            if (!(auth_user()->hasPermission('sales.view') && $document->branch_id==$branch)) {
                abort_unless(auth_user()->hasPermission('manufacturing.view'),404);
                $requests=$requests->filter(fn($r)=>!$r->factory_warehouse_id || $r->factoryWarehouse->branch_id==$branch);
            }
        }
        abort_if($requests->isEmpty(),404,'No factory requests for this invoice.');
        $shop=$document->shop_snapshot;
        $pdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'defaultFont'=>'DejaVu Sans']);
        $pdf->loadHtml(view('factory-request',compact('document','requests','shop'))->render());$pdf->setPaper('A4');$pdf->render();
        return response($pdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$document->number.'-factory.pdf"','Cache-Control'=>'private, no-store']);
    }
    public function printPack(int $id) {
        $document=$this->document('invoices',$id)->load('payments','manufacturingRequests.product','manufacturingRequests.productionOrder.bom');
        $quote=false;
        $shop=$document->shop_snapshot ?? ['name'=>Settings::get('company_name',config('app.name')),'branch'=>$document->branch->name,'currency'=>Settings::get('currency','LKR')];
        $customer=$document->customer_snapshot ?? $document->customer?->only(['name','phone','address']) ?? ['name'=>'Walk-in customer'];
        $html=view('sales-document',compact('document','quote','shop','customer'))->render();
        $requests=$document->manufacturingRequests;
        if($requests->isNotEmpty()) {
            $factory=view('factory-request',compact('document','requests','shop'))->render();
            $body=explode('</body>',explode('<body>',$factory,2)[1],2)[0];
            $html=str_replace('</body>', '<section style="page-break-before:always">'.$body.'</section></body>',$html);
        }
        return response(view('sales-print', ['content'=>$html])->render())->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','private, no-store');
    }
    public function payment(Request $r, int $id) {
        $d = $r->validate(['payment_date'=>'sometimes|required|date_format:Y-m-d|before_or_equal:'.now('Asia/Colombo')->toDateString(), 'method' => 'required|in:cash,card,bank,koko,other', 'amount' => 'required|numeric|min:0.01|max:999999999|decimal:0,2', 'reference' => 'nullable|string|max:255']);
        return response()->json(['data' => $this->service->recordPayment($this->document('invoices', $id), $d)]);
    }
    public function requests(Request $r) {
        $q = ManufacturingRequest::with('product','invoice','productionOrder.bom');
        if ($branch = auth_user()->branchScope()) $q->where(fn ($w) => $w->whereHas('factoryWarehouse', fn ($a) => $a->where('branch_id', $branch))->orWhereNull('factory_warehouse_id'));
        if ($r->filled('status')) {
            $status = $r->validate(['status' => 'in:awaiting_bom,queued,ready,start,process,completed'])['status'];
            $mapped = ['ready' => 'draft', 'start' => 'released', 'process' => 'in_progress', 'completed' => 'completed'];
            if (isset($mapped[$status])) $q->whereHas('productionOrder', fn ($o) => $o->where('status', $mapped[$status]));
            else $q->where('status', $status);
        }
        return response()->json($q->latest('id')->paginate(20));
    }
    public function approve(Request $r, int $id) {
        $d = $r->validate(['bom_id' => 'required|exists:boms,id', 'factory_warehouse_id' => 'required|exists:warehouses,id']);
        $request = ManufacturingRequest::findOrFail($id);
        $warehouse = $this->scoped('warehouses', $d['factory_warehouse_id']);
        if ($branch = auth_user()->branchScope()) abort_if($request->factory_warehouse_id && $request->factoryWarehouse->branch_id != $branch, 403);
        return response()->json(['data' => $this->service->queue($request, Bom::findOrFail($d['bom_id']), $warehouse)]);
    }
    public function pdf(string $type, int $id) {
        $document = $this->document($type, $id);
        $quote = $type === 'quotations';
        if (!$quote) $document->load('payments', 'manufacturingRequests.productionOrder', 'manufacturingRequests.product');
        $shop = $document->shop_snapshot ?? ['name' => Settings::get('company_name', config('app.name')), 'branch' => $document->branch->name, 'address' => $document->branch->address, 'phone' => $document->branch->phone, 'currency' => Settings::get('currency', 'LKR')];
        $customer = $document->customer_snapshot ?? $document->customer?->only(['name','phone','address']) ?? ['name' => 'Walk-in customer'];
        $pdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml(view('sales-document', compact('document','quote','shop','customer'))->render());
        $pdf->setPaper('A4'); $pdf->render();
        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$document->number.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }
}
