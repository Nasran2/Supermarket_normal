<?php

namespace App\Http\Requests;

use App\Services\ReportService;
use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = ReportService::permission((string) $this->route('report'));
        $format = $this->routeIs('reports.pdf') ? '.pdf' : ($this->routeIs('reports.export') ? '.export' : '');

        return $this->user()?->hasPermission($permission) && ($format === '' || $this->user()->hasPermission($permission.$format));
    }

    public function rules(): array
    {
        return ['return_type' => 'nullable|in:INVOICE,NO_RECEIPT', 'verification_status' => 'nullable|in:VERIFIED,PARTIALLY_VERIFIED,UNVERIFIED', 'approved_by' => 'nullable|integer', 'status' => 'nullable|in:DRAFT,COMPLETED,CANCELLED', 'reason' => 'nullable|string|max:1000', 'stock_action' => 'nullable|in:RESTOCK,WRITEOFF,SUPPLIER', 'resolution' => 'nullable|in:MONEY,SAME,OTHER', 'customer_id' => 'nullable|integer', 'supplier_id' => 'nullable|integer', 'stock_view' => 'nullable|in:summary,layers', 'stock_status' => 'nullable|in:available,depleted,all', 'product_id' => 'nullable|integer|exists:products,id', 'category_id' => 'nullable|integer|exists:categories,id', 'selling_price' => 'nullable|numeric|min:0', 'cost_price' => 'nullable|numeric|min:0', 'source_reference' => 'nullable|string|max:150', 'received_from' => 'nullable|date_format:Y-m-d', 'received_to' => 'nullable|date_format:Y-m-d', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'payment_method_id' => 'nullable|integer|exists:payment_methods,id', 'user_id' => 'nullable|integer|exists:users,id', 'rule_id' => 'nullable|integer|exists:payment_charge_rules,id', 'charge_bearer' => 'nullable|in:CUSTOMER,BUSINESS', 'q' => 'nullable|string|max:150'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['from' => $this->input('from', today()->startOfMonth()->toDateString()), 'to' => $this->input('to', today()->toDateString())]);
    }
}
