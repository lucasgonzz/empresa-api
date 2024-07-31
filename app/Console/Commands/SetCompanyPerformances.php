<?php

namespace App\Console\Commands;

use App\Http\Controllers\CompanyPerformanceController;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SetCompanyPerformances extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_company_performances {user_id=500} {--historico}';

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

        $user_id = $this->argument('user_id');
        $historico = $this->option('historico');

        $ct = new CompanyPerformanceController();

        if ($historico) {

            /*
                * Aca hago solo hasta el mes anterior
                * Si lo corro a fines de Julio, lo hago hasta Junio
            */

            $currentDate = Carbon::today()->startOfMonth();
            for ($_mes = 40; $_mes > 0; $_mes--) { 

                $this->comment('$_mes = '.$_mes);

                $date = $currentDate->copy()->subMonths($_mes); // Usar copy() para evitar modificar la instancia original
                $mes = $date->month;
                $ano = $date->year;

                $ct->create($mes, $ano, $user_id);
                
                $this->info('Se mando del '.$mes.'/'.$ano);
                $this->info('');
            }

            $this->info('Termino');

        } else {

            /*
                * Este es el que se llama desde el Cron a principos de mes
            */

            $mes = Carbon::now()->subMonths(1)->month;
            $ano = Carbon::now()->subMonths(1)->year;

            $ct->create($mes, $ano, $user_id);
            $this->info('se mando NO historico');
        }


        return 0;
    }
}
