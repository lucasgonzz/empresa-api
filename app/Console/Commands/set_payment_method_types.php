<?php

namespace App\Console\Commands;

use App\Models\CurrentAcountPaymentMethod;
use Illuminate\Console\Command;

class set_payment_method_types extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_payment_method_types';

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
        $payment_methods = CurrentAcountPaymentMethod::all();

        foreach ($payment_methods as $payment_method) {
            if ($payment_method->id == 1) {
                $payment_method->c_a_payment_method_type_id = 2;
            } 

            if ($payment_method->id == 5) {
                $payment_method->c_a_payment_method_type_id = 1;
            } 

            $payment_method->timestamps = false;
            $payment_method->save();
        }

        $this->info('Listo');

        return 0;
    }
}
