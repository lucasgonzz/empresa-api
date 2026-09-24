<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Las tres propuestas genéricas del asistente —alta, edición y baja de cualquier entidad del
 * catálogo de escritura— con la misma forma que las demás (misión asistente-omnisciente, 21/9/2026,
 * bloque B): validan contra CatalogoDeEscrituraIaHelper, arman la tarjeta con los datos EXACTOS
 * que van a ir al controller y guardan en `datos` lo que EjecutorGenericoIaHelper necesita para
 * llamar a la pantalla al confirmar.
 *
 * Lo que valida cada una, y por qué:
 *
 *   - Cada clave de `datos` / `cambios` tiene que existir en el catálogo de la entidad y estar
 *     habilitada para esa operación. Una clave desconocida CORTA con la lista de campos válidos
 *     (nunca se ignora: una carga con un campo descartado en silencio llega a la persona como una
 *     carga completa).
 *   - Los valores se coercionan por tipo (number, checkbox, date AAAA-MM-DD) y una relación se
 *     acepta por nombre (se resuelve contra su tabla, filtrando por dueño si tiene user_id) o por
 *     id si otra herramienta lo devolvió. 0 resultados o 2+ vuelven como `faltan`.
 *   - El alta exige los obligatorios (los NOT NULL sin default más `name`) y avisa —no rechaza— si
 *     ya hay una fila del dueño con ese nombre.
 *   - La edición y la baja ubican el registro por id (Y dueño) o por nombre (`LIKE` por dueño;
 *     con varias coincidencias, la exacta gana; si no, `faltan` con las opciones). La edición guarda
 *     `referencia_updated_at`: si alguien edita el registro entre la tarjeta y el clic, confirmar
 *     da 409 y la tarjeta queda vencida.
 *
 * 🔴 Ninguna de las tres se auto-confirma con el dueño en "cauteloso" ni en "resuelto": no están en
 * HerramientasDeCarga::AUTO_CONFIRMABLES.
 *
 * ⚠️ Con el dueño en "directo" (misión asistente-capacidades-y-hilos, 22/9/2026) el ALTA y la
 * EDICIÓN sí se ejecutan solas: entran en AUTO_CONFIRMABLES_DIRECTO y sus `case` pasan por
 * quizas_auto_confirmar(), que es quien mira el modo. 🔴 LA BAJA NO, EN NINGÚN MODO: 31 de las 40
 * entidades de este catálogo no usan SoftDeletes, así que un borrado no se deshace. Está en
 * NUNCA_AUTO_CONFIRMABLES y su `case` sigue sin pasar por la puerta.
 */
class PropuestaGenericaIaHelper
{
    /** Cuántas opciones se ofrecen cuando un nombre es ambiguo. */
    const MAX_OPCIONES = 10;

    /** Cuántos renglones de identificación lleva la tarjeta de una baja. */
    const MAX_RENGLONES_DE_BAJA = 5;

    // -------------------------------------------------------------------------------------------
    // Alta
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta proponer_alta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  mixed  $entidad
     * @param  array  $datos  [campo => valor], con las claves de que_puedo_cargar.
     * @param  mixed  $reemplaza_a
     * @param  array  $extras  La foto y la descripción de un artículo (AltaDeArticuloConFotoIaHelper::separar).
     * @return array
     */
    public static function proponer_alta(ContextoDeCargaIa $contexto, AiMessage $mensaje, $entidad, array $datos, $reemplaza_a = null, array $extras = [])
    {
        $declaracion = self::entidad_para($contexto, $entidad, Catalogo::OP_ALTA);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            return $declaracion;
        }

        /*
         * Misión asistente-fotos-barras-y-compras (24/9/2026): la foto y la descripción viajan en la
         * MISMA tarjeta del alta, pero sólo un artículo las tiene. En otra entidad se corta en vez de
         * ignorarlas: una tarjeta que se calla una parte del pedido llega a la persona como completa.
         */
        if (count($extras) && $declaracion['entidad'] !== AltaDeArticuloConFotoIaHelper::ENTIDAD) {

            return RespuestaDeCargaIa::error('La foto y la descripción sólo se cargan en el alta de un artículo.');
        }

        /*
         * 🔴 Correcciones del 24/9/2026: una CORRECCIÓN del alta de un artículo ("sí, pero cambiale
         * el nombre") hereda la foto y la descripción de la tarjeta que reemplaza, si el modelo no
         * las vuelve a mandar. En la prueba real el modelo inventó un imagen_id (310) y reescribió la
         * descripción inventando una frase, porque la línea de historial no trae el id y recorta los
         * renglones. Ver AltaDeArticuloConFotoIaHelper::heredar().
         */
        if ($declaracion['entidad'] === AltaDeArticuloConFotoIaHelper::ENTIDAD) {

            $extras = AltaDeArticuloConFotoIaHelper::heredar($contexto, $reemplaza_a, $extras);
        }

        $validado = self::validar_campos($contexto, $declaracion, Catalogo::OP_ALTA, $datos);

        if (RespuestaDeCargaIa::es_negativa($validado)) {

            return $validado;
        }

        $faltan = [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            if ($campo['obligatorio'] && in_array(Catalogo::OP_ALTA, $campo['operaciones'], true) && self::vacio(isset($validado['payload'][$columna]) ? $validado['payload'][$columna] : null)) {

                $faltan[] = $campo['etiqueta'];
            }
        }

        if (count($faltan)) {

            return RespuestaDeCargaIa::faltan($faltan);
        }

        $payload = self::payload_de_alta($declaracion, $validado['payload']);

        $nombre = self::nombre_pedido($declaracion, $validado['payload']);

        $aviso = null;

        if (!is_null($nombre) && self::hay_otra_con_ese_nombre($contexto, $declaracion, $nombre)) {

            $aviso = 'Ya hay '.($declaracion['genero'] === 'f' ? 'una ' : 'un ').$declaracion['singular'].' con ese nombre.';
        }

