<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los "Diseños de Vender" de cada negocio (misión diseno-vender-configurable, 28/9/2026).
 *
 * Un diseño dice en qué etapa de Vender va cada campo, en qué orden y de cuántas columnas de la
 * grilla. El negocio puede tener todos los que quiera y vende con UNO solo: el que está `en_uso`
 * (decisión de Lucas, 28/9/2026: uno para todo el negocio, no uno por empleado).
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 `layout = NULL` NO ES UN DATO FALTANTE: ES "EL DISEÑO DEL SISTEMA"
 * -------------------------------------------------------------------------------------------
 *
 * El "Diseño predeterminado" que siembra `VenderLayoutSeeder` (y que crea el index cuando un dueño
 * no tiene ninguno) se guarda con `layout = NULL`. El SPA lo arma en código
 * (`src/components/vender/layout/diseno_predeterminado.js`), así el diseño por defecto vive en UN
 * solo lugar y no queda duplicado entre PHP y JS: un cliente que no tocó nada sigue viendo Vender
 * exactamente como hoy, incluido el ancho del buscador que hoy se calcula según sus extensiones.
 * Recién cuando alguien lo guarda desde el editor queda explícito.
 *
 * El JSON NO lo interpreta el backend: lo normaliza (`VenderLayoutHelper::normalizar_layout`) para
 * que no entre basura, pero no conoce el catálogo de campos, que es del SPA. Por eso es `longText`
 * y no `json`: nadie consulta adentro del diseño desde SQL.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 A PROPÓSITO NO HAY UNIQUE NI ÍNDICE COMPUESTO SOBRE (`user_id`, `en_uso`)
 * -------------------------------------------------------------------------------------------
 *
 * El invariante "exactamente un diseño en uso por dueño" se sostiene EN CÓDIGO
 * (`VenderLayoutController` + `VenderLayoutHelper::poner_en_uso()`), apagando los demás en la MISMA
 * transacción que prende uno, igual que `sistemas_de_puntos.activo`. Un unique sobre
 * (`user_id`, `en_uso`) no sirve: prohibiría también tener dos diseños APAGADOS, que es justamente
 * el caso normal (todos menos uno). Y las reglas del repo piden no usar unique compuestos.
 *
 * Sin foreign keys físicas, igual que todo el schema. Sin soft deletes: ninguna venta ni ningún
 * otro registro apunta al diseño con el que se hizo, así que borrar uno no deja nada colgando.
 */
class CreateVenderLayoutsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar (mismo criterio que
     * `create_sistemas_de_puntos_table`).
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('vender_layouts')) {
            return;
        }

        Schema::create('vender_layouts', function (Blueprint $table) {

            /* Clave primaria autoincremental (bigint unsigned) */
            $table->id();

            /*
             * El DUEÑO del negocio (`$this->userId()`), nunca el empleado: el diseño es uno para
             * todo el negocio. unsignedInteger y no unsignedBigInteger porque `users.id` es
             * increments() -> int unsigned: espeja el tipo de su fuente.
             *
             * Índice simple: todas las lecturas de esta tabla son "los diseños de este dueño" (el
             * index del ABM, el "hay alguno" del predeterminado y el apagado de los demás), y un
             * dueño tiene un puñado de filas, así que no hace falta sumar `en_uso` al índice.
             */
            $table->unsignedInteger('user_id')->index();

            /* Nombre que se ve en la tarjeta del ABM ("Diseño predeterminado", "Mostrador", ...) */
            $table->string('name', 120);

            /* JSON del diseño (ver §3 del plan y resolver_diseno.js). NULL = diseño del sistema. */
            $table->longText('layout')->nullable();

            /* El diseño con el que vende todo el negocio. Exactamente uno por dueño (en código). */
            $table->boolean('en_uso')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vender_layouts');
    }
}
