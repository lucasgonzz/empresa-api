<?php

namespace App\Services\AsistenteIa\Mcp;

use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;

/**
 * El registro de tools del servidor MCP (misión asistente-mcp, 22/9/2026): las mismas herramientas
 * que usa el asistente de la casa, traducidas a la forma que pide el protocolo.
 *
 * 🔴 NO SE ESCRIBE NINGUNA TOOL ACÁ. La lista sale de las dos puntas que ya existen —
 * AsistenteIaService::herramientas_de_lectura() (las consultas) y HerramientasDeCarga::definiciones(true)
 * (las cargas, con las del canal sin botones: confirmar_ y cancelar_carga_pendiente)— y lo único
 * que hace este helper es cambiar `input_schema` por `inputSchema`, sumar `title` y `annotations`,
 * y sacar `proponer_compra_con_factura`, que necesita las fotos que llegan por WhatsApp y acá no
 * hay ninguna. Una tool que se sume a cualquiera de las dos listas entra al MCP sola: si otra misión
 * agrega cuatro al final de HerramientasDeCarga, tools/list las gana en ese momento sin tocar nada.
 *
 * 🔴 NO REORDENA NADA. El orden de las dos listas es parte del caché de prompt del loop interno
 * (ver AsistenteIaService::build_tools()), y acá se respeta el mismo para que el test que compara
 * tools/list contra nombres_de_lectura() + nombres(true) sea una igualdad y no un "contiene".
 *
 * Las `annotations` son pistas para el cliente MCP (la spec las declara no confiables para
 * seguridad, son para la interfaz: qué pedir confirmar, qué marcar como lectura): `readOnlyHint`
 * para lo que solo consulta, `destructiveHint` para lo que borra o reescribe de a muchos, y
 * `openWorldHint: false` en todas salvo las de SALEN_A_INTERNET (desde el 24/9/2026, la búsqueda
 * por código de barras), que son las únicas que salen del negocio de este dueño.
 */
class McpHerramientasHelper
{
    /**
     * Las tools de HerramientasDeCarga::definiciones(true) que el MCP NO declara, con el motivo:
     * proponer_compra_con_factura arma la compra con las fotos que el dueño mandó por WhatsApp en
     * esa conversación; un cliente MCP no manda fotos, así que solo podría contestar "no tengo
     * ninguna foto".
     */
    const EXCLUIDAS = ['proponer_compra_con_factura'];

    /** Prefijos de las tools que solo leen: van con readOnlyHint = true. */
    const PREFIJOS_DE_LECTURA = ['consultar_', 'que_puedo_', 'resumir_', 'mostrar_', 'contar_'];

    /**
     * Las tools que salen a internet: openWorldHint = true. La búsqueda por código de barras
     * (misión asistente-fotos-barras-y-compras, 24/9/2026) consulta Open Food Facts y la búsqueda
     * web de Anthropic: es la única que no se queda adentro del negocio del dueño.
     */
    const SALEN_A_INTERNET = [
        'buscar_producto_por_codigo_de_barras',
    ];

    /**
     * Las tools que borran o reescriben de a muchos: destructiveHint = true. Un nombre que todavía
     * no exista en las listas (proponer_borrado_por_pantalla lo suma otra misión) no molesta: la
     * pista se aplica solo a las que están.
     */
    const DESTRUCTIVAS = [
        'proponer_baja',
        'proponer_actualizacion_masiva',
        'proponer_unificar_bancos_de_cheques',
        'proponer_borrado_por_pantalla',
    ];

    /**
     * Las definiciones en la forma MCP, en el orden de origen: lectura primero, carga después.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definiciones(): array
    {
        $definiciones = [];

        foreach (self::definiciones_de_origen() as $definicion) {

            $definiciones[] = self::traducir($definicion);
        }

        return $definiciones;
    }

    /**
     * Los nombres de las tools que declara el MCP, en el mismo orden que definiciones().
     *
     * @return array<int, string>
     */
    public static function nombres(): array
    {
        return array_column(self::definiciones_de_origen(), 'name');
    }