        if (!is_null($declaracion['aviso_de_alta'])) {

            $aviso = is_null($aviso) ? $declaracion['aviso_de_alta'] : $aviso.' '.$declaracion['aviso_de_alta'];
        }

        $renglones = self::renglones($declaracion, $validado['pedidos'], $validado['nombres']);

        $renglones = self::costo_en_dolares_en_los_renglones($declaracion, $validado['payload'], $renglones);

        $imagen_url = null;

        $datos_de_la_tarjeta = [
            'entidad'   => $declaracion['entidad'],
            'operacion' => Catalogo::OP_ALTA,
            'payload'   => $payload,
            'pedidos'   => $validado['pedidos'],
        ];

        /*
         * Los extras van en `datos.extras` y NO en el payload: la pantalla de artículos no los recibe
         * en su store(). Los ejecuta AltaDeArticuloConFotoIaHelper::completar() después del alta.
         */
        if (count($extras)) {

            $resueltos = AltaDeArticuloConFotoIaHelper::resolver($contexto, $mensaje, $extras);

            if (RespuestaDeCargaIa::es_negativa($resueltos)) {

                return $resueltos;
            }

            $datos_de_la_tarjeta['extras'] = $resueltos['extras'];

            foreach ($resueltos['renglones'] as $renglon) {

                $renglones[] = $renglon;
            }

            /*
             * La miniatura de la foto en la tarjeta del panel (`presentacion.imagen_url`, la clave
             * opcional que AccionCard.vue ya pinta arriba de los renglones): el dueño ve QUÉ foto
             * queda publicada antes de confirmar, sea la que mandó o la encontrada en internet.
             */
            if (!is_null($resueltos['imagen_url'])) {

                $imagen_url = $resueltos['imagen_url'];
            }

            if (!empty($resueltos['extras']['imagen_id'])) {

                $aviso_de_la_foto = 'La foto también se publica en la tienda online del negocio.';

                $aviso = is_null($aviso) ? $aviso_de_la_foto : $aviso.' '.$aviso_de_la_foto;
            }
        }

        $presentacion = [
            'titulo'    => Catalogo::titulo($declaracion['entidad'], Catalogo::OP_ALTA),
            'renglones' => $renglones,
            'aviso'     => $aviso,
        ];

        if (!is_null($imagen_url)) {

            $presentacion['imagen_url'] = $imagen_url;

            /*
             * La referencia de la foto, para que AiMessageAction::toArray() rearme la URL en el
             * request de la SPA: esta propuesta corre adentro del job, donde url() sale de APP_URL
             * (ver FotosDelMensajeIaHelper::url). `imagen_url` queda como respaldo.
             */
            if (!empty($resueltos['extras']['imagen_id'])) {

                $imagen = AiMessageImagen::find((int) $resueltos['extras']['imagen_id']);

                if (!is_null($imagen)) {

                    $presentacion['imagen_mensaje'] = ['ai_message_id' => (int) $imagen->ai_message_id, 'orden' => (int) $imagen->orden];
                }
            }
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_ALTA,
            self::clave_de_alta($declaracion, $nombre, $validado['pedidos']),
            $datos_de_la_tarjeta,
            $presentacion,
            $reemplaza_a
        );

