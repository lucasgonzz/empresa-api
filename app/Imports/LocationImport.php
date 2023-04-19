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
        $num_provincia = 1;
        $num_localidad = 1;

        $last_provincia = Provincia::orderBy('num', 'DESC')->first();
        if (!is_null($last_provincia)) {
            $num_provincia = $last_provincia->num;
            $num_provincia++;
            Log::info('num_provincia: '.$num_provincia);
        }
        $last_localidad = Localidad::orderBy('num', 'DESC')->first();
        if (!is_null($last_localidad)) {
            $num_localidad = $last_localidad->num;
            $num_localidad++;
            Log::info('num_localidad: '.$num_localidad);
        }

        $ct = new Controller();
        Log::info('hay: '.count($rows));
        foreach ($rows as $row) {
            if (!is_null($row[0]) && !is_null($row[2])) {
                $provincia = $ct->getModelBy('provincias', 'nombre', $row[0]);
                if (is_null($provincia)) {
                    $provincia = Provincia::create([
                        'num'       => $num_provincia,
                        'nombre'    => $row[0],
                        'user_id'   => 1,
                    ]);
                    $num_provincia++;
                    Log::info('se creo provincia nu: '.$num_provincia);
                }
                Localidad::create([
                    'num'           => $num_localidad,
                    'nombre'        => $row[1],
                    'codigo_postal' => $row[2],
                    'provincia_id'  => $provincia->id,
                    'user_id'       => 1,
                ]);
                $num_localidad++;
            }
        }
        Log::info('Ultima provincia: '.$num_provincia);
        Log::info('Ultima localidad: '.$num_localidad);
        Log::info('-----------------------------------');
    }
}
