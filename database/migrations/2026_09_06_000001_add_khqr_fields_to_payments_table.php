<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->nullable()->after('order_id');
            $table->string('currency', 10)->default('USD')->after('amount');
            $table->string('transaction_hash')->nullable()->after('transaction_id');
            $table->text('qr_data')->nullable()->after('transaction_hash');
            $table->string('md5', 64)->nullable()->index()->after('qr_data');
            $table->timestamp('paid_at')->nullable()->after('md5');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['md5']);
            $table->dropColumn([
                'amount',
                'currency',
                'transaction_hash',
                'qr_data',
                'md5',
                'paid_at',
            ]);
        });
    }
};
