<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Http\Controllers\SaleController;
use App\Models\Client;
use App\Models\Mantenimiento;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckClientsSaldos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_clients_saldos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        foreach (explode(',', env('USERS_ID_CHECK_CLIENTS_SALDOS')) as $user_id) {
            
            $user = User::find($user_id);

            $this->info('Negocio: '.$user->company_name);

            $sales = Sale::where('user_id', $user_id)
                        ->whereHas('current_acount', function($q) {
                            return $q->where('debe', '>', 0)
                                        ->where('status', 'sin_pagar')
                                        ->orWhere('status', 'pagandose')
                                        ->where(function ($query) {
                                            $query->whereNull('pagandose')
                                            ->orWhereRaw('debe - pagandose > 300');
                                        });
                        })
                        ->orderBy('created_at', 'DESC')
                        ->get();

            $clients = VentasSinCobrarHelper::ordenar_por_clientes($sales);

            // $clients = Client::where('user_id', $user_id)
            //                     ->whereDate('updated_at', '>=', Carbon::today()->subDays(5))
            //                     ->get();
            
            $saldos_diferentes = 0;
            $clientes_chequeados = 0;

            foreach ($clients as $client) {

                $clientes_chequeados++;

                if (!is_null($client['client'])
                    && $client['client']->id != 7334) {

                    $this->info($client['client']->name);

                    $saldo_anterior = $client['client']->saldo;
                    $client['client'] = CurrentAcountHelper::checkSaldos('client', $client['client']->id);
                    CurrentAcountHelper::checkPagos('client', $client['client']->id, true);
                    $nuevo_saldo = $client['client']->saldo;

                    if ($saldo_anterior != $nuevo_saldo) {
                        $saldos_diferentes++;
                    }
                    $this->info('Se checkeo pagos de '.$client['client']->name);
                }

            }

            Mantenimiento::create([
                'notas'     => 'Se chequearon saldos de los clientes de '.$user->company_name.'. Clientes chequeados: '.$clientes_chequeados.'. Saldos diferentes: '.$saldos_diferentes,
                'user_id'   => $user->id,
            ]);                                
        }

        $this->info('Termino');

        return 0;
    }
}
