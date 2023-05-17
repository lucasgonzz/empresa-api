<?php

namespace App\Imports;

use App\Http\Controllers\Controller;
use App\Models\Localidad;
use App\Models\Provincia;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Facades\Log;

class LocationImport implements ToCollection
{
    /**
    * @param Collection $collection
    */
    public function collection(Collection $rows)
    {
        set_time_limit(0);
        $num_provincia = 1;
        $num_localidad = 1;

        $ct = new Controller();
        Log::info('hay: '.count($rows));
        foreach ($rows as $row) {
            if ($row[0] != 'id' && !is_null($row[2])) {
                $localidad = Localidad::create([
                    'num'           => $num_localidad,
                    'nombre'        => $row[2],
                    'codigo_postal' => $row[3],
                    'provincia_id'  => $row[4],
                    'user_id'       => 1,
                ]);
                Log::info('se creo la localidad: '.$localidad->nombre);
                $num_localidad++;
            }
        }
        Log::info('Ultima provincia: '.$num_provincia);
        Log::info('Ultima localidad: '.$num_localidad);
        Log::info('-----------------------------------');
    }
}
