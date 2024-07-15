<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCheckInventoryLinkages;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckInventoryLinkages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_inventory_linkages';

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
        $user = User::where('company_name', 'Fenix')
                        ->first();

        Log::info('Se llamo ProcessCheckInventoryLinkages desde comando');

        ProcessCheckInventoryLinkages::dispatch($user);

        return 0;
    }
}
