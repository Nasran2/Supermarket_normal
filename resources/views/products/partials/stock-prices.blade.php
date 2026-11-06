<div class="stock-pricing-summary">
<div class="card"><span>Current stock</span><strong>{{ \App\Support\Units::quantity((float)$record->stock, $record->unit->short_name) }}</strong></div>
@if(!\App\Support\SecretControl::isHidden('hide_stock_counts'))<div class="card"><span>Available selling prices</span><strong>{{ $pricing->prices??0 }}</strong></div>@endif
@can('products.view_cost')
@if(!\App\Support\SecretControl::isHidden('hide_stock_money') && !\App\Support\SecretControl::isHidden('hide_exact_purchase_cost') && !\App\Support\SecretControl::isHidden('hide_all_price_data'))
<div class="card"><span>Stock value at cost</span><strong>{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::formatRaw($pricing->cost_value??0) }}</strong></div>
@endif
@endcan
@if(!\App\Support\SecretControl::isHidden('hide_sales_value') && !\App\Support\SecretControl::isHidden('hide_exact_selling_price') && !\App\Support\SecretControl::isHidden('hide_all_price_data'))<div class="card"><span>Potential sales value</span><strong>{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::formatRaw($pricing->selling_value??0) }}</strong></div>@endif
</div>
<section class="card stock-pricing-section" id="stock-prices">
<div class="customer-section-heading"><span class="customer-section-icon"><x-icon name="tags"/></span><div><h2>Stock by price</h2><p>{{ auth()->user()->hasPermission('products.view_cost')?'Available quantities and the delivery they came from.':'Available selling prices and quantities.' }}</p></div></div>
@if(auth()->user()->hasPermission('products.view_cost') || auth()->user()->hasPermission('products.manage_prices'))
<div class="stock-price-filter">@foreach(['available'=>'In stock','depleted'=>'Depleted','all'=>'All stock'] as $key=>$label)<a class="{{ $stockStatus===$key?'selected':'' }}" href="{{ route('manage.show',['products',$record->id]) }}?stock_status={{ $key }}#stock-prices">{{ $label }}</a>@endforeach</div>
<div class="table-wrap"><table><thead><tr>
@if(!\App\Support\SecretControl::isHidden('hide_exact_selling_price') && !\App\Support\SecretControl::isHidden('hide_all_price_data'))
<th>Selling price</th>
@endif
@can('products.view_cost')
@if(!\App\Support\SecretControl::isHidden('hide_stock_money') && !\App\Support\SecretControl::isHidden('hide_exact_purchase_cost') && !\App\Support\SecretControl::isHidden('hide_all_price_data'))
<th>Cost price</th>
@endif
@endcan
<th>Available</th>
@can('products.view_cost')
@if(!\App\Support\SecretControl::isHidden('hide_profit'))
<th>Margin @if(\App\Support\Units::enabled())/ unit @endif</th>
@endif
@endcan
<th>Source</th><th>Received</th>@can('products.manage_prices')<th>Manage stock prices</th>@endcan</tr></thead><tbody>
@forelse($layers as $layer)
<tr class="stock-price-row">
@if(!\App\Support\SecretControl::isHidden('hide_exact_selling_price') && !\App\Support\SecretControl::isHidden('hide_all_price_data'))
<td><strong>@if(\App\Support\SecretControl::isSellingPriceHiddenFor($layer->selling_price))—@else{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::displayPrice($layer->selling_price) }}@endif</strong></td>
@endif
@can('products.view_cost')
@if(!\App\Support\SecretControl::isHidden('hide_stock_money') && !\App\Support\SecretControl::isHidden('hide_exact_purchase_cost') && !\App\Support\SecretControl::isHidden('hide_all_price_data'))
<td>@if(\App\Support\SecretControl::isCostHiddenFor($layer->cost_price))—@else{{ \App\Support\Money::displayCost($layer->cost_price) }}@endif</td>
@endif
@endcan
<td><strong>{{ \App\Support\Units::quantity((float)$layer->remaining_quantity, $record->unit->short_name) }}</strong><small>Originally {{ \App\Support\Units::quantity((float)$layer->original_quantity, $record->unit->short_name) }}</small></td>
@can('products.view_cost')
@if(!\App\Support\SecretControl::isHidden('hide_profit'))
@php($scaledSell = \App\Support\SecretControl::scalePrice($layer->selling_price))
@php($scaledCost = \App\Support\SecretControl::scaleCost($layer->cost_price))
@php($scaledMargin = $scaledSell - $scaledCost)
<td class="{{ $scaledMargin < 0 ? 'text-warning' : '' }}">@if(\App\Support\SecretControl::isCostHiddenFor($layer->cost_price) || \App\Support\SecretControl::isSellingPriceHiddenFor($layer->selling_price))—@else{{ \App\Support\Money::formatRaw($scaledMargin) }}@endif</td>
@endif
@endcan<td>{{ \Illuminate\Support\Str::headline(strtolower($layer->source_type)) }}<small>{{ $layer->source_reference }}</small></td><td>{{ $layer->received_at->format($settings['date_format']??'d/m/Y') }}</td>@can('products.manage_prices')<td>@if(\App\Support\Money::compare($layer->remaining_quantity,0)>0)<details><summary class="text-link">Change selling price</summary><form method="POST" action="{{ route('stock-prices.update',$layer) }}" data-confirm="Change the selling price for the remaining {{ \App\Support\Units::quantity((float)$layer->remaining_quantity, $record->unit->short_name) }}? Historical sales stay unchanged.">@csrf @method('PUT')<input type="hidden" name="confirmed" value="1"><input type="number" name="selling_price" step="0.01" min="0" max="999999999" value="{{ $layer->selling_price }}" aria-label="New selling price for stock row {{ $layer->id }}" required><button class="btn primary small">Save price</button></form></details>@else<span class="badge slate">Depleted</span>@endif</td>@endcan</tr>
@empty<tr><td colspan="7" class="empty-cell">No stock rows match this view.</td></tr>@endforelse
</tbody></table></div>
@else
<div class="table-wrap"><table><thead><tr>
@if(!\App\Support\SecretControl::isHidden('hide_exact_selling_price') && !\App\Support\SecretControl::isHidden('hide_all_price_data'))
<th>Selling price</th>
@endif
<th>Available</th></tr></thead><tbody>
@forelse($priceGroups as $group)<tr>
@if(!\App\Support\SecretControl::isHidden('hide_exact_selling_price') && !\App\Support\SecretControl::isHidden('hide_all_price_data'))
<td><strong>@if(\App\Support\SecretControl::isSellingPriceHiddenFor($group['stock_price']))—@else{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::displayPrice($group['stock_price']) }}@endif</strong></td>
@endif
<td>{{ \App\Support\Units::quantity((float)$group['quantity'], $record->unit->short_name) }}</td></tr>@empty<tr><td colspan="2" class="empty-cell">No available stock.</td></tr>@endforelse
</tbody></table></div>
@endif
</section>
