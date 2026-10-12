<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use App\Models\PermissionEmpresa;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Le da el permiso nuevo `comprobantes.index` a los empleados que hoy usan la pantalla Comprobantes
 * (misión permisos-navegacion-empleados, 9/10/2026).
 *
 *   php artisan db:seed --class=PermissionComprobantesIndexSeeder
 *
 * Por qué existe: hasta esta misión la pantalla Comprobantes (notas de crédito y pagos de clientes,
 * con importes) no pedía ningún permiso, así que cualquier empleado la veía. Lucas decidió darle uno
 * propio y la SPA nueva la esconde a quien no lo tenga. Sin este seeder, con el release TODOS los
 * empleados que hoy la usan la pierden de golpe, porque nadie tiene todavía el permiso tildado.
 *
 * El criterio para saber quién "la usa hoy" es la decisión de Lucas: el que tiene `sale.index`
 * (ver el listado de ventas) o `client.index` (ver clientes). Son los que ya ven esos importes por
 * otro lado. Se suma `devolucion.store` (hacer devoluciones), que marcó el chequeo independiente de
 * la misión: en Comprobantes está el botón para reintentar con ARCA una nota de crédito guardada sin
 * CAE (misión del 7/10/2026), y el que hace devoluciones sin ver ventas ni clientes lo perdería.
 *
 * Qué hace, sin borrar ni reinsertar nada:
 *   1. Si la base no tiene ninguna fila `comprobantes.index`, la crea con el nombre y el grupo del
 *      catálogo (`PermisosCatalogoHelper`). En una base que ya corrió `PermisosOrdenarYCompletarSeeder`
 *      o `PermissionSeeder` con el catálogo nuevo la fila ya está y no se crea otra.
 *   2. A cada usuario que tenga `sale.index`, `client.index` o `devolucion.store` y todavía no tenga
 *      `comprobantes.index` le agrega la fila del pivot `permission_empresa_user`.
 *
 * Bases viejas: el mismo slug puede estar repetido en varias filas (seeders viejos que usaban
 * `create()`) y los pivots pueden colgar de cualquiera de los ids. Por eso todo se busca por SLUG y
 * no por id: un empleado colgado del segundo id de `sale.index` también lo recibe, y uno que ya
 * tiene `comprobantes.index` por un id duplicado no recibe otra fila. El permiso nuevo se cuelga
 * siempre del id más bajo de `comprobantes.index`.
 *
 * Es idempotente: correrlo dos veces no crea filas de más. ⚠️ Pero no es neutro: si entre una
 * corrida y otra el dueño le SACÓ el permiso a un empleado que tiene alguno de los que lo heredan,
 * la segunda corrida se lo vuelve a dar. Por eso va UNA vez, en los `seeders` de la publicación del
 * release, y no en `DatabaseSeeder`: las bases nuevas lo reciben por el catálogo
 * (`PermissionSeeder`), y los empleados de las demos por `EmployeeSeeder`.
 *
 * PHP 7.4: sin argumentos nombrados ni funciones flecha con tipos.
 */
class PermissionComprobantesIndexSeeder extends Seeder
{
    /**
     * El permiso nuevo. La SPA lo consulta tal cual en `router/routes.js`: no se cambia.
     */
    const SLUG = 'comprobantes.index';

    /**
     * Quien tenga alguno de estos permisos recibe `comprobantes.index` (decisión de Lucas, 9/10/2026).
     * `devolucion.store` se sumó por el botón de reintentar con ARCA una nota de crédito sin CAE, que
     * vive en Comprobantes (ver la cabecera).
     */
    const SLUGS_QUE_LO_HEREDAN = ['sale.index', 'client.index', 'devolucion.store'];

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

            $ids_comprobantes = PermissionEmpresa::where('slug', self::SLUG)
                                    ->orderBy('id')
                                    ->pluck('id');

            if (!$ids_comprobantes->count()) {
                $filas = PermisosCatalogoHelper::filas();
                $fila = $filas[self::SLUG];

                // `forceCreate`: el modelo no declara `$fillable` (igual que `PermisosCatalogoHelper::aplicar()`).
                $permiso = PermissionEmpresa::forceCreate([
                    'slug'          => self::SLUG,
                    'name'          => $fila['nombre'],
                    'model_name'    => $fila['grupo'],
                ]);

                $ids_comprobantes = collect([$permiso->id]);
                $permisos_creados = 1;
            }

            // Si el slug está repetido, el permiso nuevo va al id más bajo (el más viejo).
            $id_destino = $ids_comprobantes->first();

            // Todos los ids de los permisos que lo heredan, incluidos los repetidos de bases viejas.
            $ids_origen = PermissionEmpresa::whereIn('slug', self::SLUGS_QUE_LO_HEREDAN)->pluck('id');

            if (!$ids_origen->count()) {
                return ['permisos_creados' => $permisos_creados, 'empleados' => 0];
            }

            $ids_comprobantes_array = $ids_comprobantes->all();

            /*
                Usuarios con alguno de SLUGS_QUE_LO_HEREDAN (por cualquiera de sus ids) que todavía no
                tienen `comprobantes.index` (por cualquiera de sus ids). `distinct`: el que tiene
                varios de esos permisos recibe una sola fila. El `whereIn` contra `users` deja afuera los pivots
                huérfanos de usuarios que ya no existen, para no sumarles filas ni contarlos.
            */
            $user_ids = DB::table('permission_empresa_user')
                            ->whereIn('permission_empresa_id', $ids_origen->all())
                            ->whereNotIn('user_id', function ($query) use ($ids_comprobantes_array) {
                                $query->select('user_id')
                                      ->from('permission_empresa_user')
                                      ->whereIn('permission_empresa_id', $ids_comprobantes_array);
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
