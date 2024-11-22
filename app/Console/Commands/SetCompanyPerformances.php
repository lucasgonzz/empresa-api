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
            for ($_mes = 5; $_mes > 0; $_mes--) { 

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

            $users_id = [
                121, // Colman
                228, // HiperMax
                2, // Fenix
            ];

            foreach ($users_id as $_user_id) {

                $this->create_company_performance($ct, $_user_id);
            }

        }


        return 0;
    }

    function create_company_performance($ct, $user_id) {

        $mes = Carbon::now()->subMonths(1)->month;
        $ano = Carbon::now()->subMonths(1)->year;

        // Aca borro los que se estuvieron haciendo durante el mes, asi solo queda el que hago en el siguiente paso
        // $ct->borrar_los_realizados_durante_el_mes($mes, $ano, $user_id);

        $ct->create($mes, $ano, $user_id);
        $this->info('se mando NO historico para user_id '.$user_id);
    }
}
