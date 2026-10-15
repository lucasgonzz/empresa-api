<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use App\Models\PermissionEmpresa;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Le da el permiso `deposit_movement.store` ("Crear movimientos de depósito") a los empleados que
 * hoy pueden crear movimientos de depósito (misión permiso-crear-movimiento-deposito, 14/10/2026).
 *
 *   php artisan db:seed --class=PermissionCrearMovimientoDepositoSeeder
 *
 * Por qué existe: hasta esta misión `deposit_movement.store` figuraba en el grupo "Sin efecto por
 * ahora" y ni `DepositMovementController::store()` ni las sugerencias de stock lo chequeaban, así
 * que cualquier empleado que veía los movimientos podía crearlos. Con el release, los dos caminos
 * responden 403 a quien no lo tenga tildado. Sin este seeder, TODOS los empleados que hoy crean
 * movimientos lo perderían de golpe, porque nadie tiene el permiso tildado todavía (en "Sin efecto"
 * el dueño no tenía motivo para tildarlo).
 *
 * El criterio para saber quién "los usa hoy" es la decisión de Lucas (14/10/2026): el que tiene
 * `article.index` ("Ver el listado de artículos"). Es lo que piden el Listado y Alertas →
 * Movimientos desde la 4.3.9, o sea, el que hoy ve los movimientos. El dueño se lo saca después a
 * quien no corresponda.
 *
 * Qué hace, sin borrar ni reinsertar nada:
 *   1. Si la base no tiene ninguna fila `deposit_movement.store`, la crea con el nombre y el grupo
 *      del catálogo (`PermisosCatalogoHelper`). Si ya la tiene (lo normal: estaba en el catálogo
 *      desde siempre, en "Sin efecto por ahora"), le pone a TODAS las filas con ese slug el nombre y
 *      el grupo del catálogo nuevo: así el permiso aparece en "Stock y depósitos" aunque nadie haya
 *      corrido `PermisosOrdenarYCompletarSeeder`.
 *   2. A cada usuario que tenga `article.index` y todavía no tenga `deposit_movement.store` le
 *      agrega la fila del pivot `permission_empresa_user`.
 *
 * Bases viejas: el mismo slug puede estar repetido en varias filas (seeders viejos que usaban
 * `create()`) y los pivots pueden colgar de cualquiera de los ids. Por eso todo se busca por SLUG y
 * no por id: un empleado colgado del segundo id de `article.index` también lo recibe, y uno que ya
 * tiene `deposit_movement.store` por un id duplicado no recibe otra fila. El permiso se cuelga
 * siempre del id más bajo de `deposit_movement.store`. Los pivots de usuarios que ya no existen
 * (huérfanos) no se tocan ni se cuentan.
 *
 * Es idempotente: correrlo dos veces no crea filas de más. ⚠️ Pero no es neutro: si entre una
 * corrida y otra el dueño le SACÓ el permiso a un empleado que tiene `article.index`, la segunda
 * corrida se lo vuelve a dar. Por eso va UNA vez, en los `seeders` de la publicación del release
 * (`run_scope: per_database`), y NO en `DatabaseSeeder`: las bases nuevas lo reciben por el catálogo
 * (`PermissionSeeder`), y los empleados de las demos por `EmployeeSeeder`.
 *
 * PHP 7.4: sin argumentos nombrados ni funciones flecha con tipos.
 */
class PermissionCrearMovimientoDepositoSeeder extends Seeder
{
    /**
     * El permiso. La SPA lo consulta tal cual (`can('deposit_movement.store')`) y la API en
     * `DepositMovementController` y `StockSuggestionController`: el slug no se cambia.
     */
    const SLUG = 'deposit_movement.store';