        $resumen = Catalogo::titulo($declaracion['entidad'], Catalogo::OP_ALTA).(is_null($nombre) ? '' : ' '.$nombre).self::resumen_de_renglones($renglones, $nombre);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
    }

    // -------------------------------------------------------------------------------------------
    // Edición
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta proponer_edicion.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  mixed  $entidad
     * @param  mixed  $registro  Id (si otra herramienta lo devolvió), nombre, o número para ventas y gastos.
     * @param  array  $cambios  [campo => valor nuevo]
     * @param  mixed  $reemplaza_a
     * @return array
     */
    public static function proponer_edicion(ContextoDeCargaIa $contexto, AiMessage $mensaje, $entidad, $registro, array $cambios, $reemplaza_a = null)
    {
        $declaracion = self::entidad_para($contexto, $entidad, Catalogo::OP_EDICION);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            return $declaracion;
        }

        $fila = self::ubicar($contexto, $declaracion, $registro);

        if (RespuestaDeCargaIa::es_negativa($fila)) {

            return $fila;
        }

        if (!count($cambios)) {

            return RespuestaDeCargaIa::error('No mandaste ningún cambio. Decime qué campo cambia y a qué valor.');
        }

        $validado = self::validar_campos($contexto, $declaracion, Catalogo::OP_EDICION, $cambios);

        if (RespuestaDeCargaIa::es_negativa($validado)) {

            return $validado;
        }

        $nombre_actual = Catalogo::nombre_de_fila($declaracion['entidad'], $fila);

        $renglones = [];
        $cambios_reales = [];
        $pedidos = [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            if (!array_key_exists($columna, $validado['payload'])) {

                continue;
            }

            $antes = isset($fila->{$columna}) ? $fila->{$columna} : null;
            $despues = $validado['payload'][$columna];

            if (self::iguales($campo, $antes, $despues)) {

                continue;
            }

            $cambios_reales[$columna] = $despues;
            $pedidos[$columna] = $validado['pedidos'][$columna];

            $renglones[] = [
                'etiqueta' => Str::ucfirst($campo['etiqueta']),
                'valor'    => Catalogo::valor_legible($campo, $antes).' → '.Catalogo::valor_legible($campo, $despues),
            ];
        }

        if (!count($cambios_reales)) {

            return RespuestaDeCargaIa::error(Str::ucfirst($declaracion['singular']).' "'.$nombre_actual.'" ya está así: no hay nada distinto para cambiar.');
        }

        // El updated_at del registro al armar la tarjeta: si alguien lo edita en el medio, confirmar da 409 (vencida).
        $referencia = isset($fila->updated_at) && !is_null($fila->updated_at) ? (string) $fila->updated_at : null;

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_EDICION,
            'edicion:'.$declaracion['entidad'].':'.(int) $fila->id,
            [
                'entidad'   => $declaracion['entidad'],
                'operacion' => Catalogo::OP_EDICION,
                'id'        => (int) $fila->id,
                'cambios'   => $cambios_reales,
                'pedidos'   => $pedidos,
            ],
            [
                'titulo'    => Catalogo::titulo($declaracion['entidad'], Catalogo::OP_EDICION, $nombre_actual),
                'renglones' => $renglones,
                'aviso'     => null,
            ],
            $reemplaza_a,
            $referencia
        );

        $resumen = Catalogo::titulo($declaracion['entidad'], Catalogo::OP_EDICION, $nombre_actual).self::resumen_de_renglones($renglones, null);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
    }

    // -------------------------------------------------------------------------------------------
    // Baja
    // -------------------------------------------------------------------------------------------

    /**
     * Herramienta proponer_baja.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  mixed  $entidad
     * @param  mixed  $registro
     * @param  mixed  $reemplaza_a
     * @return array
     */
    public static function proponer_baja(ContextoDeCargaIa $contexto, AiMessage $mensaje, $entidad, $registro, $reemplaza_a = null)
    {
        $declaracion = self::entidad_para($contexto, $entidad, Catalogo::OP_BAJA);

        if (RespuestaDeCargaIa::es_negativa($declaracion)) {

            return $declaracion;
        }

        $fila = self::ubicar($contexto, $declaracion, $registro);

        if (RespuestaDeCargaIa::es_negativa($fila)) {

            return $fila;
        }

        $nombre = Catalogo::nombre_de_fila($declaracion['entidad'], $fila);

        /*
         * El aviso de una baja lo arma el catálogo: el texto fijo de la entidad, más si la baja es
         * DEFINITIVA (el modelo no usa SoftDeletes), más cuántas filas del dueño van a quedar
         * apuntando a un registro que ya no existe. Ver Catalogo::aviso_de_baja(): es el arreglo
         * del 🔴 4 del chequeo adversarial, donde borrar una lista de precios dejaba 30 clientes
         * con un `price_type_id` inexistente mientras la tarjeta lo contaba como algo reversible.
         */
        $aviso = Catalogo::aviso_de_baja($declaracion, $fila, $contexto->owner_id);

        if ($aviso === '') {

            $aviso = 'Se borra del sistema. Lo que dependa de esto puede dejar de verse.';
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_BAJA,
            'baja:'.$declaracion['entidad'].':'.(int) $fila->id,
            [
                'entidad'   => $declaracion['entidad'],
                'operacion' => Catalogo::OP_BAJA,
                'id'        => (int) $fila->id,
                'nombre'    => $nombre,
            ],
            [
                'titulo'    => Catalogo::titulo($declaracion['entidad'], Catalogo::OP_BAJA, $nombre),
                'renglones' => self::renglones_de_baja($declaracion, $fila),
                'aviso'     => $aviso,
            ],
            $reemplaza_a
        );

        return AccionesIaHelper::respuesta_de_propuesta($creada, Catalogo::titulo($declaracion['entidad'], Catalogo::OP_BAJA, $nombre), [
            'aviso' => $aviso,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // Permisos y entidad
    // -------------------------------------------------------------------------------------------

    /**
     * La declaración de la entidad si existe, admite la operación, el comercio tiene su extensión
     * y la persona tiene permiso; si no, la respuesta negativa.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $entidad
     * @param  string  $operacion
     * @return array
     */
    public static function entidad_para(ContextoDeCargaIa $contexto, $entidad, string $operacion)
    {
        $entidad = is_scalar($entidad) ? trim((string) $entidad) : '';

        if ($entidad === '') {

            return RespuestaDeCargaIa::faltan(['qué entidad es (mirá que_puedo_cargar)']);
        }

        $declaracion = Catalogo::declaracion($entidad);

        if (is_null($declaracion)) {

            return RespuestaDeCargaIa::error(
                'La entidad "'.$entidad.'" no se puede cargar por acá.',
                ['entidades' => array_keys(Catalogo::entidades())]
            );
        }

        if (!isset($declaracion['operaciones'][$operacion])) {

            return RespuestaDeCargaIa::error(
                Str::ucfirst($declaracion['etiqueta']).': '.self::verbo($operacion).' no se puede por acá'.self::porque_no($declaracion['entidad'], $operacion).'.'
            );
        }

        if (!is_null($declaracion['extension']) && !PermisosIaHelper::tiene_extencion($contexto->owner, $declaracion['extension'])) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_extencion($declaracion['etiqueta']));
        }

        if (!self::puede($contexto->persona, $declaracion['entidad'], $operacion)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso($declaracion['etiqueta']));
        }

        return $declaracion;
    }

    /**
     * true si la persona puede hacer la operación sobre la entidad: dueño o admin_access siempre;
     * si no, el permiso de la pantalla (`entidad.store` para crear —el mismo formulario edita, así
     * que también vale para editar—, `entidad.update` si existiera, `entidad.delete` para borrar).
     *
     * @param  \App\Models\User|null  $persona
     * @param  string  $entidad
     * @param  string  $operacion
     * @return bool
     */
    public static function puede($persona, string $entidad, string $operacion): bool
    {
        if (PermisosIaHelper::es_admin($persona)) {

            return true;
        }

        switch ($operacion) {

            case Catalogo::OP_ALTA:
                return PermisosIaHelper::puede($persona, $entidad.'.store');

            case Catalogo::OP_EDICION:
                return PermisosIaHelper::puede($persona, $entidad.'.update') || PermisosIaHelper::puede($persona, $entidad.'.store');

            case Catalogo::OP_BAJA:
                return PermisosIaHelper::puede($persona, $entidad.'.delete');
        }

        return false;
    }

    // -------------------------------------------------------------------------------------------
    // Ubicar un registro
    // -------------------------------------------------------------------------------------------

    /**
     * El registro del dueño que nombra `$registro`, o la respuesta negativa.
     *
     *   - Un número es el id si la entidad tiene nombre (los ids los devuelven otras herramientas);
     *     si la entidad se identifica por número (ventas, gastos), es ese número.
     *   - Un texto se busca con LIKE en la columna de nombre, por dueño. Con varias coincidencias
     *     gana la exacta; si no hay exacta, `faltan` con las opciones.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  mixed  $registro
     * @return object|array
     */
    public static function ubicar(ContextoDeCargaIa $contexto, array $declaracion, $registro)
    {
        $etiqueta = $declaracion['singular'];

        if (is_null($registro) || (is_string($registro) && trim($registro) === '')) {

            return RespuestaDeCargaIa::faltan(['qué '.$etiqueta.' es']);
        }

        if (is_array($registro)) {

            return RespuestaDeCargaIa::error('El registro tiene que venir como texto o como número.');
        }

        $registro = is_string($registro) ? trim($registro) : $registro;

        if (is_numeric($registro) && (string) (int) $registro === (string) $registro) {

            $numero = (int) $registro;

            if (is_null($declaracion['columna_nombre']) && !is_null($declaracion['columna_numero'])) {

                $fila = self::consulta_del_dueno($contexto, $declaracion)->where($declaracion['columna_numero'], $numero)->first();

                if (is_null($fila)) {

                    return RespuestaDeCargaIa::error('No encontré '.($declaracion['genero'] === 'f' ? 'la ' : 'el ').$etiqueta.' N° '.$numero.' entre '.($declaracion['genero'] === 'f' ? 'las tuyas' : 'los tuyos').'.');
                }

                return self::verificar_fila($declaracion, $fila);
            }

            $fila = self::fila_del_dueno($contexto->owner_id, $declaracion, $numero);

            if (is_null($fila)) {

                return RespuestaDeCargaIa::error('No encontré '.($declaracion['genero'] === 'f' ? 'esa ' : 'ese ').$etiqueta.' entre '.($declaracion['genero'] === 'f' ? 'las tuyas' : 'los tuyos').'.');
            }

            return self::verificar_fila($declaracion, $fila);
        }

        if (is_null($declaracion['columna_nombre'])) {

            return RespuestaDeCargaIa::error(
                Str::ucfirst($declaracion['etiqueta']).' se ubican por su número, no por un texto: decime el número '.($declaracion['genero'] === 'f' ? 'de la ' : 'del ').$etiqueta.'.'
            );
        }

        $texto = (string) $registro;

        $columna = $declaracion['columna_nombre'];

        $candidatas = self::consulta_del_dueno($contexto, $declaracion)
                            ->where($columna, 'LIKE', '%'.self::escapar_like($texto).'%')
                            ->orderBy($columna)
                            ->limit(self::MAX_OPCIONES + 1)
                            ->get();

        if (!count($candidatas)) {

            return RespuestaDeCargaIa::error('No encontré '.($declaracion['genero'] === 'f' ? 'ninguna ' : 'ningún ').$etiqueta.' que se llame como "'.$texto.'".');
        }

        if (count($candidatas) === 1) {

            return self::verificar_fila($declaracion, $candidatas[0]);
        }

        $exactas = [];

        foreach ($candidatas as $candidata) {

            if (mb_strtolower(trim((string) $candidata->{$columna})) === mb_strtolower($texto)) {

                $exactas[] = $candidata;
            }
        }

        if (count($exactas) === 1) {

            return self::verificar_fila($declaracion, $exactas[0]);
        }

        $opciones = [];

        foreach ($candidatas as $i => $candidata) {

            if ($i >= self::MAX_OPCIONES) {

                break;
            }

            $opciones[] = ['id' => (int) $candidata->id, 'nombre' => (string) $candidata->{$columna}];
        }

        return RespuestaDeCargaIa::faltan(
            ['cuál de '.($declaracion['genero'] === 'f' ? 'estas ' : 'estos ').$declaracion['etiqueta'].' es (hay '.count($candidatas).' que encajan con "'.$texto.'")'],
            [$declaracion['entidad'] => $opciones]
        );
    }

    /**
     * La fila de la entidad con ese id SI es del dueño (y no está borrada), o null. Es la guarda
     * de tenencia que los controllers de recurso no hacen (`Model::find($id)` pelado).
     *
     * @param  int  $owner_id
     * @param  array  $declaracion
     * @param  int  $id
     * @return object|null
     */
    public static function fila_del_dueno(int $owner_id, array $declaracion, int $id)
    {
        if ($id <= 0) {

            return null;
        }

        $consulta = DB::table($declaracion['tabla'])
                        ->where('id', $id)
                        ->where('user_id', $owner_id);

        if ($declaracion['tiene_deleted_at']) {

            $consulta->whereNull('deleted_at');
        }

        return $consulta->first();
    }

    /**
     * Guardas propias de algunas entidades sobre la fila ubicada: hoy, una venta contenedora de
     * consolidación de facturación no se anula por acá.
     *
     * @param  array  $declaracion
     * @param  object  $fila
     * @return object|array
     */
    protected static function verificar_fila(array $declaracion, $fila)
    {
        if ($declaracion['entidad'] === 'sale' && !empty($fila->is_consolidacion_facturacion)) {

            return RespuestaDeCargaIa::error('La venta N° '.$fila->num.' es una consolidación de facturación: se maneja desde Comprobantes, no se anula por acá.');
        }

        return $fila;
    }

    /**
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function consulta_del_dueno(ContextoDeCargaIa $contexto, array $declaracion)
    {
        $consulta = DB::table($declaracion['tabla'])->where('user_id', $contexto->owner_id);

        if ($declaracion['tiene_deleted_at']) {

            $consulta->whereNull('deleted_at');
        }

        return $consulta;
    }

    // -------------------------------------------------------------------------------------------
    // Validación de campos
    // -------------------------------------------------------------------------------------------

    /**
     * Valida y coerciona los campos que mandó el modelo para una operación.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  string  $operacion
     * @param  array  $valores  [clave => valor], clave = columna, etiqueta o nombre de la relación.
     * @return array  ['payload' => [columna => valor para el controller], 'pedidos' => [columna => valor normalizado],
     *                 'nombres' => [columna => nombre de la relación]] o la respuesta negativa.
     */
    public static function validar_campos(ContextoDeCargaIa $contexto, array $declaracion, string $operacion, array $valores)
    {
        $payload = [];
        $pedidos = [];
        $nombres = [];

        $alias = self::alias_de_campos($declaracion);

        foreach ($valores as $clave => $valor) {

            $clave_normalizada = self::normalizar_clave((string) $clave);

            if ($clave_normalizada === 'reemplaza_a') {

                continue;
            }

            if (!isset($alias[$clave_normalizada])) {

                return RespuestaDeCargaIa::error(
                    'El campo "'.$clave.'" no existe para '.$declaracion['etiqueta'].'. Los campos válidos son: '.implode(', ', array_keys($declaracion['campos'])).'.',
                    ['campos' => self::campos_para_ofrecer($declaracion)]
                );
            }

            $columna = $alias[$clave_normalizada];

            $campo = $declaracion['campos'][$columna];

            if (!in_array($operacion, $campo['operaciones'], true)) {

                $verbo = $operacion === Catalogo::OP_ALTA ? 'al crear' : 'al editar';
                $otro = $operacion === Catalogo::OP_ALTA ? 'editándol'.($declaracion['genero'] === 'f' ? 'a' : 'o').' después' : 'solo al crear';

                return RespuestaDeCargaIa::error(
                    'El campo "'.$campo['etiqueta'].'" no se carga '.$verbo.' '.($declaracion['genero'] === 'f' ? 'una ' : 'un ').$declaracion['singular'].' desde la pantalla ('.$otro.').'
                );
            }

            $resultado = self::coercionar($contexto, $declaracion, $campo, $valor);

            if (RespuestaDeCargaIa::es_negativa($resultado)) {

                return $resultado;
            }

            $payload[$columna] = $resultado['valor'];
            $pedidos[$columna] = $resultado['valor'];

            if (isset($resultado['nombre'])) {

                $nombres[$columna] = $resultado['nombre'];
            }
        }

        return ['payload' => $payload, 'pedidos' => $pedidos, 'nombres' => $nombres];
    }

    /**
     * Un valor coercionado al tipo del campo: ['valor' => ..., 'nombre' => ...] o la respuesta negativa.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  array  $campo
     * @param  mixed  $valor
     * @return array
     */
    protected static function coercionar(ContextoDeCargaIa $contexto, array $declaracion, array $campo, $valor)
    {
        $etiqueta = $campo['etiqueta'];

        if (is_array($valor) || is_object($valor)) {

            return RespuestaDeCargaIa::error('El campo "'.$etiqueta.'" tiene que ser un valor simple, no una lista.');
        }

        if (self::vacio($valor)) {

            if ($campo['obligatorio']) {

                return RespuestaDeCargaIa::error('El campo "'.$etiqueta.'" no puede quedar vacío.');
            }

            return ['valor' => null];
        }

        if (!is_null($campo['relacion'])) {

            return self::resolver_relacion($contexto, $declaracion, $campo, $valor);
        }

        switch ($campo['tipo']) {

            case 'number':
                $numero = self::a_numero($valor);

                if (is_null($numero)) {

                    return RespuestaDeCargaIa::error('El campo "'.$etiqueta.'" tiene que ser un número.');
                }

                return ['valor' => $numero];

            case 'checkbox':
                $booleano = self::a_booleano($valor);

                if (is_null($booleano)) {

                    return RespuestaDeCargaIa::error('El campo "'.$etiqueta.'" es sí o no.');
                }

                return ['valor' => $booleano ? 1 : 0];

            case 'date':
                $fecha = self::a_fecha($valor);

                if (is_null($fecha)) {

                    return RespuestaDeCargaIa::error('El campo "'.$etiqueta.'" tiene que venir como AAAA-MM-DD.');
                }

                return ['valor' => $fecha];
        }

        return ['valor' => trim((string) $valor)];
    }

    /**
     * Resuelve una relación por id (si vino un número y existe para el dueño) o por nombre.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  array  $campo
     * @param  mixed  $valor
     * @return array
     */
    protected static function resolver_relacion(ContextoDeCargaIa $contexto, array $declaracion, array $campo, $valor)
    {
        $relacion = $campo['relacion'];
        $etiqueta = $campo['etiqueta'];

        if ($relacion['tabla'] === 'monedas') {

            $moneda_id = EntradaDeCargaIa::moneda($contexto, $valor);

            if (is_array($moneda_id)) {

                return $moneda_id;
            }

            return ['valor' => $moneda_id, 'nombre' => Catalogo::MONEDAS[$moneda_id]];
        }

        $consulta_base = function () use ($contexto, $relacion) {

            $consulta = DB::table($relacion['tabla']);

            if ($relacion['tabla'] === 'users') {

                // Los empleados del dueño, y el dueño mismo.
                $consulta->where(function ($q) use ($contexto) {
                    $q->where('owner_id', $contexto->owner_id)->orWhere('id', $contexto->owner_id);
                });

            } elseif (self::tabla_tiene($relacion['tabla'], 'user_id')) {

                $consulta->where('user_id', $contexto->owner_id);
            }

            if (self::tabla_tiene($relacion['tabla'], 'deleted_at')) {

                $consulta->whereNull('deleted_at');
            }

            return $consulta;
        };

        $texto = is_string($valor) ? trim($valor) : $valor;

        // Un número es un id que devolvió otra herramienta (o el número que la persona dijo, para
        // relaciones que se identifican así, como la alícuota de IVA).
        if (is_numeric($texto)) {

            $fila = $consulta_base()->where('id', (int) $texto)->first();

            if (!is_null($fila)) {

                return ['valor' => (int) $fila->id, 'nombre' => (string) $fila->{$relacion['campo']}];
            }
        }

        $texto = (string) $texto;

        $candidatas = $consulta_base()
                        ->where($relacion['campo'], 'LIKE', '%'.self::escapar_like($texto).'%')
                        ->orderBy($relacion['campo'])
                        ->limit(self::MAX_OPCIONES + 1)
                        ->get();

        if (!count($candidatas)) {

            return RespuestaDeCargaIa::faltan(
                ['qué '.$etiqueta.' es: no encontré ninguna que se llame como "'.$texto.'"'],
                [$campo['columna'] => self::opciones_de_relacion($consulta_base()->orderBy($relacion['campo'])->limit(self::MAX_OPCIONES)->get(), $relacion['campo'])]
            );
        }

        if (count($candidatas) === 1) {

            return ['valor' => (int) $candidatas[0]->id, 'nombre' => (string) $candidatas[0]->{$relacion['campo']}];
        }

        $exactas = [];

        foreach ($candidatas as $candidata) {

            if (mb_strtolower(trim((string) $candidata->{$relacion['campo']})) === mb_strtolower($texto)) {

                $exactas[] = $candidata;
            }
        }

        if (count($exactas) === 1) {

            return ['valor' => (int) $exactas[0]->id, 'nombre' => (string) $exactas[0]->{$relacion['campo']}];
        }

        return RespuestaDeCargaIa::faltan(
            ['cuál '.$etiqueta.' es (hay '.count($candidatas).' que encajan con "'.$texto.'")'],
            [$campo['columna'] => self::opciones_de_relacion($candidatas, $relacion['campo'])]
        );
    }

    /**
     * @param  iterable  $filas
     * @param  string  $campo_nombre
     * @return array<int, array{id: int, nombre: string}>
     */
    protected static function opciones_de_relacion($filas, string $campo_nombre): array
    {
        $opciones = [];

        foreach ($filas as $i => $fila) {

            if ($i >= self::MAX_OPCIONES) {

                break;
            }

            $opciones[] = ['id' => (int) $fila->id, 'nombre' => (string) $fila->{$campo_nombre}];
        }

        return $opciones;
    }

    // -------------------------------------------------------------------------------------------
    // Payload, renglones y claves
    // -------------------------------------------------------------------------------------------

    /**
     * El payload del alta: lo pedido, más los sí/no que la pantalla manda siempre (con el valor
     * con el que nace el formulario si está curado, si no el default de la columna, si no 0), más
     * las claves de pantalla (`price_types: []`...).
     *
     * @param  array  $declaracion
     * @param  array  $pedidos
     * @return array
     */
    public static function payload_de_alta(array $declaracion, array $pedidos): array
    {
        $payload = $pedidos;

        $defaults = isset($declaracion['defaults_de_pantalla']) ? $declaracion['defaults_de_pantalla'] : [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            if (array_key_exists($columna, $payload) || !in_array(Catalogo::OP_ALTA, $campo['operaciones'], true)) {

                continue;
            }

            if (array_key_exists($columna, $defaults)) {

                $payload[$columna] = $defaults[$columna];

            } elseif ($campo['tipo'] === 'checkbox') {

                $payload[$columna] = is_null($campo['default']) ? 0 : (int) $campo['default'];
            }
        }

        foreach ($declaracion['claves_de_pantalla'] as $clave => $valor) {

            if (!array_key_exists($clave, $payload)) {

                $payload[$clave] = $valor;
            }
        }

        return $payload;
    }

    /**
     * Un renglón por campo pedido, en el orden del catálogo.
     *
     * @param  array  $declaracion
     * @param  array  $pedidos
     * @param  array  $nombres
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    protected static function renglones(array $declaracion, array $pedidos, array $nombres): array
    {
        $renglones = [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            if (!array_key_exists($columna, $pedidos)) {

                continue;
            }

            $valor = isset($nombres[$columna]) ? $nombres[$columna] : Catalogo::valor_legible($campo, $pedidos[$columna]);

            $renglones[] = ['etiqueta' => Str::ucfirst($campo['etiqueta']), 'valor' => $valor];
        }

        return $renglones;
    }

    /**
     * Con `cost_in_dollars` prendido, el renglón del costo se escribe en dólares ("US$ 10") y no en
     * pesos ("$ 10").
     *
     * Misión asistente-fotos-barras-y-compras (24/9/2026): valor_legible() formatea `cost` como
     * plata en pesos porque no ve los otros campos del pedido. Con la marca de dólares, la tarjeta
     * decía "Costo: $ 10" para un costo de diez DÓLARES, y el dueño confirmaba leyendo un precio que
     * no era el que se iba a guardar.
     *
     * @param  array  $declaracion
     * @param  array  $payload  El payload validado de la propuesta.
     * @param  array  $renglones
     * @return array
     */
    protected static function costo_en_dolares_en_los_renglones(array $declaracion, array $payload, array $renglones): array
    {
        if ($declaracion['entidad'] !== 'article'
            || !isset($payload['cost_in_dollars'], $payload['cost'], $declaracion['campos']['cost'])
            || !filter_var($payload['cost_in_dollars'], FILTER_VALIDATE_BOOLEAN)) {

            return $renglones;
        }

        $etiqueta_del_costo = Str::ucfirst($declaracion['campos']['cost']['etiqueta']);

        foreach ($renglones as $indice => $renglon) {

            if ($renglon['etiqueta'] === $etiqueta_del_costo) {

                $renglones[$indice]['valor'] = 'US$ ' . number_format((float) $payload['cost'], 2, ',', '.');
            }
        }

        return $renglones;
    }

    /**
     * Los renglones que identifican lo que se va a borrar: el nombre y hasta cuatro datos más de la
     * fila (fecha, total, cliente...) para que la persona reconozca el registro.
     *
     * @param  array  $declaracion
     * @param  object  $fila
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    protected static function renglones_de_baja(array $declaracion, $fila): array
    {
        $renglones = [];

        $renglones[] = ['etiqueta' => Str::ucfirst($declaracion['singular']), 'valor' => Catalogo::nombre_de_fila($declaracion['entidad'], $fila)];

        // Una venta o un gasto no declaran campos (no se editan por acá): se describen a mano.
        $fijos = [
            'sale'    => ['created_at' => 'Fecha', 'total' => 'Total', 'client_id' => 'Cliente'],
            'expense' => ['created_at' => 'Fecha', 'amount' => 'Importe', 'expense_concept_id' => 'Subcategoría'],
            'pending' => ['fecha_realizacion' => 'Fecha', 'notas' => 'Notas'],
        ];

        if (isset($fijos[$declaracion['entidad']])) {

            foreach ($fijos[$declaracion['entidad']] as $columna => $etiqueta) {

                if (!isset($fila->{$columna}) || self::vacio($fila->{$columna})) {

                    continue;
                }

                $campo = [
                    'columna'  => $columna,
                    'tipo'     => in_array($columna, ['created_at', 'fecha_realizacion'], true) ? 'date' : (in_array($columna, ['total', 'amount'], true) ? 'number' : 'text'),
                    'etiqueta' => $etiqueta,
                    'relacion' => null,
                ];

                if ($columna === 'client_id') {

                    $campo['relacion'] = ['tabla' => 'clients', 'campo' => 'name'];
                }

                if ($columna === 'expense_concept_id') {

                    $campo['relacion'] = ['tabla' => 'expense_concepts', 'campo' => 'name'];
                }

                $renglones[] = ['etiqueta' => $etiqueta, 'valor' => Catalogo::valor_legible($campo, $fila->{$columna})];
            }

            return $renglones;
        }

        foreach ($declaracion['campos'] as $columna => $campo) {

            if (count($renglones) >= self::MAX_RENGLONES_DE_BAJA) {

                break;
            }

            if ($columna === $declaracion['columna_nombre'] || !isset($fila->{$columna}) || self::vacio($fila->{$columna})) {

                continue;
            }

            $renglones[] = ['etiqueta' => Str::ucfirst($campo['etiqueta']), 'valor' => Catalogo::valor_legible($campo, $fila->{$columna})];
        }

        return $renglones;
    }

    /**
     * "· Teléfono: 351... · CUIT: ..." para el resumen que lee la IA (sin repetir el nombre).
     *
     * @param  array  $renglones
     * @param  string|null  $nombre
     * @return string
     */
    protected static function resumen_de_renglones(array $renglones, $nombre): string
    {
        $partes = [];

        foreach ($renglones as $renglon) {

            if (!is_null($nombre) && $renglon['valor'] === $nombre) {

                continue;
            }

            $partes[] = $renglon['etiqueta'].': '.$renglon['valor'];
        }

        return count($partes) ? ' · '.implode(' · ', $partes) : '';
    }

    /**
     * Identidad de un alta para el reemplazo: la misma entidad con el mismo nombre es la misma
     * carga corregida; sin nombre, la huella de lo pedido.
     *
     * @param  array  $declaracion
     * @param  string|null  $nombre
     * @param  array  $pedidos
     * @return string
     */
    protected static function clave_de_alta(array $declaracion, $nombre, array $pedidos): string
    {
        $identidad = is_null($nombre)
            ? substr(md5(json_encode($pedidos)), 0, 12)
            : mb_strtolower(trim($nombre));

        return mb_substr('alta:'.$declaracion['entidad'].':'.$identidad, 0, 100);
    }

    /**
     * El nombre que se pidió para la fila nueva (la columna de nombre de la entidad), o null.
     *
     * @param  array  $declaracion
     * @param  array  $payload
     * @return string|null
     */
    protected static function nombre_pedido(array $declaracion, array $payload)
    {
        $columna = $declaracion['columna_nombre'];

        if (is_null($columna) || !isset($payload[$columna]) || self::vacio($payload[$columna])) {

            return null;
        }

        return trim((string) $payload[$columna]);
    }

    /**
     * true si el dueño ya tiene una fila con ese nombre (sin distinguir mayúsculas).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  string  $nombre
     * @return bool
     */
    protected static function hay_otra_con_ese_nombre(ContextoDeCargaIa $contexto, array $declaracion, string $nombre): bool
    {
        return self::consulta_del_dueno($contexto, $declaracion)
                    ->whereRaw('LOWER('.$declaracion['columna_nombre'].') = ?', [mb_strtolower($nombre)])
                    ->exists();
    }

    /**
     * Las claves con las que se acepta cada campo: la columna, su etiqueta (con y sin acentos) y,
     * para una relación, el nombre sin `_id`.
     *
     * @param  array  $declaracion
     * @return array<string, string>  alias normalizado => columna
     */
    protected static function alias_de_campos(array $declaracion): array
    {
        $alias = [];

        // Primero los alias derivados, después las columnas: una columna real siempre gana.
        foreach ($declaracion['campos'] as $columna => $campo) {

            $alias[self::normalizar_clave($campo['etiqueta'])] = $columna;

            if (Str::endsWith($columna, '_id')) {

                $alias[self::normalizar_clave(substr($columna, 0, -3))] = $columna;
            }
        }

        // "nombre" siempre es la columna de nombre de la entidad (street en una sucursal).
        if (!is_null($declaracion['columna_nombre']) && isset($declaracion['campos'][$declaracion['columna_nombre']])) {

            $alias['nombre'] = $declaracion['columna_nombre'];
            $alias['name'] = $declaracion['columna_nombre'];
        }

        foreach ($declaracion['campos'] as $columna => $campo) {

            $alias[self::normalizar_clave($columna)] = $columna;
        }

        return $alias;
    }

    /**
     * Minúsculas, sin acentos, guiones y espacios como guión bajo.
     *
     * @param  string  $clave
     * @return string
     */
    protected static function normalizar_clave(string $clave): string
    {
        $clave = mb_strtolower(trim($clave));

        $clave = strtr($clave, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return preg_replace('/[\s\-]+/', '_', $clave);
    }

    /**
     * Los campos de la entidad para ofrecerlos en una respuesta negativa.
     *
     * @param  array  $declaracion
     * @return array<int, array{campo: string, etiqueta: string, tipo: string}>
     */
    protected static function campos_para_ofrecer(array $declaracion): array
    {
        $campos = [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            $campos[] = ['campo' => $columna, 'etiqueta' => $campo['etiqueta'], 'tipo' => $campo['tipo']];
        }

        return $campos;
    }

    // -------------------------------------------------------------------------------------------
    // Coerción y comparación
    // -------------------------------------------------------------------------------------------

    /**
     * true si el valor guardado y el pedido son lo mismo para el tipo del campo.
     *
     * @param  array  $campo
     * @param  mixed  $antes
     * @param  mixed  $despues
     * @return bool
     */
    public static function iguales(array $campo, $antes, $despues): bool
    {
        if (self::vacio($antes) && self::vacio($despues)) {

            return true;
        }

        if (self::vacio($antes) || self::vacio($despues)) {

            return false;
        }

        if (!is_null($campo['relacion'])) {

            return (int) $antes === (int) $despues;
        }

        switch ($campo['tipo']) {

            case 'number':
                return abs((float) $antes - (float) $despues) < 0.0001;

            case 'checkbox':
                return filter_var($antes, FILTER_VALIDATE_BOOLEAN) === filter_var($despues, FILTER_VALIDATE_BOOLEAN);

            case 'date':
                return substr((string) $antes, 0, 10) === substr((string) $despues, 0, 10);
        }

        return trim((string) $antes) === trim((string) $despues);
    }

    /**
     * true si el valor no trae nada.
     *
     * @param  mixed  $valor
     * @return bool
     */
    public static function vacio($valor): bool
    {
        return is_null($valor) || (is_string($valor) && trim($valor) === '');
    }

    /**
     * Un número a partir de lo que mandó el modelo ("1500", 1500, "1.500,50", "1500.5"), o null.
     *
     * @param  mixed  $valor
     * @return float|int|null
     */
    protected static function a_numero($valor)
    {
        if (is_int($valor) || is_float($valor)) {

            return $valor;
        }

        if (is_bool($valor) || !is_string($valor)) {

            return null;
        }

        $texto = trim($valor);

        // "1.500,50" → "1500.50"; "1500,5" → "1500.5".
        if (strpos($texto, ',') !== false) {

            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        }

        if (!is_numeric($texto)) {

            return null;
        }

        return strpos($texto, '.') === false ? (int) $texto : (float) $texto;
    }

    /**
     * Un sí/no a partir de lo que mandó el modelo, o null si no se entiende.
     *
     * @param  mixed  $valor
     * @return bool|null
     */
    protected static function a_booleano($valor)
    {
        if (is_bool($valor)) {

            return $valor;
        }

        if (is_int($valor) || is_float($valor)) {

            return (float) $valor !== 0.0;
        }

        $texto = mb_strtolower(trim((string) $valor));

        if (in_array($texto, ['1', 'si', 'sí', 'true', 'verdadero', 'yes'], true)) {

            return true;
        }

        if (in_array($texto, ['0', 'no', 'false', 'falso'], true)) {

            return false;
        }

        return null;
    }

    /**
     * Una fecha AAAA-MM-DD (o AAAA-MM-DD HH:MM:SS) validada, o null.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    protected static function a_fecha($valor)
    {
        $texto = trim((string) $valor);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})( \d{2}:\d{2}(:\d{2})?)?$/', $texto, $m)) {

            return null;
        }

        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {

            return null;
        }

        return $texto;
    }

    /**
     * true si la tabla tiene esa columna (cacheado por proceso: se pregunta por cada relación que
     * se resuelve).
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @return bool
     */
    protected static function tabla_tiene(string $tabla, string $columna): bool
    {
        static $cache = [];

        $clave = $tabla.'.'.$columna;

        if (!isset($cache[$clave])) {

            $cache[$clave] = Schema::hasColumn($tabla, $columna);
        }

        return $cache[$clave];
    }

    /**
     * Escapa los comodines de LIKE en un texto de búsqueda.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function escapar_like(string $texto): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
    }

    /**
     * @param  string  $operacion
     * @return string
     */
    protected static function verbo(string $operacion): string
    {
        switch ($operacion) {

            case Catalogo::OP_ALTA:
                return 'crear';

            case Catalogo::OP_EDICION:
                return 'editar';
        }

        return 'borrar';
    }

    /**
     * El motivo por el que una operación no está para una entidad que sí está en el catálogo.
     *
     * @param  string  $entidad
     * @param  string  $operacion
     * @return string
     */
    protected static function porque_no(string $entidad, string $operacion): string
    {
        if ($entidad === 'sale' && $operacion === Catalogo::OP_ALTA) {

            return ' (para vender está proponer_venta)';
        }

        if ($entidad === 'sale') {

            return ' (una venta no se edita desde el chat)';
        }

        if ($entidad === 'expense') {

            return ' (para cargar un gasto está proponer_gasto)';
        }

        if ($entidad === 'pending') {

            return ' (las tareas se crean y cambian con proponer_tarea y proponer_cambios_en_tarea)';
        }

        return '';
    }
}
