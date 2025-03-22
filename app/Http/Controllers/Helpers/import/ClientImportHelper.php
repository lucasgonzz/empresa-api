<?php

namespace App\Http\Controllers\Helpers\import;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ClientImportHelper {

	static function formateo_golonorte($row, $columns) {

        $direccion = ImportHelper::getColumnValue($row, 'direccion', $columns);
        $res = Self::separar_localidad_de_direccion($direccion);
        
        return $res;

	}

    function separar_localidad_de_direccion($direccion) {

        $localidades = ["Esperanza", "Wanda", "Libertad", "Iguazu"];
        
        foreach ($localidades as $localidad) {

            if (stripos($direccion, $localidad) !== false) { // Verifica si la localidad está en la dirección

                // Elimina la localidad con los guiones y espacios
                $direccionLimpia = trim(str_ireplace(" - $localidad - ", " - ", $direccion));
                
                // Si quedó un guion al principio o final, lo limpiamos
                $direccionLimpia = trim($direccionLimpia, " -");

                // Elimina la localidad y cualquier coma o espacio antes/después
                // $direccionLimpia = trim(preg_replace("/\s*,?\s*$localidad\s*,?/i", "", $direccion));
                return [
                    "direccion" => $direccionLimpia, 
                    "localidad" => $localidad
                ];
            }
        }
        
        // Si no se encuentra una localidad, devuelve la dirección original sin modificar
        return ["direccion" => $direccion, "localidad" => null];
    }

	
}