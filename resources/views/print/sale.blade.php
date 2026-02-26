<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt #{{ $sale->reference ?? $sale->id }}</title>
    @php
        $next = $next ?? '/';
        $autoPrint = (bool) ($autoPrint ?? false);
        $currencyCode = $sale->store?->currency?->code ?? 'PKR';
    @endphp
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 24px; color: #111; }
        .receipt { max-width: 720px; margin: 0 auto; }
        .header { margin-bottom: 16px; }
        .muted { color: #666; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 6px; text-align: left; }
        th.num, td.num { text-align: right; }
        .totals { margin-top: 14px; display: flex; justify-content: flex-end; }
        .totals table { width: 320px; margin: 0; }
        .no-print { margin-top: 20px; display: flex; gap: 10px; }
        .btn { border: 1px solid #d1d5db; background: #fff; padding: 8px 12px; border-radius: 6px; text-decoration: none; color: #111; }
        .btn.primary { background: #111827; color: #fff; border-color: #111827; }
        @media print {
            .no-print { display: none; }
            body { padding: 6mm; }
        }
    </style>
</head>
<body>
<div class="receipt">
    <div class="header">
        <h2 style="margin: 0 0 8px;">{{ $sale->store?->name ?? config('app.name') }}</h2>
        <div class="muted">Receipt #{{ $sale->reference ?? $sale->id }}</div>
        <div class="muted">Date: {{ $sale->created_at?->setTimezone($sale->store?->timezone?->name ?? 'UTC')->format('M d, Y h:i A') }}</div>
        <div class="muted">Customer: {{ $sale->customer?->name ?? 'Guest' }}</div>
    </div>

    <table>
        <thead>
        <tr>
            <th>Description</th>
            <th class="num">Qty</th>
            <th class="num">Unit Price</th>
            <th class="num">Discount</th>
            <th class="num">Tax</th>
            <th class="num">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach(($groupedVariations ?? collect()) as $line)
            @php
                $description = $line['description']
                    ?? ($line['variation']?->description ?? 'Item');
            @endphp
            <tr>
                <td>{{ $description }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) ($line['quantity'] ?? 0), 6, '.', ','), '0'), '.') }}</td>
                <td class="num">{{ number_format((float) ($line['unit_price'] ?? 0), 2) }}</td>
                <td class="num">{{ number_format((float) ($line['line_discount'] ?? 0), 2) }}</td>
                <td class="num">{{ number_format((float) ($line['line_tax_total'] ?? 0), 2) }}</td>
                <td class="num">{{ number_format((float) ($line['line_total'] ?? 0), 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr>
                <th>Subtotal</th>
                <td class="num">{{ number_format((float) ($sale->subtotal ?? 0), 2) }} {{ $currencyCode }}</td>
            </tr>
            <tr>
                <th>Tax</th>
                <td class="num">{{ number_format((float) ($sale->tax ?? 0), 2) }} {{ $currencyCode }}</td>
            </tr>
            <tr>
                <th>Discount</th>
                <td class="num">{{ number_format((float) ($sale->discount ?? 0), 2) }} {{ $currencyCode }}</td>
            </tr>
            <tr>
                <th>Freight</th>
                <td class="num">{{ number_format((float) ($sale->freight_fare ?? 0), 2) }} {{ $currencyCode }}</td>
            </tr>
            <tr>
                <th>Total</th>
                <td class="num"><strong>{{ number_format((float) ($sale->total ?? 0), 2) }} {{ $currencyCode }}</strong></td>
            </tr>
        </table>
    </div>

    <div class="no-print">
        <a class="btn" href="{{ $next ?: '/' }}">Back</a>
        <button class="btn primary" type="button" onclick="window.print()">Print</button>
    </div>
</div>

<script>
    if (@json($autoPrint)) {
        window.addEventListener('load', () => {
            window.print();
        });
    }
</script>
</body>
</html>
