<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Un filtro de columna del listado trae un `key` que no se puede traducir a SQL sin riesgo (misión
 * filtros-key-sin-inyeccion, 5/10/2026): no es un nombre de columna de la tabla del modelo y aun así
 * trae un criterio puesto, o pide ordenar en una dirección que no es ASC ni DESC.
 *
 * El caso que la motivó: `ColumnFiltersHelper::apply()` armaba el "que contenga" con
 * `whereRaw($filter['key'].' LIKE ?')`, sin paréntesis. Un key como "1=1 OR name" dejaba
 * `user_id = <dueño> AND 1=1 OR name LIKE ?` y traía las filas de TODOS los comercios de una base
 * compartida; por las masivas (`PUT api/delete/{model}` y `PUT api/update/{model}` con
 * `from_filter`) las borraba o las modificaba.
 *
 * 🔴 POR QUÉ ES UNA EXCEPCIÓN Y NO UN 422 DEVUELTO. La validación vive ADENTRO del helper, que no
 * tiene cómo devolver una respuesta HTTP, y al helper lo llaman ocho caminos distintos (search,
 * global-search, la exportación a Excel, el PDF del catálogo, el PDF de clientes, la eliminación y la
 * actualización masivas por filtro, y el asistente de IA). Devolver un "resultado inválido" obligaría
 * a cada uno a acordarse de mirarlo, y el que se olvide sigue de largo SIN el filtro: una masiva
 * sobre más filas de las pedidas. La excepción corta la operación entera en cualquiera de los ocho,
 * sin que ninguno tenga que hacer nada (mismo razonamiento que CobroDePresupuestoInvalidoException).
 *
 * Extiende `RuntimeException`: un `catch (\Throwable)` que la atrape antes del handler (los jobs de
 * masivas, el ejecutor del asistente) la trata como un fallo común y corta, que es el piso seguro.
 *
 * PHP 7.4: sin promoción de propiedades en el constructor.
 */
class FiltroDeColumnaInvalidoException extends RuntimeException
{
    /**
     * Mensaje fijo de la respuesta. No repite el key: lo que mandó el pedido no vuelve en la
     * respuesta (va solo al log, recortado).
     */
    const MENSAJE = 'Filtro inválido.';

    /**
     * Largo máximo del key en el log: el key lo arma quien manda el pedido y puede ser enorme.
     */
    const LARGO_MAXIMO_EN_EL_LOG = 120;

    /**
     * El key tal como llegó en el pedido (puede no ser un string).
     *
     * @var mixed
     */
    protected $key_recibido;

    /**
     * Clase Eloquent del modelo que se estaba filtrando.
     *
     * @var string|null
     */
    protected $modelo;

    /**
     * Por qué se rechazó (para el log): 'key_no_es_columna', 'direccion_de_orden', etc.
     *
     * @var string
     */
    protected $motivo;

    /**
     * @param  mixed        $key_recibido  El `key` del filtro, sin tocar.
     * @param  string|null  $modelo        Clase Eloquent del modelo filtrado.
     * @param  string       $motivo        Motivo corto para el log.
     */
    public function __construct($key_recibido, $modelo = null, $motivo = 'key_no_es_columna')
    {
        $this->key_recibido = $key_recibido;
        $this->modelo = $modelo;
        $this->motivo = (string) $motivo;

        parent::__construct(self::MENSAJE);
    }

    /**
     * El key recibido, para quien necesite inspeccionarlo (tests, log).
     *
     * @return mixed
     */
    public function getKeyRecibido()
    {
        return $this->key_recibido;
    }

    /**
     * Respuesta: SIEMPRE JSON 422, aunque el pedido no pida JSON.
     *
     * 🔴 No se usa una ValidationException a propósito: hay entradas que viven en routes/web.php
     * (`client/pdf`, `article/table-pdf`) y que la SPA abre en una pestaña nueva, sin
     * `Accept: application/json`. Ahí el handler de Laravel convertiría una ValidationException en
     * un redirect 302 a la página anterior en vez de un 422.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function render($request)
    {
        return response()->json([
            'message'         => self::MENSAJE,
            'filtro_invalido' => true,
        ], 422);
    }

    /**
     * Reporte: un warning en el log, no un error.
     *
     * Un key que no es columna con un criterio puesto no lo manda la SPA (con un criterio, hoy daba
     * 500 por SQL sobre una columna inexistente): es la huella de alguien probando el endpoint. Se
     * deja constancia sin ensuciar el log de errores ni el reporte automático a GitHub (al definir
     * report(), el handler no llama a sus callbacks de reportable para esta excepción).
     *
     * @return void
     */
    public function report()
    {
        $key = is_scalar($this->key_recibido) ? (string) $this->key_recibido : json_encode($this->key_recibido);

        Log::warning('Filtro de columna rechazado', [
            'motivo' => $this->motivo,
            'modelo' => $this->modelo,
            'key'    => mb_substr((string) $key, 0, self::LARGO_MAXIMO_EN_EL_LOG),
        ]);
    }
}
