@if (count($product_ids) > 0)
    <table class="table table-bordered aiz-table">
        <thead>
            <tr>
                <td width="30%">
                    <span>{{ translate('Product') }}</span>
                </td>
                <td data-breakpoints="lg">
                    <span>{{ translate('Cost Price') }}</span>
                </td>
                <td data-breakpoints="lg">
                    <span>{{ translate('Price') }}</span>
                </td>
                <td data-breakpoints="lg">
                    <span>{{ translate('Profit Amount') }}</span>
                </td>
                <td data-breakpoints="lg" width="20%">
                    <span>{{ translate('Discount') }}</span>
                </td>
                <td data-breakpoints="lg" width="20%">
                    <span>{{ translate('Discount Type') }}</span>
                </td>
                <td data-breakpoints="lg">
                    <span>{{ translate('Price after discount') }}</span>
                </td>
                <td data-breakpoints="lg">
                    <span>{{ translate('Profit after discount') }}</span>
                </td>
            </tr>
        </thead>
        <tbody>
            @foreach ($product_ids as $key => $id)
                @php
                    $product = \App\Models\Product::findOrFail($id);
                    $flash_deal_product = \App\Models\FlashDealProduct::where('flash_deal_id', $flash_deal_id)
                        ->where('product_id', $product->id)
                        ->first();
                @endphp
                <tr>
                    <td>
                        <div class="form-group row">
                            <div class="col-auto">
                                <img src="{{ uploaded_asset($product->thumbnail_img) }}" class="size-60px img-fit">
                            </div>
                            <div class="col">
                                <span>{{ $product->getTranslation('name') }}</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span>{{ $product->wholesale_price }}</span>
                    </td>
                    <td>
                        <span>{{ $product->unit_price }}</span>
                    </td>
                    <td>
                        <span>{{ $product->unit_price - $product->wholesale_price }}</span>
                    </td>
                    <td>
                        <input type="number" lang="en" name="discount_{{ $id }}"
                            value="{{ $product->discount }}" data-id="{{ $id }}"
                            data-unit-price="{{ $product->unit_price }}"
                            data-wholesale-price="{{ $product->wholesale_price }}"
                            data-discount-type="{{ $product->discount_type }}" min="0" step="1"
                            class="form-control flash_discount_field" required>
                    </td>
                    <td>
                        <select class="aiz-selectpicker" name="discount_type_{{ $id }}">
                            <option value="amount" <?php if ($product->discount_type == 'amount') {
                                echo 'selected';
                            } ?>>{{ translate('Flat') }}</option>
                            <option value="percent" <?php if ($product->discount_type == 'percent') {
                                echo 'selected';
                            } ?>>{{ translate('Percent') }}</option>
                        </select>
                    </td>
                    <td>
                        @if ($product->discount_type == 'percent')
                            @php($priceAfterDiscount = ($product->unit_price * $product->discount) / 100)
                        @else
                            @php($priceAfterDiscount = $product->unit_price - $product->discount)
                        @endif
                        <span class="price_after_discount_{{ $id }}">{{ $priceAfterDiscount }}</span>
                    </td>
                    <td>
                        @php($profitAfterDiscount = $priceAfterDiscount - $product->wholesale_price)
                        <span
                            class="profit_after_discount_{{ $id }} {{ $profitAfterDiscount > 0 ? 'text-success' : 'text-danger' }}">{{ $profitAfterDiscount }}</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
