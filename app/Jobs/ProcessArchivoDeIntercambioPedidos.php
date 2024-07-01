<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\OrderHelper;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessArchivoDeIntercambioPedidos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    
    public $user_id;
    public $timeout = 9999999;
    
    public function __construct($user_id)
    {
        $this->user_id = $user_id;
    }

    
    public function handle()
    {
        $this->guardar_archivo_pedidos();

        $this->guardar_archivo_items();
    }



    /*
        * Se pasan los pedidos desde el inicio del mes anterior hasta la fecha
    */
    function guardar_archivo_pedidos() {
        
        // Obtener los encabezados de las columnas
        $headers = [
            'UiPedido',   
            'CodTipoPedido',   
            'UiVendedor',   
            'CodNego',   
            'CodSucursal',   
            'FecPedido',   
            'CodCondVenta',   
            'Bonificación',   
            'PrecTotal',   
            'FecEstEntrega',   
            'CodObservacion1',   
            'CodObservacion2',   
            'CodObservacion3',   
            'Observación',   
            'TipoVisita',   
            'FecAlta',   
            'CodCircuito',
        ]; 

        // Encabezados
        $tsvData = implode("\t", $headers) . "\n";


        $mes_anterior = Carbon::today()->subMonth();

        $orders = Order::where('user_id', $this->user_id)
                        ->whereDate('created_at', '>=', $mes_anterior->startOfMonth())
                        ->orderBy('created_at', 'ASC')
                        ->get();

        foreach ($orders as $order) {

            $row = [
                $order->id,
                0,
                0,
                $order->buyer->comercio_city_client->num,
                0,
                $order->created_at->format('d/m/Y H:i'),
                0,
                0,
                OrderHelper::get_total($order),
                0,
                0,
                0,
                0,
                $order->description,
                0,
                '',
            ];

            $tsvData .= implode("\t", $row) . "\n";
        }

        Storage::put('archivos-de-intercambio/' . 'PEDIDOS.txt', $tsvData);
    }



    /*
        * Se pasan los pedidos desde el inicio del mes anterior hasta la fecha
    */
    function guardar_archivo_items() {

        // Obtener los encabezados de las columnas
        $headers = [
            'UiPedido',   
            'Item',   
            'CodProducto',   
            'Cantidad',   
            'BonifItem',   
            'PrecPorUnid',   
            'Unidad',   
            'Lista',   
            'Tipo',   
            'Peso',
        ]; 


        // Encabezados
        $tsvData = implode("\t", $headers) . "\n";

        $mes_anterior = Carbon::today()->subMonth();

        $orders = Order::where('user_id', $this->user_id)
                        ->whereDate('created_at', '>=', $mes_anterior->startOfMonth())
                        ->orderBy('created_at', 'ASC')
                        ->get();

        foreach ($orders as $order) {

            $index = 0;

            foreach ($order->articles as $article) {

                $index++;

                $row = [
                    $order->id,
                    $index,
                    $article->provider_code,
                    $article->pivot->amount,
                    1,
                    $article->pivot->price,
                    0,
                    $order->buyer->comercio_city_client->price_type_id,
                ];

                $tsvData .= implode("\t", $row) . "\n";

            }


        }

        Storage::put('archivos-de-intercambio/' . 'ITEMS.txt', $tsvData);
    }
}
