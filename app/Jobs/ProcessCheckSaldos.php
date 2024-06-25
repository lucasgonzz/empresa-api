<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCheckSaldos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $user;

    public $timeout = 99999999;

    public function __construct($user)
    {
        $this->user = $user;
    }

  
    public function handle()
    {
        $clients = Client::where('user_id', $this->user->id)
                            ->where('id', '!=', 7334)
                            ->get();

        Log::info('checkSaldos para '.count($clients).' clientes');

        $saldos_diferentes = 0;

        foreach ($clients as $client) {
            Log::info('chequeando saldo y pagos de '.$client->name);
            $saldo_anterior = $client->saldo;
            $client = CurrentAcountHelper::checkSaldos('client', $client->id);
            CurrentAcountHelper::checkPagos('client', $client->id, true);
            $nuevo_saldo = $client->saldo;

            if ($saldo_anterior != $nuevo_saldo) {
                Log::info('Tenia el saldo diferente');
                $saldos_diferentes++;
            }
        }

        Log::info('habia '.$saldos_diferentes.' saldos diferentes');
    }
}
