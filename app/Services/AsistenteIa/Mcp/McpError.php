<?php

namespace App\Services\AsistenteIa\Mcp;

/**
 * Un error JSON-RPC 2.0 del servidor MCP (misión asistente-mcp, 22/9/2026).
 *
 * Los métodos de McpServidor lo lanzan cuando la respuesta a un mensaje tiene que ser un `error`
 * y no un `result`; McpController lo atrapa y lo serializa como `{code, message[, data]}`. Es una
 * excepción propia y no un array de retorno para que el camino feliz de cada método devuelva el
 * `result` pelado y el camino de error no se pueda confundir con un resultado válido.
 *
 * Códigos que usa el servidor (los reservados de JSON-RPC más los del rango de la spec de MCP):
 *   -32700 parse error (el cuerpo no es JSON)          -32600 invalid request (sin method, versión no soportada)
 *   -32601 método desconocido                          -32602 params inválidos (tool desconocida, sesión ajena)
 *   -32603 error interno (sin stack en la respuesta)   -32001 sesión inexistente (HTTP 404)
 *   -32002 recurso inexistente
 *
 * PHP 7.4: sin promoción de constructor ni tipos de propiedad.
 */
class McpError extends \Exception
{
    /** @var int El `code` JSON-RPC. */
    public $codigo;

    /** @var mixed Lo que viaja en `data`, o null para omitirla. */
    public $data;

    /**
     * @param  int  $codigo
     * @param  string  $mensaje
     * @param  mixed  $data
     */
    public function __construct($codigo, $mensaje, $data = null)
    {
        parent::__construct((string) $mensaje, (int) $codigo);

        $this->codigo = (int) $codigo;
        $this->data = $data;
    }

    /**
     * El objeto `error` de la respuesta JSON-RPC. `data` solo va cuando la hay: la spec la declara
     * opcional y un cliente estricto no tiene por qué tolerar un null.
     *
     * @return array<string, mixed>
     */
    public function a_json_rpc(): array
    {
        $error = [
            'code'    => $this->codigo,
            'message' => $this->getMessage(),
        ];

        if (!is_null($this->data)) {

            $error['data'] = $this->data;
        }

        return $error;
    }
}
