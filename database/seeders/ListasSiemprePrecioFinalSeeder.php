<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seeder suelto de la misión prueba-listas-siempre (3/11/2026): deja prendido `users.listas_de_precio`
 * en los dueños de las bases que YA existen, solo donde prenderlo no cambia ningún precio.
 *
 * Todo negocio trabaja con listas de precios desde esta versión. Pero prender el flag en una cuenta
 * cambia de dónde sale el precio de Vender, del listado y de la tienda (del `final_price` del artículo
 * al pivote de la lista por defecto), así que este seeder prende el flag SOLO en el caso en que ese número
 * es el mismo por construcción: el dueño ya tiene la lista «Precio final»
 * (`price_types.es_precio_final = 1`), creada por el comando `listas:migrar-a-precio-final`: su pivote es
 * el espejo del precio del artículo. Es la red de seguridad del comando (si el flag se apagó por el camino,
 * acá vuelve).
 *
 * 🔴 NO prende el flag a un dueño con listas cargadas sin la «Precio final», tenga o no la extensión vieja
 * `articulo_margen_de_ganancia_segun_lista_de_precios`: Vender y la tienda pasarían a cobrar otra cosa, sin
 * verificar artículo por artículo (decisión del chequeo independiente de la misión, 3/11/2026: ese caso se
 * sacó). Esos se migran de a uno con el comando (`--incluir-listas-existentes`, que avisa qué pasa). Tampoco
 * toca a los dueños que usan otro modo de listas (`lista_de_precios_por_categoria`,
 * `lista_de_precios_por_rango_de_cantidad_vendida`), ni crea listas: la «Precio final» de un dueño sin
 * listas la crea el comando, con respaldo y verificación artículo por artículo.
 *
 * Deja en el log cuántos dueños quedan con el flag apagado (pendientes del comando). Idempotente y solo
 * sobre dueños (`owner_id IS NULL`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ListasSiemprePrecioFinalSeeder extends Seeder
{
    /** Modos de listas que este seeder no toca. */
    const EXTENSIONES_DE_OTRO_MODO = array(
        'lista_de_precios_por_categoria',
        'lista_de_precios_por_rango_de_cantidad_vendida',
    );

    /**
     * @return void
     */
    public function run()
    {
        $ids_otro_modo = ExtencionEmpresa::whereIn('slug', self::EXTENSIONES_DE_OTRO_MODO)->pluck('id')->all();

        $duenos = DB::table('users')
            ->whereNull('owner_id')
            ->where(function ($q) {
                $q->whereNull('listas_de_precio')->orWhere('listas_de_precio', 0);
            })
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $prendidos = 0;
        $pendientes = 0;
        $otro_modo = 0;

        foreach ($duenos as $user_id) {

            $user_id = (int) $user_id;

            if (count($ids_otro_modo) && DB::table('extencion_empresa_user')->where('user_id', $user_id)->whereIn('extencion_empresa_id', $ids_otro_modo)->exists()) {
                $otro_modo++;
                continue;
            }

            $tiene_precio_final = DB::table('price_types')->where('user_id', $user_id)->where('es_precio_final', 1)->exists();

            if ($tiene_precio_final) {
                DB::table('users')->where('id', $user_id)->update(array('listas_de_precio' => 1));
                PriceTypeHelper::olvidar_precio_final($user_id);
                $prendidos++;
                continue;
            }

            $pendientes++;
        }

        Log::info('ListasSiemprePrecioFinalSeeder: flag prendido en '.$prendidos.' dueño(s); '.$pendientes.' dueño(s) siguen con el flag apagado y se migran con listas:migrar-a-precio-final; '.$otro_modo.' usan otro modo de listas y no se tocan.');
    }
}
