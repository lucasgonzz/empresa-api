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
            // 🔴 decimal(22,6), la MISMA escala que tiene hoy `articles.cost`. Ojo: la migración
            // original de 2019 lo creó en 22,2, pero `2026_07_30_160000_extend_cost_decimals_on_
            // articles_table` lo amplió a 22,6 justamente porque los costos importados con más de
            // dos decimales se truncaban en silencio (ProcessRow declara 'cost' => [16, 6]).
            //
            // Dejar `cost_bruto` en 22,2 reintroduciría esa misma truncación por la vía del import:
            // un costo de 1234,5678 guardaría `cost` con seis decimales y `cost_bruto` redondeado a
            // 1234,57, y como la interfaz prefiere `cost_bruto` sobre recalcular, al reabrir y
            // guardar el neto se correría — que es exactamente la deriva que esta columna existe
            // para evitar.
            $table->decimal('cost_bruto', 22, 6)->nullable()->after('cost');
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
