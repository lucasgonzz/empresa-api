<?php

namespace App\Imports;

use App\Models\Provincia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;

class ProvinciaImport implements ToCollection
{
    /**
    * @param Collection $collection
    */
    public function collection(Collection $rows)
    {
        set_time_limit(0);
        $num_provincia = 1;

        Log::info('hay: '.count($rows));
        foreach ($rows as $row) {
            $provincia = Provincia::create([
                'num'       => $num_provincia,
                'nombre'    => $row[1],
                'user_id'   => 1,
            ]);
            $provincia->id = $row[0];
            $provincia->save();
            $num_provincia++;
        }
        Log::info('Ultima provincia: '.$num_provincia);
        Log::info('-----------------------------------');
    }
}
