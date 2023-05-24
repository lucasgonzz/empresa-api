<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\AfipTicket;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AfipTicketSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $sale = Sale::find(3);
        AfipTicket::create([
            'cuit_negocio'      => '204323235600',
            'iva_negocio'       => 'Responsable inscripto',
            'punto_venta'       => 4,
            'cbte_numero'       => '34',
            'cbte_letra'        => 'A',
            'cbte_tipo'         => 1,
            'importe_total'     => SaleHelper::getTotalSale($sale),
            'moneda_id'         => 'PES',
            'resultado'         => 'APRO',
            'concepto'          => 'asd',
            'cuit_cliente'      => 'NR',
            'iva_cliente'       => '',
            'cae'               => '391283947328234',
            'cae_expired_at'    => Carbon::now(),
            'sale_id'           => $sale->id,
        ]);
    }
}