    /**
     * Quien tenga este permiso recibe `deposit_movement.store` (decisión de Lucas, 14/10/2026):
     * es el que hoy ve los movimientos de depósito.
     */
    const SLUG_QUE_LO_HEREDA = 'article.index';

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
                .($resultado['permisos_actualizados'] ? ' (se alinearon '.$resultado['permisos_actualizados'].' fila(s) con el catálogo)' : '')
                .' — se lo di a '.$resultado['empleados'].' empleado(s) con '.self::SLUG_QUE_LO_HEREDA
            );
        }
    }

    /**
     * Crea o alinea el permiso y se lo da a quien corresponde. Público para que el test pueda leer
     * cuántos permisos creó o actualizó y a cuántos empleados se lo dio.
     *
     * @return array  ['permisos_creados' => int, 'permisos_actualizados' => int, 'empleados' => int]
     */
    public function aplicar()
    {
        return DB::transaction(function () {

            $permisos_creados = 0;
            $permisos_actualizados = 0;

            // Nombre y grupo que decidió el catálogo (la fuente de verdad de la pantalla de Empleados).
            $filas = PermisosCatalogoHelper::filas();
            $fila = $filas[self::SLUG];

            $existentes = PermissionEmpresa::where('slug', self::SLUG)
                                ->orderBy('id')
                                ->get();

            if (!$existentes->count()) {

                // `forceCreate`: el modelo no declara `$fillable` (igual que `PermisosCatalogoHelper::aplicar()`).
                $permiso = PermissionEmpresa::forceCreate([
                    'slug'          => self::SLUG,
                    'name'          => $fila['nombre'],
                    'model_name'    => $fila['grupo'],
                ]);

                $ids_store = collect([$permiso->id]);
                $permisos_creados = 1;

            } else {

                // Si el slug está repetido se alinean TODAS las filas: los pivots pueden estar
                // colgados de cualquiera de los ids y ninguna se borra. Solo se escribe la que
                // difiere, para que una segunda corrida no cuente ni toque nada.
                foreach ($existentes as $existente) {
                    if ($existente->name != $fila['nombre'] || $existente->model_name != $fila['grupo']) {
                        PermissionEmpresa::where('id', $existente->id)->update([
                            'name'          => $fila['nombre'],
                            'model_name'    => $fila['grupo'],
                        ]);
                        $permisos_actualizados++;
                    }
                }

                $ids_store = $existentes->pluck('id');
            }

            // Si el slug está repetido, el permiso nuevo va al id más bajo (el más viejo).
            $id_destino = $ids_store->first();

            // Todos los ids de `article.index`, incluidos los repetidos de bases viejas.
            $ids_origen = PermissionEmpresa::where('slug', self::SLUG_QUE_LO_HEREDA)->pluck('id');

            if (!$ids_origen->count()) {
                return [
                    'permisos_creados'      => $permisos_creados,
                    'permisos_actualizados' => $permisos_actualizados,
                    'empleados'             => 0,
                ];
            }

            $ids_store_array = $ids_store->all();

            /*
                Usuarios con `article.index` (por cualquiera de sus ids) que todavía no tienen
                `deposit_movement.store` (por cualquiera de sus ids). `distinct`: el que tiene dos
                filas de `article.index` (id repetido) recibe una sola. El `whereIn` contra `users`
                deja afuera los pivots huérfanos de usuarios que ya no existen, para no sumarles
                filas ni contarlos.
            */
            $user_ids = DB::table('permission_empresa_user')
                            ->whereIn('permission_empresa_id', $ids_origen->all())
                            ->whereNotIn('user_id', function ($query) use ($ids_store_array) {
                                $query->select('user_id')
                                      ->from('permission_empresa_user')
                                      ->whereIn('permission_empresa_id', $ids_store_array);
                            })
                            ->whereIn('user_id', function ($query) {
                                $query->select('id')->from('users');
                            })
                            ->distinct()
                            ->orderBy('user_id')
                            ->pluck('user_id');

            $ahora = Carbon::now();

            // Por tandas de 500 para no armar un INSERT gigante en una base con muchos empleados.
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

            return [
                'permisos_creados'      => $permisos_creados,
                'permisos_actualizados' => $permisos_actualizados,
                'empleados'             => $user_ids->count(),
            ];
        });
    }
}
