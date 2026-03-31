<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Sincroniza users.listas_de_precio a partir de la extensión legacy de listas de precio.
 */
class CheckExtencionListasDePrecios extends Command
{
    /**
     * Slug histórico de la extensión que habilitaba márgenes por lista de precio.
     */
    const SLUG_EXTENCION_LISTAS = 'articulo_margen_de_ganancia_segun_lista_de_precios';

    /**
     * Nombre y firma del comando de consola.
     *
     * @var string
     */
    protected $signature = 'check_extencion_listas_de_precios';

    /**
     * Descripción breve del comando.
     *
     * @var string
     */
    protected $description = 'Activa listas_de_precio en el usuario dueño si tiene la extensión antigua de listas de precio';

    /**
     * Crea la instancia del comando.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Ejecuta la sincronización extensión → flag listas_de_precio en owners.
     *
     * @return int
     */
    public function handle()
    {
        // Usuarios (cualquier fila users) que tienen la extensión legacy en el pivot.
        $users_con_extencion = User::whereHas('extencions', function ($query) {
            $query->where('slug', self::SLUG_EXTENCION_LISTAS);
        })->get();

        if ($users_con_extencion->isEmpty()) {
            $this->info('Ningún usuario tiene la extensión de listas de precio.');

            return 0;
        }

        // IDs de dueño únicos: si el user es empleado, el flag vive en el owner.
        $owner_ids = [];
        foreach ($users_con_extencion as $user) {
            $owner_id = $user->owner_id ? (int) $user->owner_id : (int) $user->id;
            $owner_ids[$owner_id] = true;
        }

        $owner_ids_list = array_keys($owner_ids);

        // Actualiza solo dueños que aún no tienen el flag (evita writes innecesarios).
        $actualizados = User::whereIn('id', $owner_ids_list)
            ->where(function ($query) {
                $query->whereNull('listas_de_precio')
                    ->orWhere('listas_de_precio', 0)
                    ->orWhere('listas_de_precio', false);
            })
            ->update(['listas_de_precio' => 1]);

        $this->info('Dueños únicos detectados: '.count($owner_ids_list));
        $this->info('Registros actualizados (listas_de_precio = 1): '.$actualizados);

        return 0;
    }
}
