<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sync_logs` tablosu — her sync denemesinin (job attempt'i dahil) geçmişini
 * tutar. GET /api/sync/status ve GET /api/sync/history endpoint'leri buradan
 * beslenir.
 */
return new class extends Migration
{
    /**
     * Migration'ı uygular: tabloyu ve history sorgusu için index'i oluşturur.
     */
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider_type', 32);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 32); // running|completed|failed
            $table->unsignedInteger('products_added')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('products_deleted')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            // /api/sync/history'nin provider'a göre filtreleyip tarihe göre sıraladığı sorguyu hızlandırır.
            $table->index(['provider_type', 'started_at']);
        });
    }

    /**
     * Migration'ı geri alır: `sync_logs` tablosunu tamamen kaldırır.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
