<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCheckSaldosChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $clients;

    public $timeout = 3600;

    public function __construct($clients)
    {
        $this->clients = $clients;
    }

    public function handle()
    {
        $saldos_diferentes = 0;

        foreach ($this->clients as $client) {
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
