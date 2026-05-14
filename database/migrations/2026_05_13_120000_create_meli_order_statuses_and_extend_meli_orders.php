<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estados internos de gestión de pedidos ML (similar a tienda_nube_order_statuses).
 * No define FK a nivel MySQL; la relación es lógica en Eloquent.
 */
class CreateMeliOrderStatusesAndExtendMeliOrders extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('meli_order_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->timestamps();
        });

        DB::table('meli_order_statuses')->insert([
            ['id' => 1, 'name' => 'Pendiente', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Confirmado', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('meli_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('meli_orders', 'num')) {
                $table->string('num', 40)->nullable()->after('id');
            }
            if (!Schema::hasColumn('meli_orders', 'name')) {
                $table->string('name', 120)->nullable()->after('num');
            }
            $table->unsignedBigInteger('meli_order_status_id')->nullable()->default(1)->after('user_id');
            $table->text('notes')->nullable()->after('meli_order_status_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            $table->dropColumn(['meli_order_status_id', 'notes']);
            if (Schema::hasColumn('meli_orders', 'num')) {
                $table->dropColumn('num');
            }
            if (Schema::hasColumn('meli_orders', 'name')) {
                $table->dropColumn('name');
            }
        });

        Schema::dropIfExists('meli_order_statuses');
    }
}
