<?php

namespace SmartTill\Core\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Cache;
use SmartTill\Core\Casts\PriceCast;
use SmartTill\Core\Observers\VariationObserver;
use SmartTill\Core\Traits\HasStoreScopedReference;

#[ObservedBy([VariationObserver::class])]
class Variation extends Model
{
    use HasFactory, HasStoreScopedReference;

    protected $fillable = [
        'product_id',
        'store_id',
        'brand_name',
        'description',
        'sku',
        'price',
        'sale_price',
        'sale_percentage',
        'unit_id',
        'pct_code',
    ];

    protected function casts(): array
    {
        return [
            'price' => PriceCast::class,
            'sale_price' => PriceCast::class,
            'sale_percentage' => 'decimal:6',
        ];
    }

    /**
     * Scope a query to perform flexible search using FULLTEXT boolean mode with LIKE fallback.
     *
     * Usage: Variation::query()->search('0100 White')->get();
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        // Store original term for exact matching
        $originalTerm = $term;

        // tokenize
        $rawTokens = preg_split('/\s+/', $term);
        $tokens = array_values(array_filter(array_map(function ($t) {
            // Keep letters, numbers, underscore and dash only
            $clean = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $t ?? '');

            // Strip leading boolean operator characters that break BOOLEAN MODE when standalone
            // e.g. '-', '+', '~', '>', '<', '(', ')', '"', '|'
            $clean = ltrim($clean, '+-~><()|"');

            // Also strip trailing operator characters
            $clean = rtrim($clean, '+-~><()|"');

            // Finally, trim stray dashes/underscores from both ends (internal dashes are allowed)
            $clean = trim($clean, '-_');

            // Skip if empty after cleaning or if it consists only of dashes/underscores
            if ($clean === '' || preg_match('/^[-_]+$/', $clean)) {
                return null;
            }

            return $clean;
        }, $rawTokens)));

        if (empty($tokens)) {
            return $query;
        }

        // If the user entered a single, SKU-like token (e.g. contains a dash/underscore),
        // prefer an exact match on `sku` to avoid broad matches like INF-32* when searching INF-2D4F03.
        $isSingleToken = count($tokens) === 1;
        $hasSkuDelimiter = str_contains($originalTerm, '-') || str_contains($originalTerm, '_');
        $looksLikeSku = $isSingleToken && $hasSkuDelimiter && preg_match('/^[A-Za-z0-9_-]{3,}$/', $originalTerm);

        if ($looksLikeSku) {
            return $query->where(function (Builder $q) use ($originalTerm) {
                $q->where('sku', $originalTerm)
                    ->orWhere('sku', 'like', $originalTerm.'%');
            })->orderByRaw('CASE WHEN sku = ? THEN 0 ELSE 1 END', [$originalTerm]);
        }

        // build boolean fulltext string: +term*
        $booleanTerms = array_map(fn ($t) => '+'.$t.'*', $tokens);
        $against = implode(' ', $booleanTerms);

        $columns = ['sku', 'description'];
        $matchSql = 'MATCH('.implode(',', $columns).') AGAINST (? IN BOOLEAN MODE)';

        // Create the main where clause
        $query->where(function (Builder $q) use ($matchSql, $against, $tokens, $columns, $originalTerm) {
            // Primary: fulltext match
            $q->whereRaw($matchSql, [$against]);

            // Fallback: if fulltext yields nothing (short tokens / stopwords),
            // allow broader LIKE-based matching using all tokens.
            $q->orWhere(function (Builder $fallback) use ($tokens, $columns) {
                foreach ($tokens as $token) {
                    // Escape LIKE wildcards to avoid unintended broad matches
                    $escaped = addcslashes($token, '%_\\');

                    $fallback->where(function (Builder $inner) use ($escaped, $columns) {
                        foreach ($columns as $col) {
                            $inner->orWhere($col, 'like', '%'.$escaped.'%');
                        }
                    });
                }
            });

            // Also add exact and prefix matches for SKU (priority search)
            $q->orWhere('sku', $originalTerm);
            $q->orWhere('sku', 'like', $originalTerm.'%');

            // Add support for dashed descriptions (like P-O-P)
            // Remove spaces and search with dashes intact
            $dashedTerm = str_replace(' ', '', $originalTerm);
            if (str_contains($dashedTerm, '-')) {
                $q->orWhere('description', 'like', '%'.$dashedTerm.'%');
            }
        });

        // Extract first token (likely SKU) for priority sorting
        $firstToken = ! empty($tokens) ? $tokens[0] : null;

        // Priority ordering:
        // 1. Exact SKU match for full search term (highest priority)
        // 2. Exact SKU match for first token (likely SKU) - e.g., SKU "901" when searching "901 g"
        // 3. SKU starts with first token - e.g., SKU "9010" when searching "901 g"
        // 4. SKU starts with full search term
        // 5. SKU contains first token (but doesn't start with it)
        // 6. Description exact match for full search term
        // 7. Description starts with full search term
        // 8. Description contains first token
        // 9. Everything else (fulltext/LIKE matches)
        if ($firstToken) {
            return $query->orderByRaw(
                'CASE
                    WHEN sku = ? THEN 0
                    WHEN sku = ? THEN 1
                    WHEN sku LIKE ? THEN 2
                    WHEN sku LIKE ? THEN 3
                    WHEN sku LIKE ? THEN 4
                    WHEN description = ? THEN 5
                    WHEN description LIKE ? THEN 6
                    WHEN description LIKE ? THEN 7
                    ELSE 8
                END',
                [
                    $originalTerm,           // 0: Exact SKU match for full term
                    $firstToken,             // 1: Exact SKU match for first token
                    $firstToken.'%',         // 2: SKU starts with first token
                    $originalTerm.'%',       // 3: SKU starts with full term
                    '%'.$firstToken.'%',     // 4: SKU contains first token
                    $originalTerm,           // 5: Description exact match for full term
                    $originalTerm.'%',       // 6: Description starts with full term
                    '%'.$firstToken.'%',     // 7: Description contains first token
                ]
            );
        }

        // Fallback ordering when no tokens (shouldn't happen, but safe fallback)
        return $query->orderByRaw(
            'CASE
                WHEN sku = ? THEN 0
                WHEN sku LIKE ? THEN 1
                WHEN description = ? THEN 2
                WHEN description LIKE ? THEN 3
                ELSE 4
            END',
            [$originalTerm, $originalTerm.'%', $originalTerm, $originalTerm.'%']
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Store::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function sales(): BelongsToMany
    {
        return $this->belongsToMany(Sale::class)
            ->using(SaleVariation::class)
            ->withPivot('stock_id', 'quantity', 'unit_price', 'tax', 'discount', 'total', 'supplier_price', 'supplier_total');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->orderBy('sort_order');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function latestStock(): HasOne
    {
        return $this->hasOne(Stock::class)->latestOfMany();
    }

    public function getStockAttribute(): float
    {
        if (array_key_exists('stock', $this->attributes)) {
            return (float) $this->attributes['stock'];
        }

        if ($this->relationLoaded('stocks')) {
            return (float) $this->stocks->sum('stock');
        }

        return (float) $this->stocks()->sum('stock');
    }

    public function scopeWithBarcodeStock(Builder $query): Builder
    {
        return $query->withSum('stocks as stock', 'stock');
    }

    public function purchaseOrders(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseOrder::class)
            ->using(PurchaseOrderProduct::class)
            ->withPivot(
                'description',
                'requested_quantity',
                'requested_unit_price',
                'requested_tax_percentage',
                'requested_tax_amount',
                'requested_supplier_percentage',
                'requested_supplier_is_percentage',
                'requested_supplier_price',
                'received_quantity',
                'received_unit_price',
                'received_tax_percentage',
                'received_tax_amount',
                'received_supplier_percentage',
                'received_supplier_is_percentage',
                'received_supplier_price',
            )
            ->withTimestamps();
    }

    /**
     * Get total revenue for this variation from all sales
     */
    public function getTotalRevenueAttribute(): float
    {
        return $this->sales->sum(function ($sale) {
            $pivot = $sale->pivot;

            return $pivot->total ?? 0;
        });
    }

