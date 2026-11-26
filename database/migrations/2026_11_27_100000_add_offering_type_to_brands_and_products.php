<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a brand sells: physical products, software or services. A product can differ from its brand;
 * null on a product means it follows the brand. Existing brands were all physical stores.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brands', 'offering_type')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->string('offering_type', 20)->default('physical');
            });
        }
        if (! Schema::hasColumn('products', 'offering_type')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('offering_type', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['brands', 'products'] as $name) {
            if (Schema::hasColumn($name, 'offering_type')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('offering_type');
                });
            }
        }
    }
};
