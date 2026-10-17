<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use App\Models\PermissionEmpresa;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Le da el permiso `sale.index.previus_days` («Ver ventas de días anteriores») a los empleados que hoy
 * tienen `sale.index` (misión seeder-dias-anteriores-de-ventas, 17/10/2026).
 *
 *   php artisan db:seed --class=PermissionVentasDiasAnterioresSeeder
 *
 * Por qué existe: hasta la misión alcance-de-ventas-caminos-faltantes (16/10/2026) ese permiso solo
 * escondía la tira de días de la pantalla de Ventas; el botón de calendario le quedaba a la vista a
 * cualquiera, así que un empleado SIN el permiso elegía igual cualquier día o rango. Desde esa misión
 * el servidor lo hace valer de verdad: sin el permiso el empleado recibe solo las ventas de hoy (en la
 * lista y en el buscador de Ventas) y deja de ver el calendario. Sin este seeder, con el release TODOS
 * los empleados no administradores que no lo tengan tildado —y es la inmensa mayoría, porque nunca
 * hizo falta— pierden de golpe los días anteriores. Mismo problema y misma salida que
 * `PermissionComprobantesIndexSeeder` (9/10/2026).
 *
 * El criterio para saber quién «lo usa hoy» es la decisión de Lucas del 17/10/2026: el que tiene
 * `sale.index` (ver el listado de ventas). Quien no lo tiene no usa el calendario de Ventas y no se le
 * regala nada; no se suma ningún otro permiso de origen.
 *
 * Qué hace, sin borrar ni reinsertar nada:
 *   1. Si la base no tiene ninguna fila `sale.index.previus_days`, la crea con el nombre y el grupo del
 *      catálogo (`PermisosCatalogoHelper`). En una base que ya corrió `PermisosOrdenarYCompletarSeeder`
 *      o `PermissionSeeder` la fila ya está y no se crea otra.
 *   2. A cada usuario que tenga `sale.index` y todavía no tenga `sale.index.previus_days` le agrega la
 *      fila del pivot `permission_empresa_user`.
 *
 * Bases viejas: el mismo slug puede estar repetido en varias filas (seeders viejos que usaban
 * `create()`) y los pivots pueden colgar de cualquiera de los ids. Por eso todo se busca por SLUG y
 * no por id: un empleado colgado del segundo id de `sale.index` también lo recibe, y uno que ya
 * tiene `sale.index.previus_days` por un id duplicado no recibe otra fila. El permiso se cuelga
 * siempre del id más bajo de `sale.index.previus_days`.
 *
 * Es idempotente: correrlo dos veces no crea filas de más. ⚠️ Pero no es neutro: si entre una
 * corrida y otra el dueño le SACÓ el permiso a un empleado que tiene `sale.index`, la segunda
 * corrida se lo vuelve a dar. Por eso va UNA vez, en los `seeders` de la publicación del release, y
 * NO en `DatabaseSeeder`: las bases nuevas lo reciben por el catálogo (`PermissionSeeder`) y los
 * empleados de las demos por `EmployeeSeeder`.
 *
 * Es por BASE DE DATOS (`run_scope: per_database`): las bases compartidas por varios comercios lo
 * corren una sola vez y alcanza a todos sus usuarios.
 *
 * PHP 7.4: sin argumentos nombrados ni funciones flecha con tipos.
 */
class PermissionVentasDiasAnterioresSeeder extends Seeder
{
    /**
     * El permiso. La SPA lo consulta tal cual (`<modelo>.index.previus_days`) y el servidor lo lee en
     * `ListadoVentasHelper::PERMISO_DIAS_ANTERIORES`: el slug no se cambia.
     */
    const SLUG = 'sale.index.previus_days';

    /**
     * Quien tenga alguno de estos permisos recibe `sale.index.previus_days` (decisión de Lucas,
     * 17/10/2026). Hoy es uno solo, pero queda como lista por si el criterio se amplía.
     */
    const SLUGS_QUE_LO_HEREDAN = ['sale.index'];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $resultado = $this->aplicar();

        if (isset($this->command)) {
            $this->command->info(
                'Permiso '.self::SLUG.': '
                .($resultado['permisos_creados'] ? 'creado' : 'ya existía')
                .' — se lo di a '.$resultado['empleados'].' empleado(s) con alguno de: '
                .implode(', ', self::SLUGS_QUE_LO_HEREDAN)
            );
        }
    }

    /**
     * Crea el permiso si falta y se lo da a quien corresponde. Público para que el test pueda leer
     * cuántos permisos creó y a cuántos empleados se lo dio.
     *
     * @return array  ['permisos_creados' => int, 'empleados' => int]
     */
    public function aplicar()
    {
        return DB::transaction(function () {

            $permisos_creados = 0;

            $ids_dias = PermissionEmpresa::where('slug', self::SLUG)
                                ->orderBy('id')
                                ->pluck('id');

            if (!$ids_dias->count()) {
                $filas = PermisosCatalogoHelper::filas();
                $fila = $filas[self::SLUG];

                // `forceCreate`: el modelo no declara `$fillable` (igual que `PermisosCatalogoHelper::aplicar()`).
                $permiso = PermissionEmpresa::forceCreate([
                    'slug'          => self::SLUG,
                    'name'          => $fila['nombre'],
                    'model_name'    => $fila['grupo'],
                ]);

                $ids_dias = collect([$permiso->id]);
                $permisos_creados = 1;
            }

            // Si el slug está repetido, el permiso va al id más bajo (el más viejo).
            $id_destino = $ids_dias->first();

            // Todos los ids de los permisos que lo heredan, incluidos los repetidos de bases viejas.
            $ids_origen = PermissionEmpresa::whereIn('slug', self::SLUGS_QUE_LO_HEREDAN)->pluck('id');

            if (!$ids_origen->count()) {
                return ['permisos_creados' => $permisos_creados, 'empleados' => 0];
            }

            $ids_dias_array = $ids_dias->all();

            /*
                Usuarios con `sale.index` (por cualquiera de sus ids) que todavía no tienen
                `sale.index.previus_days` (por cualquiera de sus ids). `distinct`: el que tiene `sale.index`
                repetido por ids duplicados recibe una sola fila. El `whereIn` contra `users` deja afuera
                los pivots huérfanos de usuarios que ya no existen, para no sumarles filas ni contarlos.
            */
            $user_ids = DB::table('permission_empresa_user')
                            ->whereIn('permission_empresa_id', $ids_origen->all())
                            ->whereNotIn('user_id', function ($query) use ($ids_dias_array) {
                                $query->select('user_id')
                                      ->from('permission_empresa_user')
                                      ->whereIn('permission_empresa_id', $ids_dias_array);
                            })
                            ->whereIn('user_id', function ($query) {
                                $query->select('id')->from('users');
                            })
                            ->distinct()
                            ->orderBy('user_id')
                            ->pluck('user_id');

            $ahora = Carbon::now();

            foreach ($user_ids->chunk(500) as $tanda) {
                $filas_pivot = [];

                foreach ($tanda as $user_id) {
                    $filas_pivot[] = [
                        'permission_empresa_id' => $id_destino,
                        'user_id'               => $user_id,
                        'created_at'            => $ahora,
                        'updated_at'            => $ahora,
                    ];
                }

                DB::table('permission_empresa_user')->insert($filas_pivot);
            }

            return ['permisos_creados' => $permisos_creados, 'empleados' => $user_ids->count()];
        });
    }
}
