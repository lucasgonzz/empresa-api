<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Textos del pie de pagina (footer) de la tienda editables desde el sistema (pedido de Truvari,
 * 19/11/2026). Todos opcionales: vacios, la tienda muestra lo de siempre (titulo "Contactanos" y el
 * mail de la cuenta del comercio).
 */
class AddTextosFooterToOnlineConfigurations extends Migration
{
    const COLUMNAS = ['footer_contacto_titulo', 'footer_contacto_email', 'footer_contacto_texto'];

    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('online_configurations', 'footer_contacto_titulo')) {
                $table->string('footer_contacto_titulo', 80)->nullable();
            }
            if (!Schema::hasColumn('online_configurations', 'footer_contacto_email')) {
                $table->string('footer_contacto_email', 150)->nullable();
            }
            if (!Schema::hasColumn('online_configurations', 'footer_contacto_texto')) {
                $table->text('footer_contacto_texto')->nullable();
            }
        });
    }

    public function down()
    {
        foreach (self::COLUMNAS as $columna) {
            if (Schema::hasColumn('online_configurations', $columna)) {
                Schema::table('online_configurations', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
