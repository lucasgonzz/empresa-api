<?php

namespace App\Console\Commands;

use App\Models\IvaCondition;
use Illuminate\Console\Command;

class set_iva_condition_slugs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_iva_condition_slugs';

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

        IvaCondition::where('name', 'Responsable inscripto')->update([
            'slug'  => 'RRII',
        ]); 

        IvaCondition::where('name', 'Monotributista')->update([
            'slug'  => 'MT',
        ]); 

        IvaCondition::where('name', 'Consumidor final')->update([
            'slug'  => 'C.F',
        ]); 

        IvaCondition::where('name', 'Exento')->update([
            'slug'  => 'EX',
        ]); 

        $this->info('Listo');

        return 0;
    }
}
