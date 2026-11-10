<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddConsultasDeStockALaTienda extends Migration
{
    private const COLUMNAS = [
        'online_configurations' => 'permitir_pedidos_sin_stock',
        'article_cart' => 'stock_a_consultar',
        'article_order' => 'stock_a_consultar',
        'carts' => 'preferencia_online_emitida',
    ];

    public function up()
    {
        foreach (self::COLUMNAS as $tabla => $columna) {
            if (!Schema::hasColumn($tabla, $columna)) {
                Schema::table($tabla, function (Blueprint $table) use ($columna) {
                    // La tienda anterior pudo emitir una preferencia para estos carritos.
                    // Solo la API nueva inicializa en falso un carrito que ella crea.
                    $table->boolean($columna)->default($columna === 'preferencia_online_emitida');
                });
            }
        }
    }

    public function down()
    {
        foreach (array_reverse(self::COLUMNAS, true) as $tabla => $columna) {
            if (Schema::hasColumn($tabla, $columna)) {
                Schema::table($tabla, function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
