<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `products` tablosu — her tedarikçiden senkronize edilen ürünleri tutar.
 * `(provider_type, external_id)` unique constraint'i idempotency'nin temeli:
 * aynı ürün ikinci kez senkronize edilirse insert değil upsert olur.
 */
return new class extends Migration
{
    /**
     * Migration'ı uygular: tabloyu, unique constraint'i ve index'leri oluşturur.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('provider_type', 32);
            $table->string('external_id', 64);
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('stock');
            $table->text('description')->nullable();
            // HashService::hash() ile hesaplanan sha256 (64 hex karakter) — delta tespiti bu kolon üzerinden yapılır.
            $table->char('data_hash', 64);
            $table->timestamp('last_synced_at')->nullable();
            // Hard-delete değil soft-delete: provider'da artık olmayan ürün için deleted_at set edilir.
            $table->softDeletes();
            $table->timestamps();

            // İdempotency'nin temeli: aynı provider+external_id ikinci kez insert edilemez.
            $table->unique(['provider_type', 'external_id']);
            // DeltaSyncService'in hash karşılaştırması bu index'i kullanır.
            $table->index('data_hash');
            // Soft-delete filtreli sorgular (aktif/silinmiş ürün listeleme) için.
            $table->index('deleted_at');
        });
    }

    /**
     * Migration'ı geri alır: `products` tablosunu tamamen kaldırır.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
