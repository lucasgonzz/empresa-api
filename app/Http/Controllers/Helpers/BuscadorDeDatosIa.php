<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\SearchController;

/**
 * El buscador general del sistema, con el dueño fijado por parámetro en vez de por sesión.
 *
 * 🔴 POR QUÉ EXISTE. `SearchController::search()` es exactamente lo que necesita la consulta
 * genérica del asistente: ya scopea por `where('user_id', ...)` cuando la tabla lo admite, aplica
 * los filtros de columna por `ColumnFiltersHelper` y pagina. Pero resuelve el dueño con
 * `$this->userId()`, que sale de `UserHelper` y por lo tanto de la sesión o de `Auth` — y el chat
 * del asistente CONSULTA DESDE UN JOB, donde no hay ninguna de las dos. Sin sesión,
 * `UserHelper::userId()` cae a `config('app.USER_ID')`: el buscador contestaría con los datos del
 * comercio que diga esa variable, que no es el que preguntó.
 *
 * La alternativa era hacer `Auth::loginUsingId($owner_id)` alrededor de la llamada (el molde de
 * `AdminSync\AiExcelImportController`), pero eso deja la sesión del proceso tocada mientras dure la
 * consulta y depende de restaurarla bien en todos los caminos de salida, incluido el de excepción.
 * Acá el dueño viaja en el objeto y no hay nada global que restaurar.
 *
 * ⚠️ Esta clase NO relaja ningún filtro: lo único que cambia es de dónde sale el id del dueño. Todo
 * lo demás —el scope, los filtros, el tope de página, el `status = active` de artículos— corre tal
 * cual lo escribió `SearchController`.
 */
class BuscadorDeDatosIa extends SearchController
{
    /**
     * Id del dueño de los datos que se están consultando.
     *
     * @var int
     */
    protected $owner_id;

    /**
     * @param int $owner_id Dueño resuelto por el que llama (en el chat, desde la conversación).
     */
    public function __construct($owner_id)
    {
        $this->owner_id = (int) $owner_id;
    }

    /**
     * El dueño fijo de esta instancia.
     *
     * `query_base_del_modelo()` es privado en el padre pero llama a `$this->userId()`, que es
     * público: la llamada se resuelve contra esta clase y el scope queda apuntando al dueño
     * correcto sin tocar una línea de `SearchController`.
     *
     * El `$from_owner` se ignora a propósito: el asistente consulta SIEMPRE como el dueño, nunca
     * como un empleado. El `$user_id` explícito se respeta porque es el contrato del método.
     *
     * @param  bool      $from_owner
     * @param  int|null  $user_id
     * @return int
     */
    public function userId($from_owner = true, $user_id = null)
    {
        if (! is_null($user_id)) {
            return $user_id;
        }

        return $this->owner_id;
    }
}
