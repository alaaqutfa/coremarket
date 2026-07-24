<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Supplier Statement - {{ $supplier->name }}</title>
    <style>
        @page { margin: 24px; }
        body { color: #0f172a; font-family: dejavusans, sans-serif; font-size: 10px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { padding: 6px; vertical-align: top; }
        .header { border-bottom: 3px solid {{ $branding['color'] }}; margin-bottom: 16px; padding-bottom: 10px; }
        .title { color: {{ $branding['color'] }}; font-size: 20px; font-weight: bold; text-align: right; }
        .muted { color: #64748b; }
        .entries th { background: {{ $branding['color'] }}; color: #fff; font-size: 8px; text-align: left; }
        .entries td { border-bottom: 1px solid #e2e8f0; font-size: 8px; }
        .number { text-align: right; white-space: nowrap; }
        .summary { margin-left: 50%; width: 50%; }
        .summary td { border-bottom: 1px solid #e2e8f0; }
        .closing { color: {{ $branding['color'] }}; font-size: 12px; font-weight: bold; }
        .footer { border-top: 1px solid #cbd5e1; color: #64748b; font-size: 8px; margin-top: 18px; padding-top: 8px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td width="55%">
                @if($branding['logo_path'])
                    <img src="{{ $branding['logo_path'] }}" style="max-height: 42px; max-width: 180px;">
                @else
                    <div style="font-size: 16px; font-weight: bold;">{{ $branding['store_name'] }}</div>
                @endif
                <div class="muted">{{ $branding['address'] }}</div>
                <div class="muted">{{ $branding['email'] }}{{ $branding['email'] && $branding['phone'] ? ' | ' : '' }}{{ $branding['phone'] }}</div>
            </td>
            <td width="45%" class="title">SUPPLIER STATEMENT</td>
        </tr>
    </table>

    <table>
        <tr><td width="18%"><strong>Supplier</strong></td><td width="42%">{{ $supplier->company_name ?: $supplier->name }}</td><td width="16%"><strong>Date Range</strong></td><td width="24%">{{ $dateFrom ?: 'Beginning' }} to {{ $dateTo ?: 'Present' }}</td></tr>
        <tr><td><strong>Contact</strong></td><td>{{ $supplier->email ?: '-' }} / {{ $supplier->phone ?: '-' }}</td><td><strong>Opening Balance</strong></td><td>{{ coremarket_money($openingBalance, 'USD') }}</td></tr>
        <tr><td><strong>Address</strong></td><td>{{ $supplier->address ?: '-' }}</td><td><strong>Currency</strong></td><td>USD</td></tr>
    </table>

    <table class="entries" style="margin-top: 16px;">
        <thead>
            <tr>
                <th width="14%">Date</th>
                <th width="15%">Type</th>
                <th width="14%">Reference</th>
                <th width="25%">Description</th>
                <th width="10%">Debit</th>
                <th width="10%">Credit</th>
                <th width="12%">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $row['entry_type'])) }}</td>
                    <td>{{ $row['reference'] }}</td>
                    <td>{{ $row['description'] ?: '-' }}</td>
                    <td class="number">{{ $row['debit'] > 0 ? coremarket_money($row['debit'], 'USD') : '-' }}</td>
                    <td class="number">{{ $row['credit'] > 0 ? coremarket_money($row['credit'], 'USD') : '-' }}</td>
                    <td class="number">{{ coremarket_money($row['running_balance'], 'USD') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align: center;">No supplier ledger entries in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary" style="margin-top: 14px;">
        <tr><td>Purchase Credits</td><td class="number">{{ coremarket_money($totals['purchases'], 'USD') }}</td></tr>
        <tr><td>Payments</td><td class="number">{{ coremarket_money($totals['payments'], 'USD') }}</td></tr>
        <tr><td>Purchase Returns</td><td class="number">{{ coremarket_money($totals['returns'], 'USD') }}</td></tr>
        <tr><td>Total Credits</td><td class="number">{{ coremarket_money($totals['credits'], 'USD') }}</td></tr>
        <tr><td>Total Debits</td><td class="number">{{ coremarket_money($totals['debits'], 'USD') }}</td></tr>
        <tr class="closing"><td>Closing Balance</td><td class="number">{{ coremarket_money($totals['closingBalance'], 'USD') }}</td></tr>
    </table>

    <div class="footer">
        Balance is calculated from available supplier ledger entries only: credits minus debits.
        Opening balance includes recorded entries before the selected start date. No historical purchases are backfilled.
    </div>
</body>
</html>
