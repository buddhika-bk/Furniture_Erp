<?php
namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    private function filters(Request $r): array
    {
        $f = $r->only(['from', 'to', 'branch_id', 'warehouse_id', 'type']);
        if ($s = auth_user()->branchScope()) $f['branch_id'] = $s;      // branch users only see their own branch
        return $f;
    }

    public function dashboard(Request $r) { return response()->json($this->reports->dashboard($this->filters($r))); }

    public function show(Request $r, string $name)
    {
        $f = $this->filters($r);
        $data = match ($name) {
            'sales-by-day' => $this->reports->salesByDay($f),
            'sales-by-branch' => $this->reports->salesByBranch($f),
            'sales-by-product' => $this->reports->salesByProduct($f),
            'salesperson' => $this->reports->salespersonPerformance($f),
            'product-profitability' => $this->reports->productProfitability($f),
            'branch-profitability' => $this->reports->branchProfitability($f),
            'profit-and-loss' => $this->reports->profitAndLoss($f),
            'stock' => $this->reports->stock($f),
            'production' => $this->reports->production($f),
            'receivables' => $this->reports->receivables(),
            'payables' => $this->reports->payables(),
            'expenses' => $this->reports->expensesByCategory($f),
            'marketing' => $this->reports->marketing($f),
            'delivery-costs' => $this->reports->deliveryCosts($f),
            default => abort(404, 'Unknown report.'),
        };
        return response()->json(['report' => $name, 'filters' => $f, 'data' => $data]);
    }
}
