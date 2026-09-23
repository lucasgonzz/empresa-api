<?php

namespace App\Services\AsistenteIa\Mcp;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Models\User;
use Carbon\Carbon;

/**
 * Los recursos del servidor MCP (misión asistente-mcp, 22/9/2026): el ESQUEMA del negocio, para
 * que un cliente MCP sepa qué se puede consultar y qué se puede cargar antes de llamar a una tool.
 *
 *   comerciocity://negocio               quién es este negocio y cómo está configurado su asistente
 *   comerciocity://consultas             la lista corta de entidades consultables (que_puedo_consultar)
 *   comerciocity://consultas/{entidad}   los campos, operadores y condiciones de UNA entidad
 *   comerciocity://cargas                la lista corta de entidades escribibles (que_puedo_cargar)
 *   comerciocity://cargas/{entidad}      los campos por operación de UNA entidad
 *
 * 🔴 SON EL MISMO CATÁLOGO QUE LAS TOOLS que_puedo_consultar Y que_puedo_cargar, no una copia:
 * leer() delega en CatalogoDeDatosIaHelper y CatalogoDeEscrituraIaHelper. Lo que gana el cliente es
 * poder descubrirlo como recurso (y cachearlo) sin gastar una llamada de tool por entidad.
 *
 * 🔴 NINGÚN RECURSO LEE DATOS DEL NEGOCIO: son esquema y configuración. Por eso acá no hay más
 * tenencia que el gate de la ruta (auth + extensión + solo el dueño); lo único que depende del
 * dueño es `negocio`, y eso es su propia ficha.
 *
 * Todos van con mimeType application/json y el `text` es el JSON del array.
 */
class McpRecursosHelper
{
    /** Esquema de URI de los recursos de este servidor. */
    const ESQUEMA = 'comerciocity://';

    /** Todos los recursos son JSON. */
    const MIME = 'application/json';

    /** Moneda principal de todo negocio del sistema (los dólares van marcados aparte). */
    const MONEDA_PRINCIPAL = 'ARS';

    /**
     * La lista completa de recursos, para resources/list.
     *
     * @return array<int, array<string, string>>
     */
    public static function lista(): array
    {
        $recursos = [
            self::recurso('negocio', 'El negocio', 'Nombre, moneda, extensiones activas, modo de confianza del asistente, proveedor de IA elegido y la fecha de hoy.'),
            self::recurso('consultas', 'Qué se puede consultar', 'La lista corta de las entidades que se pueden leer con consultar_datos y resumir_datos, agrupadas por módulo. Es lo mismo que devuelve que_puedo_consultar sin entidad.'),
        ];

        foreach (EsquemaDeDatosIaHelper::entidades() as $entidad) {

            $declaracion = EsquemaDeDatosIaHelper::declaracion($entidad);

            $etiqueta = is_array($declaracion) && isset($declaracion['etiqueta']) ? (string) $declaracion['etiqueta'] : $entidad;

            $recursos[] = self::recurso(
                'consultas/' . $entidad,
                'Consultar: ' . $etiqueta,
                'Los campos de "' . $etiqueta . '" (' . $entidad . ') con su tipo y sus operadores, sus condiciones fijas y qué campos viajan por defecto. Es lo mismo que devuelve que_puedo_consultar con esta entidad.'
            );
        }

        $recursos[] = self::recurso('cargas', 'Qué se puede cargar', 'La lista corta de las entidades que se pueden dar de alta, editar o borrar con proponer_alta, proponer_edicion y proponer_baja. Es lo mismo que devuelve que_puedo_cargar sin entidad.');

        foreach (CatalogoDeEscrituraIaHelper::entidades() as $entidad => $datos) {

            $etiqueta = is_array($datos) && isset($datos['etiqueta']) ? (string) $datos['etiqueta'] : (string) $entidad;

            $recursos[] = self::recurso(
                'cargas/' . $entidad,
                'Cargar: ' . $etiqueta,
                'Los campos por operación de "' . $etiqueta . '" (' . $entidad . ') y cómo se ubica un registro. Es lo mismo que devuelve que_puedo_cargar con esta entidad.'
            );
        }

        return $recursos;
    }

