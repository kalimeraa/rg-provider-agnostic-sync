<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel'in native `failed_jobs` tablosuna tek bir kolon ekler. Ayrı bir
 * DLQ (dead-letter-queue) tablosu yaratmak yerine bilinçli olarak bu yol
 * seçildi — retry'lar zaten `queue:retry` üzerinden yürüyor, bu kolon sadece
 * kaç kez manuel retry denendiğini saymak için.
 */
return new class extends Migration
{
    /**
     * Migration'ı uygular: `failed_jobs`'a `retry_count` kolonunu ekler.
     */
    public function up(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table) {
            $table->unsignedInteger('retry_count')->default(0)->after('failed_at');
        });
    }

    /**
     * Migration'ı geri alır: eklenen `retry_count` kolonunu kaldırır.
     */
    public function down(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table) {
            $table->dropColumn('retry_count');
        });
    }
};
