<?php

namespace App\Console\Commands;

use App\Models\CreditAccount;
use App\Models\DebtSnapshot;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Comando Artisan que captura el snapshot de deuda del día para todos los dueños de negocio.
 * Se ejecuta automáticamente a las 23:59 todos los días desde el Kernel.
 *
 * Por cada usuario owner (owner_id null) calcula:
 * - Suma de saldos de credit_accounts de clientes en ARS y USD
 * - Suma de saldos de credit_accounts de proveedores en ARS y USD
 * Luego hace updateOrCreate en DebtSnapshot para hoy.
 */
class TakeDebtSnapshot extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'debt:snapshot';

    /**
     * Descripción del comando para php artisan list.
     *
     * @var string
     */
    protected $description = 'Captura el snapshot de deuda (clientes y proveedores) del día para todos los dueños de negocio';

    /**
     * Crea una nueva instancia del comando.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Ejecuta el comando: itera todos los usuarios owner y genera un snapshot de deuda para hoy.
     *
     * @return int Código de salida (0 = OK)
     */
    public function handle()
    {
        /* Obtener todos los usuarios dueños de negocio (owner_id null = no son empleados) */
        $owners = User::whereNull('owner_id')
            ->select('id')
            ->orderBy('id')
            ->get();

        $this->info('Iniciando debt:snapshot para ' . $owners->count() . ' usuarios...');

        foreach ($owners as $owner) {

            $this->process_user($owner->id);
        }

        $this->info('debt:snapshot finalizado.');

        return 0;
    }

    /**
     * Calcula y guarda el snapshot de deuda del día para un usuario específico.
     * Suma los saldos de credit_accounts separando por model_name y moneda_id.
     *
     * @param int $user_id ID del usuario dueño del negocio
     * @return void
     */
    protected function process_user($user_id)
    {
        /* Suma de saldos de clientes en ARS (moneda_id = 1) */
        $deuda_clientes = CreditAccount::where('user_id', $user_id)
            ->where('model_name', 'client')
            ->where('moneda_id', 1)
            ->sum('saldo');

        /* Suma de saldos de clientes en USD (moneda_id = 2) */
        $deuda_clientes_usd = CreditAccount::where('user_id', $user_id)
            ->where('model_name', 'client')
            ->where('moneda_id', 2)
            ->sum('saldo');

        /* Suma de saldos de proveedores en ARS (moneda_id = 1) */
        $deuda_proveedores = CreditAccount::where('user_id', $user_id)
            ->where('model_name', 'provider')
            ->where('moneda_id', 1)
            ->sum('saldo');

        /* Suma de saldos de proveedores en USD (moneda_id = 2) */
        $deuda_proveedores_usd = CreditAccount::where('user_id', $user_id)
            ->where('model_name', 'provider')
            ->where('moneda_id', 2)
            ->sum('saldo');

        /* Guardar o actualizar el snapshot del día (evita duplicados por el índice único user_id + date) */
        DebtSnapshot::updateOrCreate(
            [
                'user_id' => $user_id,
                'date'    => today()->toDateString(),
            ],
            [
                'deuda_clientes'        => $deuda_clientes,
                'deuda_clientes_usd'    => $deuda_clientes_usd,
                'deuda_proveedores'     => $deuda_proveedores,
                'deuda_proveedores_usd' => $deuda_proveedores_usd,
            ]
        );

        $this->info('Snapshot guardado para user_id ' . $user_id . ': clientes=' . $deuda_clientes . ', proveedores=' . $deuda_proveedores);
    }
}