    /**
     * true si el MCP declara esa tool. Es lo que tools/call mira ANTES de despachar: una tool que
     * existe en HerramientasDeCarga pero está en EXCLUIDAS tampoco se puede llamar.
     *
     * @param  string  $name
     * @return bool
     */
    public static function existe($name): bool
    {
        return in_array((string) $name, self::nombres(), true);
    }

    /**
     * Las definiciones tal como viven en el asistente (forma de la API de Anthropic), ya filtradas
     * por EXCLUIDAS y sin reordenar.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function definiciones_de_origen(): array
    {
        $servicio = new AsistenteIaService();

        $definiciones = $servicio->herramientas_de_lectura();

        foreach (HerramientasDeCarga::definiciones(true) as $definicion) {

            if (in_array((string) $definicion['name'], self::EXCLUIDAS, true)) {

                continue;
            }

            $definiciones[] = $definicion;
        }

        return $definiciones;
    }

    /**
     * Una definición de la API de Anthropic → una tool MCP.
     *
     * @param  array<string, mixed>  $definicion  {name, description, input_schema}
     * @return array<string, mixed>  {name, title, description, inputSchema, annotations}
     */
    protected static function traducir(array $definicion): array
    {
        $name = (string) $definicion['name'];

        $input_schema = isset($definicion['input_schema']) && is_array($definicion['input_schema'])
            ? $definicion['input_schema']
            : ['type' => 'object'];

        return [
            'name'        => $name,
            'title'       => self::titulo($name),
            'description' => (string) ($definicion['description'] ?? ''),
            'inputSchema' => self::con_properties_como_objeto($input_schema),
            'annotations' => [
                'readOnlyHint'    => self::es_de_lectura($name),
                'destructiveHint' => in_array($name, self::DESTRUCTIVAS, true),
                'openWorldHint'   => in_array($name, self::SALEN_A_INTERNET, true),
            ],
        ];
    }

    /**
     * "consultar_stock_de_articulos" → "Consultar stock de articulos": el name con espacios y la
     * primera en mayúscula, que es lo que un cliente MCP muestra en su lista.
     *
     * @param  string  $name
     * @return string
     */
    protected static function titulo($name): string
    {
        return ucfirst(str_replace('_', ' ', (string) $name));
    }

    /**
     * true si el nombre empieza con alguno de los prefijos de lectura.
     *
     * @param  string  $name
     * @return bool
     */
    protected static function es_de_lectura($name): bool
    {
        foreach (self::PREFIJOS_DE_LECTURA as $prefijo) {

            if (strpos((string) $name, $prefijo) === 0) {

                return true;
            }
        }

        return false;
    }

    /**
     * 🔴 `properties` vacío tiene que viajar como `{}` y nunca como `[]`. En JSON Schema
     * `properties` es SIEMPRE un objeto, y json_encode de un array PHP vacío da `[]`: un cliente
     * MCP que valide el esquema (Claude Desktop lo hace) rechaza la tool entera. Es el mismo
     * cuidado que AsistenteIaService::normalize_assistant_content_for_api() tiene con `input:{}`.
     *
     * Se recorre en profundidad y no solo el nivel de arriba porque un argumento de tipo object sin
     * campos declarados tiene el mismo problema un nivel más abajo. Solo se toca la clave
     * `properties`: `required` vacío es una lista y está bien como `[]`.
     *
     * @param  array<string, mixed>  $esquema
     * @return array<string, mixed>
     */
    protected static function con_properties_como_objeto(array $esquema): array
    {
        foreach ($esquema as $clave => $valor) {

            if ($clave === 'properties' && is_array($valor) && count($valor) === 0) {

                $esquema[$clave] = new \stdClass();

                continue;
            }

            if (is_array($valor)) {

                $esquema[$clave] = self::con_properties_como_objeto($valor);
            }
        }

        return $esquema;
    }
}