    /**
     * Get total cost for this variation from all sales
     */
    public function getTotalCostAttribute(): float
    {
        return $this->sales->sum(function ($sale) {
            $pivot = $sale->pivot;

            return $pivot->supplier_total ?? 0;
        });
    }

    /**
     * Get total profit for this variation
     */
    public function getTotalProfitAttribute(): float
    {
        return $this->total_revenue - $this->total_cost;
    }

    /**
     * Get profit margin percentage for this variation
     */
    public function getProfitMarginAttribute(): float
    {
        if ($this->total_revenue == 0) {
            return 0;
        }

        return ($this->total_profit / $this->total_revenue) * 100;
    }

    /**
     * Get total quantity sold for this variation
     */
    public function getTotalQuantitySoldAttribute(): float
    {
        return $this->sales->sum('pivot.quantity');
    }

    /**
     * Get a variation from cache or database.
     * Cache key format: variation_{id}
     * Cache duration: Configurable via VARIATION_CACHE_TTL_HOURS env variable (default: 12 hours)
     * Cache is automatically invalidated on create/update/delete
     */
    public static function findCached(?int $id): ?self
    {
        if (! $id) {
            return null;
        }

        $cacheKey = "variation_{$id}";
        $ttlHours = config('products.variation_cache_ttl_hours', 12);

        return Cache::remember($cacheKey, now()->addHours($ttlHours), function () use ($id) {
            return static::with('product')->find($id);
        });
    }

    /**
     * Invalidate the cache for this variation.
     */
    public function invalidateCache(): void
    {
        Cache::forget("variation_{$this->id}");
    }
}
