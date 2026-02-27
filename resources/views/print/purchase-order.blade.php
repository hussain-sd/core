<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order #{{ $purchaseOrder->reference }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    
    @php
        // Navigation URL
        $next = $next ?? request('next') ?? '/';
        $next = ($next !== '/' && $next !== null) ? urldecode($next) : $next;

        $currency = $purchaseOrder->store?->currency;
        $currencyCode = $currency?->code ?? 'PKR';
        $currencyDecimals = $currency?->decimal_places ?? 2;

        $percentDecimals = 6;

        // Number formatting functions
        $fmt = function ($number) use ($currencyDecimals) {
            $value = (float) $number;
            $formatted = number_format($value, $currencyDecimals, '.', ',');
            return rtrim(rtrim($formatted, '0'), '.') ?: '0';
        };

        $fmtPercent = function ($number) use ($percentDecimals) {
            $value = (float) $number;
            $formatted = number_format($value, $percentDecimals, '.', ',');
            return rtrim(rtrim($formatted, '0'), '.') ?: '0';
        };
        
        $fmtNoRound = function ($number) use ($currencyDecimals) {
            return number_format((float) $number, $currencyDecimals, '.', ',');
        };

        // Store-local order date/time
        $purchaseOrderDate = ($purchaseOrder->store && $purchaseOrder->created_at)
            ? $purchaseOrder->created_at->setTimezone($purchaseOrder->store->timezone?->name ?? 'UTC')
            : $purchaseOrder->created_at;
    @endphp

    @vite(['resources/css/app.css'])
    
    <style>
        /* Print-specific styles - A4 Landscape */
        @media print {
            @page {
                size: A4 landscape;
                margin: 8mm 5mm 18mm 5mm;
            }
            
            .no-print {
                display: none !important;
            }

            /* Remove shadows and rounded corners for print */
            * {
                box-shadow: none !important;
                text-shadow: none !important;
                border-radius: 0 !important;
            }
            
            /* Supplier info box - compact spacing */
            .supplier-box {
                background: white !important;
                border: none !important;
                padding: 2px 0 !important;
                margin-bottom: 2px !important;
            }
            
            /* Totals box - compact spacing */
            .totals-box {
                background: white !important;
                border: none !important;
                padding: 2px 0 !important;
            }

            
            /* Final total - compact separator */
            .summary-final-total {
                border-top: 1px solid #ccc !important;
                background: white !important;
                font-weight: bold !important;
                padding-top: 4px !important;
                margin-top: 4px !important;
            }
            
            /* Table header - light background, compact */
            .receipt-table thead tr {
                background: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                color: #000 !important;
            }
            
            /* Compact table cells */
            .receipt-table th,
            .receipt-table td {
                padding: 2px 2px !important;
            }
            
            /* Remove horizontal margins from body and containers */
            body {
                margin-left: 0 !important;
                margin-right: 0 !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
                width: 100% !important;
            }
            
            .print-container {
                max-width: 100% !important;
                width: 100% !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
            
            /* Ensure table uses full width */
            .receipt-table {
                width: 100% !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            
            /* Soft separators between table rows */
            .receipt-table tbody tr {
                border-bottom: 1px solid #e2e8f0 !important;
            }
            
            /* Remove border from last row */
            .receipt-table tbody tr:last-child {
                border-bottom: none !important;
            }

            /* Remove light backgrounds that don't print well */
            .bg-slate-50,
            .bg-blue-50,
            .bg-red-50 {
                background: white !important;
            }
            
            /* Ensure text is dark and readable */
            body {
                color: #000 !important;
            }
            
            .text-slate-600,
            .text-slate-700 {
                color: #000 !important;
            }
            
            .text-blue-600,
            .text-blue-700 {
                color: #000 !important;
            }
            
            /* Remove all borders for clean print */
            .border-slate-200,
            .border-slate-300,
            .border-blue-300,
            .border-red-300 {
                border: none !important;
            }
            
            /* Page break controls */
            .summary-totals {
                page-break-inside: avoid;
            }

            .summary-final-total {
                page-break-inside: avoid;
            }
            
            /* Allow table rows to break across pages */
            .receipt-table tbody tr {
                page-break-inside: auto;
            }
            
            /* Keep last 3 rows together */
            .receipt-table tbody tr:nth-last-child(-n+3) {
                page-break-inside: avoid;
            }
            
            /* Table header repeat */
            .receipt-table thead {
                display: table-header-group;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body class="p-0 font-sans text-slate-800 bg-white antialiased text-sm">
    
    <!-- Print Controls (Hidden when printing) -->
    <div class="no-print flex justify-center items-center gap-3 mb-4 flex-wrap bg-slate-50 p-3 rounded-lg border border-slate-200">
        <button type="button" onclick="prepareAndPrint()" class="px-5 py-2 rounded-md bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 shadow-sm hover:shadow-md transition-all">
            Print Order
        </button>
    </div>

    <!-- Receipt Container -->
    <div class="print-container">
        
        <!-- Header Section: Store Info & Order Meta -->
        <div class="flex justify-between items-start gap-1 mb-0.5">
            <!-- Store Information -->
            @if($purchaseOrder->store)
                <div class="flex-[1.2] min-w-0">
                    <div class="text-xl font-bold text-slate-900 mb-0 leading-tight tracking-tight">{{ $purchaseOrder->store->name }}</div>
                    @if($purchaseOrder->store->address)
                        <div class="text-xs text-slate-600 leading-tight mb-0">{{ $purchaseOrder->store->address }}</div>
                    @endif
                    <div class="text-xs text-slate-600 leading-tight">
                        @if($purchaseOrder->store->phone)
                            <span class="mr-2">
                                <span class="text-slate-500">Phone:</span>
                                <span class="ml-1">{{ $purchaseOrder->store->phone }}</span>
                            </span>
                        @endif
                        @if($purchaseOrder->store->email)
                            <span>
                                <span class="text-slate-500">Email:</span>
                                <span class="ml-1 whitespace-nowrap">{{ $purchaseOrder->store->email }}</span>
                            </span>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Order Meta Information -->
            <div class="flex-[0.8] text-right shrink-0">
                <div class="text-lg font-light text-slate-400 mb-0 tracking-wider uppercase">Purchase Order</div>
                <div class="text-xs text-slate-700">
                    <div class="text-right">
                        <span class="text-slate-500">Reference:</span>
                        <span class="ml-1 font-semibold text-slate-900">#{{ $purchaseOrder->reference }}</span>
                    </div>
                    <div class="text-right">
                        <span class="text-slate-500">Date:</span>
                        <span class="ml-1">{{ optional($purchaseOrderDate)->format('d-m-Y h:i A') }}</span>
                    </div>
                    <div class="text-right">
                        <span class="text-slate-500">Status:</span>
                        <span class="ml-1 font-medium">{{ $purchaseOrder->status?->name ?? 'Pending' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Supplier Information Section -->
        @if($purchaseOrder->supplier)
            <div class="supplier-box mb-0.5">
                <div class="text-xs font-medium text-slate-500 mb-0 uppercase tracking-wider">Supplier</div>
                <div class="text-xs text-slate-700 leading-tight">
                    <div class="font-bold text-slate-900 text-sm">{{ $purchaseOrder->supplier->name }}</div>
                    @if($purchaseOrder->supplier->phone)
                        <span class="mr-2">
                            <span class="text-slate-500">Phone:</span>
                            <span class="ml-1">{{ $purchaseOrder->supplier->phone }}</span>
                        </span>
                    @endif
                    @if($purchaseOrder->supplier->email)
                        <span class="mr-2">
                            <span class="text-slate-500">Email:</span>
                            <span class="ml-1">{{ $purchaseOrder->supplier->email }}</span>
                        </span>
                    @endif
                    @if($purchaseOrder->supplier->address)
                        <span>
                            <span class="text-slate-500">Address:</span>
                            <span class="ml-1">{{ $purchaseOrder->supplier->address }}</span>
                        </span>
                    @endif
                </div>
            </div>
        @endif

        <!-- Items Table -->
        <table class="receipt-table w-full border-collapse mb-0.5 mt-1 text-xs">
            <thead>
                <tr class="bg-slate-50 text-slate-700">
                    <th class="px-1 py-1 text-left font-semibold uppercase tracking-wider text-xs" rowspan="2">Description</th>
                    <th class="px-1 py-1 text-center font-semibold uppercase tracking-wider text-xs border-b border-slate-300" colspan="5">Requested</th>
                    <th class="px-1 py-1 text-center font-semibold uppercase tracking-wider text-xs border-b border-slate-300" colspan="5">Received</th>
                </tr>
                <tr class="bg-slate-50 text-slate-700">
                    <th class="px-1 py-1 text-right font-semibold uppercase tracking-wider text-xs">Qty</th>
                    <th class="px-1 py-1 text-center font-semibold uppercase tracking-wider text-xs">Unit Price</th>
                    <th class="px-1 py-1 text-center font-semibold uppercase tracking-wider text-xs">Supplier %</th>
                    <th class="px-1 py-1 text-right font-semibold uppercase tracking-wider text-xs">Supplier Price</th>
                    <th class="px-1 py-1 text-right font-semibold uppercase tracking-wider text-xs">Line Total</th>
                    <th class="px-1 py-1 text-right font-semibold uppercase tracking-wider text-xs">Qty</th>
                    <th class="px-1 py-1 text-center font-semibold uppercase tracking-wider text-xs">Unit Price</th>
                    <th class="px-1 py-1 text-center font-semibold uppercase tracking-wider text-xs">Supplier %</th>
                    <th class="px-1 py-1 text-right font-semibold uppercase tracking-wider text-xs">Supplier Price</th>
                    <th class="px-1 py-1 text-right font-semibold uppercase tracking-wider text-xs">Line Total</th>
                </tr>
            </thead>
            <tbody>
                @php 
                    $requestedTotalQty = 0;
                    $requestedSubtotal = 0; 
                    $requestedSupplierTotal = 0;
                    $receivedTotalQty = 0;
                    $receivedSubtotal = 0; 
                    $receivedSupplierTotal = 0; 
                @endphp
                
                @foreach($purchaseOrder->variations as $variation)
                    @php
                        $pivot = $variation->pivot;
                        // Requested values
                        $reqQty = (float) ($pivot->requested_quantity ?? 0);
                        $reqUnitPrice = (float) ($pivot->requested_unit_price ?? 0);
                        $reqSupplierPercentage = (float) ($pivot->requested_supplier_percentage ?? 0);
                        $reqSupplierIsPercentage = $pivot->requested_supplier_is_percentage;
                        $reqSupplierPrice = (float) ($pivot->requested_supplier_price ?? 0);
                        $reqLineTotal = $reqQty * $reqUnitPrice;
                        $reqLineSupplierTotal = $reqQty * $reqSupplierPrice;
                        $requestedTotalQty += $reqQty;
                        $requestedSubtotal += $reqLineTotal;
                        $requestedSupplierTotal += $reqLineSupplierTotal;
                        $requestedSymbol = $pivot->requestedUnit?->symbol ?? $variation->unit?->symbol;
                        
                        // Received values
                        $recQty = (float) ($pivot->received_quantity ?? 0);
                        $recUnitPrice = (float) ($pivot->received_unit_price ?? 0);
                        $recSupplierPercentage = (float) ($pivot->received_supplier_percentage ?? 0);
                        $recSupplierIsPercentage = $pivot->received_supplier_is_percentage;
                        $recSupplierPrice = (float) ($pivot->received_supplier_price ?? 0);
                        $recLineTotal = $recQty * $recUnitPrice;
                        $recLineSupplierTotal = $recQty * $recSupplierPrice;
                        $receivedTotalQty += $recQty;
                        $receivedSubtotal += $recLineTotal;
                        $receivedSupplierTotal += $recLineSupplierTotal;
                        $receivedSymbol = $pivot->receivedUnit?->symbol ?? $variation->unit?->symbol;
                    @endphp
                    
                    <tr class="border-b border-slate-200 hover:bg-slate-50 transition-colors">
                        <td class="px-1 py-0.5 align-top">
                            <div class="font-medium text-slate-900 text-xs">{{ $pivot->description ?? ($variation->sku.' - '.$variation->description) }}</div>
                        </td>
                        <!-- Requested Columns -->
                        <td class="px-1 py-0.5 text-right align-top font-medium text-slate-800 text-xs">{{ $fmt($reqQty) }}{{ $requestedSymbol ? ' '.$requestedSymbol : '' }}</td>
                        <td class="px-1 py-0.5 text-center align-top text-slate-700 text-xs">{{ $fmt($reqUnitPrice) }} {{ $currencyCode }}</td>
                        <td class="px-1 py-0.5 text-center align-top text-slate-600 text-xs">{{ $reqSupplierIsPercentage === false ? '—' : $fmtPercent($reqSupplierPercentage).'%' }}</td>
                        <td class="px-1 py-0.5 text-right align-top text-slate-700 text-xs">{{ $fmt($reqSupplierPrice) }} {{ $currencyCode }}</td>
                        <td class="px-1 py-0.5 text-right align-top font-semibold text-slate-900 text-xs">{{ $fmtNoRound($reqLineTotal) }} {{ $currencyCode }}</td>
                        <!-- Received Columns -->
                        <td class="px-1 py-0.5 text-right align-top font-medium text-slate-800 text-xs {{ $recQty > 0 ? 'text-green-600' : '' }}">{{ $fmt($recQty) }}{{ $receivedSymbol ? ' '.$receivedSymbol : '' }}</td>
                        <td class="px-1 py-0.5 text-center align-top text-slate-700 text-xs {{ $recUnitPrice > 0 ? 'text-green-600' : '' }}">{{ $recUnitPrice > 0 ? $fmt($recUnitPrice).' '.$currencyCode : '—' }}</td>
                        <td class="px-1 py-0.5 text-center align-top text-slate-600 text-xs {{ $recSupplierPercentage > 0 ? 'text-green-600' : '' }}">{{ $recSupplierIsPercentage === false ? '—' : ($recSupplierPercentage > 0 ? $fmtPercent($recSupplierPercentage).'%' : '—') }}</td>
                        <td class="px-1 py-0.5 text-right align-top text-slate-700 text-xs {{ $recSupplierPrice > 0 ? 'text-green-600' : '' }}">{{ $recSupplierPrice > 0 ? $fmt($recSupplierPrice).' '.$currencyCode : '—' }}</td>
                        <td class="px-1 py-0.5 text-right align-top font-semibold text-slate-900 text-xs {{ $recLineTotal > 0 ? 'text-green-600' : '' }}">{{ $recLineTotal > 0 ? $fmtNoRound($recLineTotal).' '.$currencyCode : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Summary Section: Totals -->
        <div class="summary-section mt-4">
            <div class="grid grid-cols-2 gap-2 text-xs p-0" id="totals-section">
                <!-- Requested Summary -->
                <div class="totals-box summary-totals">
                    <div class="text-xs font-semibold text-slate-700 mb-0.5 uppercase tracking-wider border-b border-slate-300 pb-0.5">Requested Summary</div>
                    <!-- Total Quantity -->
                    <div class="flex justify-between gap-4 mb-0 text-slate-700">
                        <span class="text-slate-600">Total Items:</span>
                        <span class="whitespace-nowrap font-semibold text-slate-900">{{ $fmt($requestedTotalQty) }}</span>
                    </div>
                    
                    <!-- Total Unit Price -->
                    <div class="flex justify-between gap-4 mb-0 text-slate-700">
                        <span class="text-slate-600">Total Unit Price:</span>
                        <span class="whitespace-nowrap font-semibold text-slate-900">{{ $fmt($requestedSubtotal) }} {{ $currencyCode }}</span>
                    </div>
                    
                    <!-- Total Supplier Cost -->
                    <div class="summary-final-total flex justify-between gap-4 mt-0.5 pt-0.5 font-bold text-sm text-slate-900">
                        <span>Total Supplier Cost:</span>
                        <span class="whitespace-nowrap text-blue-600">{{ $fmtNoRound($requestedSupplierTotal) }} {{ $currencyCode }}</span>
                    </div>
                </div>
                
                <!-- Received Summary -->
                <div class="totals-box summary-totals">
                    <div class="text-xs font-semibold text-slate-700 mb-0.5 uppercase tracking-wider border-b border-slate-300 pb-0.5">Received Summary</div>
                    <!-- Total Quantity -->
                    <div class="flex justify-between gap-4 mb-0 text-slate-700">
                        <span class="text-slate-600">Total Items:</span>
                        <span class="whitespace-nowrap font-semibold {{ $purchaseOrder->total_received_quantity > 0 ? 'text-green-600' : 'text-slate-900' }}">{{ $fmt($receivedTotalQty ?? 0) }}</span>
                    </div>
                    
                    <!-- Total Unit Price -->
                    <div class="flex justify-between gap-4 mb-0 text-slate-700">
                        <span class="text-slate-600">Total Unit Price:</span>
                        <span class="whitespace-nowrap font-semibold {{ $purchaseOrder->total_received_unit_price > 0 ? 'text-green-600' : 'text-slate-900' }}">{{ $purchaseOrder->total_received_unit_price > 0 ? $fmt($receivedSubtotal).' '.$currencyCode : '—' }}</span>
                    </div>
                    
                    <!-- Total Supplier Cost -->
                    <div class="summary-final-total flex justify-between gap-4 mt-0.5 pt-0.5 font-bold text-sm text-slate-900">
                        <span>Total Supplier Cost:</span>
                        <span class="whitespace-nowrap {{ $purchaseOrder->total_received_supplier_price > 0 ? 'text-green-600' : 'text-slate-900' }}">{{ $purchaseOrder->total_received_supplier_price > 0 ? $fmtNoRound($receivedSupplierTotal).' '.$currencyCode : '—' }}</span>
                    </div>
                </div>
            </div>

            <!-- Thank You Message -->
            <div class="text-center mt-0.5 mb-1 text-xs font-light text-slate-500 tracking-wide" id="thankyou-section">
                Thank you for your business!
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        const nextUrl = @json($next ?? '/');
        let printInitiated = false;
        let redirected = false;

        function goNext() {
            if (redirected) return;
            redirected = true;
            location.replace(nextUrl);
        }

        function triggerPrint() {
            if (printInitiated) return;
            printInitiated = true;
            document.documentElement.classList.add('printing');
            
            setTimeout(() => {
                try {
                    window.print();
                } catch (e) {
                    console.error('Print error:', e);
                }
            }, 150);
            
            setTimeout(goNext, 5000);
        }

        function prepareAndPrint() {
            document.documentElement.classList.add('printing');
            triggerPrint();
        }

        // Event listeners
        window.addEventListener('beforeprint', () => {
            document.documentElement.classList.add('printing');
        });

        window.addEventListener('afterprint', () => {
            document.documentElement.classList.remove('printing');
            goNext();
        });

        // Auto-print on load
        window.addEventListener('load', () => {
            triggerPrint();
        });
    </script>
</body>
</html>
