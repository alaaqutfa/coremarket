<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Operational Customer Statement - {{ $customer->name }}</title>
    <style>
        @page { margin: {{ $template['margins_mm']['top'] }}mm {{ $template['margins_mm']['right'] }}mm {{ $template['margins_mm']['bottom'] }}mm {{ $template['margins_mm']['left'] }}mm; }
        body { color: #0f172a; font-family: dejavusans, sans-serif; font-size: {{ $template['settings']['font_size'] }}px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { padding: 6px; vertical-align: top; }
        .header { border-bottom: 3px solid {{ $branding['color'] }}; margin-bottom: 16px; padding-bottom: 10px; }
        .title { color: {{ $branding['color'] }}; font-size: 19px; font-weight: bold; text-align: right; }
        .muted { color: {{ $branding['secondary_color'] }}; }
        .entries th { background: {{ $branding['color'] }}; color: #fff; text-align: left; }
        .entries td, .totals td { border-bottom: 1px solid #e2e8f0; }
        .number { text-align: right; white-space: nowrap; }
        .totals { margin-left: 52%; width: 48%; }
        .footer { border-top: 1px solid #cbd5e1; color: #64748b; font-size: 8px; margin-top: 18px; padding-top: 8px; }
    </style>
</head>
<body>
    @php($settings = $template['settings'])
    <table class="header"><tr>
        <td width="55%" style="text-align: {{ $settings['logo_position'] }};">
            @if($settings['logo_enabled'] && $branding['logo_path'])
                <img src="{{ $branding['logo_path'] }}" style="max-height: 42px; max-width: 180px;">
            @elseif($settings['show_store_name'])
                <div style="font-size: 16px; font-weight: bold;">{{ $branding['store_name'] }}</div>
            @endif
            <div class="muted">{{ $branding['address'] }}</div>
        </td>
        <td width="45%" class="title">OPERATIONAL CUSTOMER STATEMENT</td>
    </tr></table>

    <table>
        <tr><td><strong>Customer</strong></td><td>{{ $customer->name }}</td><td><strong>Period</strong></td><td>{{ $dateFrom ?: 'Beginning' }} to {{ $dateTo ?: 'Present' }}</td></tr>
        <tr><td><strong>Email</strong></td><td>{{ $customer->email ?: '-' }}</td><td><strong>Phone</strong></td><td>{{ $customer->phone ?: '-' }}</td></tr>
        <tr><td><strong>Opening operational balance</strong></td><td>{{ coremarket_money($openingBalance) }}</td><td></td><td></td></tr>
    </table>

    <table class="entries" style="margin-top: 16px;">
        <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th>Charge</th><th>Credit</th><th>Balance</th></tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['date'] ?: '-' }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $row['entry_type'])) }}</td>
                    <td>{{ $row['reference'] }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td class="number">{{ coremarket_money($row['debit']) }}</td>
                    <td class="number">{{ coremarket_money($row['credit']) }}</td>
                    <td class="number">{{ coremarket_money($row['running_balance']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align: center;">No operational activity is available for this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals" style="margin-top: 14px;">
        <tr><td>Order charges</td><td class="number">{{ coremarket_money($totals['charges']) }}</td></tr>
        <tr><td>Recorded payments / returns</td><td class="number">{{ coremarket_money($totals['credits']) }}</td></tr>
        <tr><td><strong>Closing operational balance</strong></td><td class="number"><strong>{{ coremarket_money($totals['closingBalance']) }}</strong></td></tr>
    </table>

    <div class="footer">
        @if($settings['show_footer']){{ $settings['footer_text'] }}@endif
        This is an operational statement based on available orders, paid status, paid amounts, and completed sales returns. It is not an official accounts receivable ledger. COD cashbox settlements are not treated as customer payments here.
    </div>
</body>
</html>
