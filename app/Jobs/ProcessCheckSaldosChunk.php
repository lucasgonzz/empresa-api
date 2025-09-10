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

            foreach ($client->credit_accounts as $credit_account) {
                CurrentAcountHelper::checkSaldos($credit_account->id);
                CurrentAcountHelper::checkPagos($credit_account->id, true);
            }

        }
    }
}
