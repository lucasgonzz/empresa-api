<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pasa a NULL los `clients.price_type_id` que estan en 0 (tanda 2 de la mision
 * vender-lista-obligatoria, 18/9/2026, item A2).
 *
 * 🔴 DE DONDE SALE EL 0. El form generico de clientes de la SPA nace con `price_type_id` en 0
 * (`empresa-spa/src/models/client.js`, el placeholder del select) y `ClientController::store()` y
 * `update()` lo guardaban pelado; el job del archivo de intercambio hacia `(float)$data[13]`, que
 * con la columna vacia da 0.0. Medido en produccion el 17/9/2026: Trama tiene 9.440 de 12.194
 * clientes con `price_type_id = 0`; golonorte, 527 de 733. Es el caso MAS COMUN de "cliente sin
 * lista", y el rescate de la lista del cliente (`SaleController::store()`, `BudgetHelper`,
 * `CreateSaleOrderHelper`) preguntaba `!is_null` y copiaba ese 0 a la venta: de ahi salian las 309
 * ventas al mes con `sales.price_type_id = 0` de golonorte.
 *
 * 🔴 POR QUE HACE FALTA UNA MIGRACION Y NO ALCANZA CON EL ARREGLO DEL CODIGO. Los tres escritores
 * ya normalizan el 0 a null (`PriceTypeHelper::normalizar_price_type_id()`), asi que no se generan
 * ceros NUEVOS, y los lectores de la venta y el presupuesto ya tratan 0 y null igual
 * (`resolver_price_type_id_para_guardar()`). Pero un 0 no es una lista: es "ninguna" escrito de
 * otra forma, y dejarlo en la base es dejar una trampa para el proximo lector que pregunte
 * `!is_null` o `isset`. Con null la columna dice lo que es.
 *
 * 🔴 ES SEGURA DE CORRER: solo toca filas con `price_type_id = 0`, y no existe una lista con id
 * 0 (`price_types.id` es autoincremental desde 1). Ningun cliente pierde una lista real. La SPA
 * vieja sigue mandando 0 desde el ABM y el back lo normaliza; la SPA lee null y 0 como "sin lista"
 * en los dos casos.
 *
 * Viaja con el codigo y la corre `DeploymentService` con el resto de las migraciones: NO se
 * publica como comando ni como seeder pendiente. Es idempotente: la segunda vez no encuentra nada.
 */
class NormalizarPriceTypeIdCeroEnClients extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $afectados = DB::table('clients')
            ->where('price_type_id', 0)
            ->update(['price_type_id' => null]);

        if ($afectados) {
            Log::info('Migracion normalizar_price_type_id_cero_en_clients: '.$afectados.' cliente(s) con price_type_id = 0 pasaron a NULL.');
        }
    }

    /**
     * Sin vuelta atras, y no es un descuido: el 0 y el null significan lo mismo ("sin lista") para
     * todos los lectores, asi que no hay nada que restituir. Volver a escribir un 0 seria volver a
     * poner la trampa.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
