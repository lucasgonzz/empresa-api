<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Peso y medidas de UNA unidad del paquete completo, incluido su embalaje. */
class MedidasEnvioHelper
{
    const CAMPOS = ['peso', 'alto', 'ancho', 'profundidad'];
    const ETIQUETAS = ['peso' => 'Peso (kg)', 'alto' => 'Alto (cm)', 'ancho' => 'Ancho (cm)', 'profundidad' => 'Largo / profundidad (cm)'];

    /** Omitido conserva, null limpia. La tabla lateral conserva compatible el SELECT * de las APIs anteriores. */
    public static function datos(array $cuerpo)
    {
        $datos = [];
        foreach (self::CAMPOS as $campo) {
            if (!array_key_exists($campo, $cuerpo)) {
                continue;
            }
            $valor = $cuerpo[$campo];
            if ($valor !== null && (!is_scalar($valor) || is_bool($valor)
                || !preg_match('/^[0-9]{1,26}(?:\.[0-9]{1,4})?$/D', (string) $valor))) {
                throw ValidationException::withMessages([$campo => [
                    'Ingresá '.self::ETIQUETAS[$campo].' como un número mayor o igual a cero, con hasta cuatro decimales, o dejalo vacío.',
                ]]);
            }
            if (Schema::hasTable('medidas_envio_paquetes')) {
                $datos[$campo] = $valor;
            }
        }
        return $datos;
    }
}