    /**
     * Las dos plantillas, para resources/templates/list.
     *
     * @return array<int, array<string, string>>
     */
    public static function plantillas(): array
    {
        return [
            [
                'uriTemplate' => self::ESQUEMA . 'consultas/{entidad}',
                'name'        => 'consultas',
                'title'       => 'Esquema de una entidad consultable',
                'description' => 'Los campos, operadores y condiciones de una entidad de comerciocity://consultas. {entidad} es el nombre tal como aparece ahí (por ejemplo "article").',
                'mimeType'    => self::MIME,
            ],
            [
                'uriTemplate' => self::ESQUEMA . 'cargas/{entidad}',
                'name'        => 'cargas',
                'title'       => 'Esquema de una entidad escribible',
                'description' => 'Los campos por operación de una entidad de comerciocity://cargas. {entidad} es el nombre tal como aparece ahí.',
                'mimeType'    => self::MIME,
            ],
        ];
    }

    /**
     * El contenido de un recurso (el array que va como JSON en `text`), o null si el URI no existe.
     *
     * @param  string  $uri
     * @param  \App\Models\User  $dueno
     * @return array<string, mixed>|null
     */
    public static function leer($uri, User $dueno)
    {
        $uri = trim((string) $uri);

        if (strpos($uri, self::ESQUEMA) !== 0) {

            return null;
        }

        $ruta = substr($uri, strlen(self::ESQUEMA));

        if ($ruta === 'negocio') {

            return self::negocio($dueno);
        }

        if ($ruta === 'consultas') {

            return CatalogoDeDatosIaHelper::que_puedo_consultar();
        }

        if ($ruta === 'cargas') {

            return CatalogoDeEscrituraIaHelper::que_puedo_cargar();
        }

        if (strpos($ruta, 'consultas/') === 0) {

            $entidad = substr($ruta, strlen('consultas/'));

            if ($entidad === '' || !EsquemaDeDatosIaHelper::existe($entidad)) {

                return null;
            }

            return CatalogoDeDatosIaHelper::que_puedo_consultar($entidad);
        }

        if (strpos($ruta, 'cargas/') === 0) {

            $entidad = substr($ruta, strlen('cargas/'));

            if ($entidad === '' || !array_key_exists($entidad, CatalogoDeEscrituraIaHelper::entidades())) {

                return null;
            }

            return CatalogoDeEscrituraIaHelper::que_puedo_cargar($entidad);
        }

        return null;
    }

    /**
     * La ficha del negocio: lo que un cliente MCP necesita saber antes de hablar. La confianza va
     * con el default (con_default, la lectura que MUESTRA) porque acá no se ejecuta nada: es lo
     * mismo que le contesta la configuración del asistente a la pantalla.
     *
     * @param  \App\Models\User  $dueno
     * @return array<string, mixed>
     */
    protected static function negocio(User $dueno): array
    {
        $hoy = Carbon::now();

        $extensiones = [];

        foreach ($dueno->extencions as $extencion) {

            $extensiones[] = (string) $extencion->slug;
        }

        return [
            'nombre'                  => self::nombre_del_negocio($dueno),
            'moneda_principal'        => self::MONEDA_PRINCIPAL,
            'usa_dolares'             => UserHelper::hasExtencion('ventas_en_dolares', $dueno),
            'extensiones'             => $extensiones,
            'confianza_del_asistente' => ConfianzaDelAgenteIaHelper::con_default($dueno),
            'proveedor_de_ia'         => ProveedorIaHelper::proveedor_elegido($dueno),
            'hoy'                     => $hoy->format('d/m/Y'),
            'dia_de_la_semana'        => FormatoIaHelper::dia_de_la_semana($hoy),
        ];
    }

    /**
     * El nombre con el que se presenta el negocio: company_name, y si no lo tiene, name.
     *
     * @param  \App\Models\User  $dueno
     * @return string
     */
    public static function nombre_del_negocio(User $dueno): string
    {
        $nombre = trim((string) $dueno->company_name);

        return $nombre !== '' ? $nombre : trim((string) $dueno->name);
    }

    /**
     * Una entrada de resources/list.
     *
     * @param  string  $ruta  Lo que va después de comerciocity://
     * @param  string  $titulo
     * @param  string  $descripcion
     * @return array<string, string>
     */
    protected static function recurso($ruta, $titulo, $descripcion): array
    {
        return [
            'uri'         => self::ESQUEMA . $ruta,
            'name'        => $ruta,
            'title'       => $titulo,
            'description' => $descripcion,
            'mimeType'    => self::MIME,
        ];
    }
}
