<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Keeps a record of every legacy WooCommerce entity that has already been
     * migrated and the Bagisto id it was mapped to. This makes the importer
     * idempotent (safe to re-run) and lets the category / attribute / product
     * steps run independently while still resolving each other's references.
     */
    public function up(): void
    {
        Schema::create('woo_import_maps', function (Blueprint $table) {
            $table->id();
            $table->string('entity');                 // category | attribute | attribute_option | product | customer
            $table->string('woo_key');                // legacy id or composite key (e.g. "color|red")
            $table->unsignedBigInteger('bagisto_id'); // resulting Bagisto primary key
            $table->json('meta')->nullable();         // anything useful for later steps (attribute code, option value...)
            $table->timestamps();

            $table->unique(['entity', 'woo_key']);
            $table->index('entity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('woo_import_maps');
    }
};
