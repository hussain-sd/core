<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createCountriesTable();
        $this->createCurrenciesTable();
        $this->createTimezonesTable();
        $this->createCountryCurrencyTable();
        $this->createCountryTimezoneTable();
        $this->createUnitDimensionsTable();
        $this->createUnitsTable();
        $this->createStoreSettingsTable();
        $this->createAttributesTable();
        $this->createBrandsTable();
        $this->createCategoriesTable();
        $this->createSuppliersTable();
        $this->createCustomersTable();
        $this->createProductsTable();
        $this->createProductAttributesTable();
        $this->createVariationsTable();
        $this->createStocksTable();
        $this->createPurchaseOrdersTable();
        $this->createPurchaseOrderVariationTable();
        $this->createSalesTable();
        $this->createSaleVariationTable();
        $this->createSalePreparableItemsTable();
        $this->createPaymentsTable();
        $this->createTransactionsTable();
        $this->createCashTransactionsTable();
        $this->createImagesTable();
        $this->createPermissionsTable();
        $this->createRolesTable();
        $this->createRoleHasPermissionsTable();
        $this->createUserRoleTable();
        $this->createInvitationsTable();
        $this->createModelActivitiesTable();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('model_activities');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('images');
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_preparable_items');
        Schema::dropIfExists('sale_variation');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('purchase_order_variation');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('stocks');
        Schema::dropIfExists('variations');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('products');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('store_settings');
        Schema::dropIfExists('units');
        Schema::dropIfExists('unit_dimensions');
        Schema::dropIfExists('country_timezone');
        Schema::dropIfExists('country_currency');
        Schema::dropIfExists('timezones');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('countries');
        Schema::enableForeignKeyConstraints();
    }

    private function createCountriesTable(): void
    {
        if (Schema::hasTable('countries')) {
            return;
        }

        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 2)->unique();
            $table->string('code3', 3)->nullable();
            $table->string('numeric_code', 3)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('code');
        });
    }

    private function createCurrenciesTable(): void
    {
        if (Schema::hasTable('currencies')) {
            return;
        }

        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 3)->unique();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->timestamps();

            $table->index('code');
        });
    }

    private function createTimezonesTable(): void
    {
        if (Schema::hasTable('timezones')) {
            return;
        }

        Schema::create('timezones', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('offset')->nullable();
            $table->timestamps();

            $table->index('name');
        });
    }

    private function createCountryCurrencyTable(): void
    {
        if (Schema::hasTable('country_currency')) {
            return;
        }

        Schema::create('country_currency', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['country_id', 'currency_id']);
        });
    }

    private function createCountryTimezoneTable(): void
    {
        if (Schema::hasTable('country_timezone')) {
            return;
        }

        Schema::create('country_timezone', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('timezone_id')->constrained('timezones')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['country_id', 'timezone_id']);
        });
    }

    private function createUnitDimensionsTable(): void
    {
        if (Schema::hasTable('unit_dimensions')) {
            return;
        }

        Schema::create('unit_dimensions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('base_unit_id')->nullable();
            $table->timestamps();
        });
    }

    private function createUnitsTable(): void
    {
        if (Schema::hasTable('units')) {
            return;
        }

        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->foreignId('dimension_id')->nullable()->constrained('unit_dimensions');
            $table->string('name');
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('to_base_factor', 18, 6)->default(1);
            $table->decimal('to_base_offset', 18, 6)->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index('deleted_at');
        });
    }

    private function createStoreSettingsTable(): void
    {
        if (Schema::hasTable('store_settings')) {
            return;
        }

        Schema::create('store_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type');
            $table->timestamps();

            $table->unique(['store_id', 'key']);
        });
    }

    private function createAttributesTable(): void
    {
        if (Schema::hasTable('attributes')) {
            return;
        }

        Schema::create('attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['store_id', 'name']);
        });
    }

    private function createBrandsTable(): void
    {
        if (Schema::hasTable('brands')) {
            return;
        }

        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('deleted_at');
            $table->index(['store_id', 'status']);
        });
    }

    private function createCategoriesTable(): void
    {
        if (Schema::hasTable('categories')) {
            return;
        }

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('deleted_at');
            $table->index(['store_id', 'status']);
        });
    }

    private function createSuppliersTable(): void
    {
        if (Schema::hasTable('suppliers')) {
            return;
        }

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('deleted_at');
            $table->index(['store_id', 'status']);
        });
    }

    private function createCustomersTable(): void
    {
        if (Schema::hasTable('customers')) {
            return;
        }

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->text('address')->nullable();
            $table->string('status')->default('active');
            $table->string('ntn', 9)->nullable();
            $table->string('cnic', 13)->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['store_id', 'phone']);
            $table->index('status');
            $table->index('deleted_at');
            $table->index(['store_id', 'status']);
            $table->index('email');
            $table->index('created_at');
        });
    }

    private function createProductsTable(): void
    {
        if (Schema::hasTable('products')) {
            return;
        }

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->boolean('has_variations')->default(false);
            $table->boolean('is_preparable')->default(false);
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('deleted_at');
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'brand_id']);
            $table->index(['store_id', 'category_id']);
            $table->index('name');
            $table->index('has_variations');
            $table->index(['store_id', 'has_variations']);
        });
    }

    private function createProductAttributesTable(): void
    {
        if (Schema::hasTable('product_attributes')) {
            return;
        }

        Schema::create('product_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->text('values')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'attribute_id']);
        });
    }

    private function createVariationsTable(): void
    {
        if (Schema::hasTable('variations')) {
            return;
        }

        Schema::create('variations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->string('brand_name')->nullable();
            $table->string('description');
            $table->string('sku')->nullable();
            $table->bigInteger('price')->nullable();
            $table->bigInteger('sale_price')->nullable();
            $table->decimal('sale_percentage', 9, 6)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->string('pct_code', 9)->nullable();
            $table->timestamps();

            $table->index('sku');
            $table->index('description');
            $table->index('price');
            $table->index('sale_price');
            $table->index('product_id');
            $table->index(['product_id', 'created_at']);
            $table->index(['product_id', 'sku']);
        });
    }

    private function createStocksTable(): void
    {
        if (Schema::hasTable('stocks')) {
            return;
        }

        Schema::create('stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variation_id')->constrained('variations')->cascadeOnDelete();
            $table->string('barcode');
            $table->string('batch_number')->nullable();
            $table->bigInteger('price')->nullable();
            $table->bigInteger('sale_price')->nullable();
            $table->decimal('sale_percentage', 9, 6)->nullable();
            $table->decimal('tax_percentage', 9, 6)->nullable();
            $table->bigInteger('tax_amount')->nullable();
            $table->decimal('supplier_percentage', 9, 6)->nullable();
            $table->bigInteger('supplier_price')->nullable();
            $table->decimal('stock', 18, 6)->default(0);
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->timestamps();

            $table->unique(['variation_id', 'barcode', 'batch_number'], 'stocks_unique');
            $table->index(['variation_id', 'barcode']);
        });
    }

    private function createPurchaseOrdersTable(): void
    {
        if (Schema::hasTable('purchase_orders')) {
            return;
        }

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('reference');
            $table->decimal('total_requested_quantity', 18, 6)->nullable();
            $table->decimal('total_received_quantity', 18, 6)->nullable();
            $table->bigInteger('total_requested_unit_price')->nullable();
            $table->bigInteger('total_received_unit_price')->nullable();
            $table->bigInteger('total_requested_tax_amount')->nullable();
            $table->bigInteger('total_received_tax_amount')->nullable();
            $table->bigInteger('total_requested_supplier_price')->nullable();
            $table->bigInteger('total_received_supplier_price')->nullable();
            $table->decimal('total_requested_supplier_percentage', 9, 6)->nullable();
            $table->decimal('total_received_supplier_percentage', 9, 6)->nullable();
            $table->string('status')->default('pending');
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
            $table->index('deleted_at');
            $table->index('reference');
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'created_at']);
            $table->index(['supplier_id', 'created_at']);
        });
    }

    private function createPurchaseOrderVariationTable(): void
    {
        if (Schema::hasTable('purchase_order_variation')) {
            return;
        }

        Schema::create('purchase_order_variation', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('variations')->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('requested_quantity', 18, 6)->default(1);
            $table->foreignId('requested_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->bigInteger('requested_unit_price')->nullable();
            $table->decimal('requested_tax_percentage', 9, 6)->nullable();
            $table->bigInteger('requested_tax_amount')->nullable();
            $table->decimal('requested_supplier_percentage', 9, 6)->nullable();
            $table->boolean('requested_supplier_is_percentage')->nullable();
            $table->bigInteger('requested_supplier_price')->nullable();
            $table->decimal('received_quantity', 18, 6)->nullable();
            $table->foreignId('received_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->bigInteger('received_unit_price')->nullable();
            $table->decimal('received_tax_percentage', 9, 6)->nullable();
            $table->bigInteger('received_tax_amount')->nullable();
            $table->decimal('received_supplier_percentage', 9, 6)->nullable();
            $table->boolean('received_supplier_is_percentage')->nullable();
            $table->bigInteger('received_supplier_price')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id', 'procurement_variation_procurement_id_index');
            $table->index('variation_id', 'procurement_variation_variation_id_index');
        });
    }

    private function createSalesTable(): void
    {
        if (Schema::hasTable('sales')) {
            return;
        }

        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id')->nullable();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->bigInteger('subtotal')->nullable();
            $table->bigInteger('tax')->nullable();
            $table->bigInteger('discount')->nullable();
            $table->string('discount_type')->default('flat');
            $table->decimal('discount_percentage', 9, 6)->nullable();
            $table->bigInteger('freight_fare')->default(0);
            $table->bigInteger('total')->nullable();
            $table->string('status')->default('completed');
            $table->string('payment_status')->default('paid');
            $table->string('payment_method')->nullable();
            $table->boolean('use_fbr')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->text('fbr_qr_code')->nullable();
            $table->timestamp('fbr_synced_at')->nullable();
            $table->json('fbr_response')->nullable();
            $table->string('fbr_refund_invoice_number')->nullable();
            $table->text('fbr_refund_qr_code')->nullable();
            $table->timestamp('fbr_refund_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'local_id']);
            $table->index('status');
            $table->index('payment_status');
            $table->index('created_at');
            $table->index('paid_at');
            $table->index('reference');
            $table->index(['store_id', 'created_at']);
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'payment_status']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['id', 'created_at']);
            $table->index(['customer_id', 'store_id']);
            $table->index(['store_id', 'status', 'payment_method', 'payment_status'], 'sales_payment_filter_idx');
        });
    }

    private function createSaleVariationTable(): void
    {
        if (Schema::hasTable('sale_variation')) {
            return;
        }

        Schema::create('sale_variation', function (Blueprint $table): void {
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('variations')->nullOnDelete();
            $table->foreignId('stock_id')->nullable()->constrained('stocks')->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 6)->default(1);
            $table->bigInteger('unit_price');
            $table->bigInteger('tax')->nullable();
            $table->bigInteger('discount')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_percentage', 9, 6)->nullable();
            $table->bigInteger('total')->nullable();
            $table->bigInteger('supplier_price')->nullable();
            $table->bigInteger('supplier_total')->nullable();
            $table->boolean('is_preparable')->default(false);

            $table->index('sale_id');
            $table->index('variation_id');
            $table->index(['sale_id', 'variation_id']);
            $table->index(['sale_id', 'supplier_total'], 'sale_variation_profit_calc_idx');
        });
    }

    private function createSalePreparableItemsTable(): void
    {
        if (Schema::hasTable('sale_preparable_items')) {
            return;
        }

        Schema::create('sale_preparable_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('preparable_variation_id')->constrained('variations')->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained('variations')->cascadeOnDelete();
            $table->foreignId('stock_id')->nullable()->constrained('stocks')->nullOnDelete();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_percentage', 9, 6)->nullable();
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('supplier_price')->default(0);
            $table->unsignedBigInteger('supplier_total')->default(0);
            $table->timestamps();

            $table->index(['sale_id', 'preparable_variation_id']);
        });
    }

    private function createPaymentsTable(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('payable_type')->nullable();
            $table->unsignedBigInteger('payable_id')->nullable();
            $table->bigInteger('amount');
            $table->string('payment_method')->default('cash');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id', 'created_at']);
            $table->index(['store_id', 'reference']);
        });
    }

    private function createTransactionsTable(): void
    {
        if (Schema::hasTable('transactions')) {
            return;
        }

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('transactionable_type');
            $table->unsignedBigInteger('transactionable_id');
            $table->string('referenceable_type')->nullable();
            $table->unsignedBigInteger('referenceable_id')->nullable();
            $table->string('type');
            $table->bigInteger('amount')->nullable();
            $table->bigInteger('amount_balance')->nullable();
            $table->decimal('quantity', 18, 6)->nullable();
            $table->decimal('quantity_balance', 18, 6)->nullable();
            $table->string('note')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['transactionable_type', 'transactionable_id']);
            $table->index(['referenceable_type', 'referenceable_id']);
            $table->index('type');
            $table->index('created_at');
            $table->index('deleted_at');
            $table->index(['store_id', 'type']);
            $table->index(['store_id', 'created_at']);
            $table->index(['store_id', 'type', 'created_at']);
            $table->index(['store_id', 'transactionable_type', 'transactionable_id', 'id'], 'transactions_latest_lookup_idx');
            $table->index(['store_id', 'transactionable_type', 'amount_balance'], 'transactions_pending_lookup_idx');
        });
    }

    private function createCashTransactionsTable(): void
    {
        if (Schema::hasTable('cash_transactions')) {
            return;
        }

        Schema::create('cash_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('type');
            $table->bigInteger('amount');
            $table->bigInteger('cash_balance');
            $table->string('referenceable_type')->nullable();
            $table->unsignedBigInteger('referenceable_id')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['referenceable_type', 'referenceable_id']);
        });
    }

    private function createImagesTable(): void
    {
        if (Schema::hasTable('images')) {
            return;
        }

        Schema::create('images', function (Blueprint $table): void {
            $table->id();
            $table->string('imageable_type');
            $table->unsignedBigInteger('imageable_id');
            $table->string('path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['imageable_type', 'imageable_id']);
            $table->index(['imageable_type', 'imageable_id', 'sort_order']);
        });
    }

    private function createPermissionsTable(): void
    {
        if (Schema::hasTable('permissions')) {
            return;
        }

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('group')->nullable();
            $table->string('panel')->nullable()->comment('store or admin');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    private function createRolesTable(): void
    {
        if (Schema::hasTable('roles')) {
            return;
        }

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('panel')->nullable()->comment('store or admin');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['name', 'store_id', 'panel'], 'roles_name_store_panel_unique');
        });
    }

    private function createRoleHasPermissionsTable(): void
    {
        if (Schema::hasTable('role_has_permissions')) {
            return;
        }

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id'], 'role_has_permissions_role_id_permission_id_unique');
        });
    }

    private function createUserRoleTable(): void
    {
        if (Schema::hasTable('user_role')) {
            return;
        }

        Schema::create('user_role', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'store_id'], 'user_role_store_unique');
        });
    }

    private function createInvitationsTable(): void
    {
        if (Schema::hasTable('invitations')) {
            return;
        }

        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('token')->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    private function createModelActivitiesTable(): void
    {
        if (Schema::hasTable('model_activities')) {
            return;
        }

        Schema::create('model_activities', function (Blueprint $table): void {
            $table->id();
            $table->string('activityable_type');
            $table->unsignedBigInteger('activityable_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['activityable_type', 'activityable_id'], 'model_activities_unique');
            $table->index(['activityable_type', 'activityable_id'], 'model_activities_type_id_index');
        });
    }
};
