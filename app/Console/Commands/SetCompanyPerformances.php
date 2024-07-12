<?php

namespace App\Console\Commands;

use App\Http\Controllers\CompanyPerformanceController;
use Illuminate\Console\Command;

class SetCompanyPerformances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_company_performances';

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

        $ct = new CompanyPerformanceController();

        $user_id = 500;

        $ct->create(2, 2024, $user_id);
        $ct->create(3, 2024, $user_id);
        $ct->create(4, 2024, $user_id);
        $ct->create(5, 2024, $user_id);
        $ct->create(6, 2024, $user_id);
        $ct->create(7, 2024, $user_id);

        echo 'se mando';

        return 0;
    }
}
