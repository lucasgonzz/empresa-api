<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Corrige el nombre visible de la extensión `road_map_detalle_por_articulos_y_no_por_venta`
 * (tanda correctivos 24/8, item 18).
 *
 * La extensión nació en `ExtencionSeeder` con el `name` "Ventas con fecha de entrega", copiado
 * de la extensión anterior de la lista: dos extensiones distintas con el mismo nombre visible,
 * imposibles de distinguir en /user/extencions/edit. El nombre propio es
 * "Hoja de ruta: detalle por articulos", que es lo que la extensión hace de verdad (en el
 * detalle de la hoja de ruta muestra los artículos de todas las ventas, no venta por venta).
 *
 * Este es el seeder para las bases de producción QUE YA EXISTEN: matchea por slug y actualiza
 * solo el `name`. `ExtencionSeeder` ya quedó corregido para las bases nuevas:
 *   php artisan db:seed --class=ExtencionRoadMapDetallePorArticulosNameSeeder
 *
 * Idempotente: correrlo dos veces deja el mismo nombre (la segunda corrida no cambia nada).
 * No toca `extencion_empresa_user`, así que las asignaciones de cada cliente quedan como
 * estaban. Si la base no tiene la extensión (nunca se sembró), no hace nada y lo informa por
 * log — no la crea, porque un catálogo al que le falta la fila la recibe por su camino normal.
 *
 * 🔴 NO va en `DatabaseSeeder`: las bases nuevas ya nacen con el nombre correcto vía
 * `ExtencionSeeder`; acá no se duplica nada (es un update), pero registrarlo sería correr un
 * seeder que no tiene nada para hacer. Mismo criterio de separación que documenta
 * `ExtencionEmpresaDescriptionSeeder` para el par "seeder de base nueva / seeder de base viva".
 */
class ExtencionRoadMapDetallePorArticulosNameSeeder extends Seeder
{
    /**
     * Slug de la extensión a renombrar (no cambia nunca: es la clave del catálogo).
     */
    const SLUG = 'road_map_detalle_por_articulos_y_no_por_venta';

    /**
     * Nombre visible correcto.
     */
    const NAME = 'Hoja de ruta: detalle por articulos';

    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($extencion)) {
            Log::info(
                'ExtencionRoadMapDetallePorArticulosNameSeeder: la base no tiene la extension '
                .self::SLUG.', no hay nada que renombrar.'
            );
            return;
        }

        if ($extencion->name === self::NAME) {
            Log::info(
                'ExtencionRoadMapDetallePorArticulosNameSeeder: la extension ya se llama "'
                .self::NAME.'", no se toca.'
            );
            return;
        }

        $extencion->name = self::NAME;
        $extencion->save();

        Log::info(
            'ExtencionRoadMapDetallePorArticulosNameSeeder: renombrada la extension '
            .self::SLUG.' a "'.self::NAME.'".'
        );
    }
}
