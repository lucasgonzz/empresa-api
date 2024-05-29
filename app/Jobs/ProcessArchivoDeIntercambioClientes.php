<?php

namespace App\Jobs;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\Client;
use App\Models\Location;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessArchivoDeIntercambioClientes implements ShouldQueue
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
        $this->init_locations();

        $this->leer_archivo();
    }


    function leer_archivo() {
        $file = Storage::get('/archivos-de-intercambio/CLIENTES.txt');
        $lines = explode("\n", $file);

        foreach ($lines as $line) {
            $data = explode("\t", $line);

            if ($data[0] != 'Codigo' && $data[0] != '') {

                Log::info('Entro con $data:');
                Log::info($data);

                $address = !is_null($data[2]) ? $this->convert_to_utf8($data[2]) : null;


                if (!is_null($data[3])) {
                    $location_id = $this->get_location_id($data[3]);
                } else {
                    $location_id = null;
                }

                $client = [
                    'num'                   => (float)$data[0],
                    'name'                  => $this->convert_to_utf8($data[1]),
                    'address'               => $address,
                    'location_id'           => $location_id,
                    'phone'                 => $this->convert_to_utf8($data[4]),
                    'cuit'                  => $data[11],
                    'price_type_id'         => (float)$data[13],
                ];

                $client_ya_creado = $this->cliente_registrado($client);

                if (!is_null($client_ya_creado)) {

                    $client_ya_creado->update($client);

                    Log::info('Se actualizo cliente con num '.$client_ya_creado->num);

                } else {

                    $client['user_id']    = $this->user_id;

                    Log::info('Se va a crear cliente con:');
                    Log::info($client);

                    $client = Client::create($client);
                    Log::info('Se CREO cliente con num '.$client->num);

                }
            }

        }
    }

    function init_locations() {
        $this->locations = Location::where('user_id', $this->user_id)
                                    ->get();
    }

    function get_location_id($location_name) {
        $location = $this->locations->firstWhere('name', $location_name);

        if (!$location) {
            $location = Location::create([
                'name'      => $this->convert_to_utf8($location_name),
                'user_id'   => $this->user_id,
            ]);
            $this->locations->push($location);
        }
        return $location->id;
    }

    function cliente_registrado($client) {
        $client = Client::where('user_id', $this->user_id)
                            ->where('num', $client['num'])
                            ->first();

        return $client;
    }


    function convert_to_utf8($string) {
        return utf8_encode($string);
    }
}
