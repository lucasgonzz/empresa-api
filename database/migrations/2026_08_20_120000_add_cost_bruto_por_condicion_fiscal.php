<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCostBrutoPorCondicionFiscal extends Migration
{
    /**
     * Run the migrations.
     *
     * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026). Dos columnas aditivas:
     *
     * 1. `articles.cost_bruto` — el costo TAL CUAL lo tipeó el usuario, con IVA incluido. Es el
     *    dato de origen, el número que figura en la factura del proveedor. Hoy ese dato se pierde:
     *    `articles.cost` guarda siempre el NETO (convención del sistema, ver
     *    ArticlePricesHelper::back_out_iva()) y no queda registro de qué se cargó.
     *
     *    🔴 No es fuente de ningún cálculo del pipeline de precios. Existe para que el ida y vuelta
     *    bruto -> neto -> bruto de la interfaz no derive centavos sobre un decimal(22,2): sin él,
     *    abrir y guardar un artículo sin tocarlo podría moverle el costo un centavo cada vez.
     *    Nullable a propósito: `null` significa "este costo se cargó en neto" (el caso de un
     *    Responsable Inscripto que tipea el precio de lista sin IVA).
     *
     * 2. `users.costos_cargados_con_iva` — default de la cuenta para Responsable Inscripto: si al
     *    cargar un costo el usuario está tipeando el bruto (con IVA) o el neto. Se ignora por
     *    completo para Monotributista, que siempre carga bruto (recibe Factura B, donde el neto no
     *    aparece discriminado en ningún lado).
     *
     *    Default 0 (= neto) a propósito: es el comportamiento actual del sistema, así que ninguna
     *    cuenta existente cambia de comportamiento al correr esta migración. Vive en `users` y no
     *    en `user_configurations` porque es la misma fila donde el grupo 231 dejó
     *    `condicion_iva_precios`, que es el otro dato del que depende esta decisión.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            // Misma precisión que `cost` (decimal 22,2, migración 2019_10_24_002337): las dos
            // guardan la misma magnitud y una diferencia de escala entre ellas haría que el
            // back-out no cerrara contra el valor tipeado.
            $table->decimal('cost_bruto', 22, 2)->nullable()->after('cost');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('costos_cargados_con_iva')->default(0)->after('condicion_iva_precios');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('cost_bruto');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('costos_cargados_con_iva');
        });
    }
}
