<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * La consulta al padrón de ARCA desde el asistente pasó su tope de tiempo (PadronDeArcaIaHelper::con_tope()).
 * La ataja PadronDeArcaIaHelper::datos_del_cuit(): la tarjeta dice que el padrón no contestó a tiempo y la razón
 * social se le pregunta a la persona. Nunca sale de ahí.
 */
class PadronFueraDeTiempoException extends \RuntimeException
{
}
