<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;

/**
 * Búsqueda de imágenes para artículos según un filtro en lenguaje natural, propuesta por el
 * asistente de IA (misión asistente-masivas-imagenes-y-remito, 19/9/2026, plan §4.3, contrato §1.4):
 * "buscale imágenes a todos los de tal proveedor", "a los primeros 100 del inventario".
 *
 * Se encola por ImagenesAutomaticasHelper::encolar(), el MISMO camino que el botón del listado
 * (GoogleController::batch_assign_images): mismo job (ProcessArticleBatchImagesJob, con la
 * verificación por visión de cada candidata), misma cuota diaria de Google y mismo registro
 * visible `imagenes_automaticas`, que nace `pendiente` al encolar.
 *
 * Es una carga inocua y reversible —mandar a buscar no toca plata ni borra nada, y una imagen se
 * saca desde la ficha del artículo—, así que está en HerramientasDeCarga::AUTO_CONFIRMABLES: con el
 * dueño en "resuelto" se manda en el acto; en "cauteloso" queda la tarjeta.
 *
 * 🔴 LOS IDS NO VIAJAN EN LA TARJETA: SE RESUELVEN DE NUEVO AL EJECUTAR. La tarjeta guarda el
 * filtro (filter_form, imagen, orden, limite) y ejecutar() vuelve a preguntarle a la base qué
 * artículos lo cumplen hoy: entre la propuesta y el clic pueden haberse cargado imágenes a mano, o
 * borrado artículos, y una lista congelada mandaría a buscar para artículos que ya no lo necesitan.
 */
class PropuestaImagenesArticulosIaHelper
{
    /** Mismo criterio que la masiva: mandar a buscar imágenes es actualizar artículos. */
    const PERMISO = 'article.update';

    /** Tope de artículos por tanda, el mismo que la masiva. */
    const TOPE = 3000;

    const MENSAJE_SIN_CUOTA = 'No quedan búsquedas de imágenes por hoy: la cuota diaria de Google ya se usó. Pedímelo de nuevo mañana.';

    /** Cuántas búsquedas de Google puede consumir un artículo (código de barras + nombre). */
    const BUSQUEDAS_POR_ARTICULO = 2;

    const TITULO = 'Búsqueda de imágenes para artículos';

    const AVISO = 'Cada artículo puede usar hasta 2 búsquedas de la cuota diaria de Google; lo que no entre hoy queda sin procesar y se puede volver a pedir mañana.';

    /**
     * Herramienta proponer_imagenes_para_articulos.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  filtros, solo_sin_imagen (default true), orden, limite, reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return RespuestaDeCargaIa::error(self::mensaje_sin_permiso());
        }

        if (is_null($contexto->owner)) {

            return RespuestaDeCargaIa::error('No pude identificar la cuenta del negocio.');
        }

        $parametros = FiltroDeArticulosIaHelper::parametros_de_seleccion($input, true);

        if (RespuestaDeCargaIa::es_negativa($parametros)) {

            return $parametros;
        }

        $traducido = FiltroDeArticulosIaHelper::traducir($parametros['filtros'], $contexto->owner_id);

        if (RespuestaDeCargaIa::es_negativa($traducido)) {

            return $traducido;
        }

        $imagen = FiltroDeArticulosIaHelper::imagen_efectiva($traducido['imagen'], $parametros['solo_sin_imagen']);

        $total = FiltroDeArticulosIaHelper::contar($contexto->owner_id, $traducido['filter_form'], $imagen);

        if ($total === 0) {

            return RespuestaDeCargaIa::error(
                $imagen === FiltroDeArticulosIaHelper::IMAGEN_EN_BLANCO
                    ? 'Ningún artículo sin imagen cumple ese filtro: los que lo cumplen ya tienen imagen.'
                    : 'Ningún artículo cumple ese filtro. Revisá el filtro con la persona.'
            );
        }

        $a_procesar = $parametros['limite'] > 0 ? min($total, $parametros['limite']) : $total;

        if ($a_procesar > self::TOPE) {

            return RespuestaDeCargaIa::error(
                'Son ' . $a_procesar . ' artículos y el tope de una tanda es ' . self::TOPE . ': acotá el filtro o pedime un límite.'
            );
        }

        $cuota = ImagenesAutomaticasHelper::cuota_de($contexto->owner);

        if ((int) $cuota['disponibles'] <= 0) {

            return RespuestaDeCargaIa::error(self::MENSAJE_SIN_CUOTA);
        }

        $renglones = [
            ['etiqueta' => 'Artículos', 'valor' => self::detalle_de_articulos($a_procesar, $parametros['orden'], $parametros['limite'], $imagen)],
        ];

        foreach ($traducido['renglones'] as $renglon) {

            $renglones[] = $renglon;
        }

        $renglones[] = ['etiqueta' => 'Búsquedas disponibles hoy', 'valor' => $cuota['disponibles'] . ' de ' . $cuota['cuota']];

        $datos = [
            'filtros'         => $parametros['filtros'],
            'filter_form'     => $traducido['filter_form'],
            'imagen'          => $imagen,
            'orden'           => $parametros['orden'],
            'limite'          => $parametros['limite'],
            'solo_sin_imagen' => $parametros['solo_sin_imagen'],
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_IMAGENES_ARTICULOS,
            self::clave($parametros['filtros'], $parametros['solo_sin_imagen'], $parametros['orden'], $parametros['limite']),
            $datos,
            ['titulo' => self::TITULO, 'renglones' => $renglones, 'aviso' => self::AVISO],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Búsqueda de imágenes para ' . $a_procesar . ' artículos',
            [
                'articulos'                 => $a_procesar,
                'total_del_filtro'          => $total,
                'busquedas_disponibles_hoy' => $cuota['disponibles'],
                'cuota_diaria'              => $cuota['cuota'],
            ]
        );
    }

    /**
     * Identidad de la carga para el reemplazo: mismo filtro, mismo alcance, misma tanda.
     *
     * @param  array  $filtros
     * @param  bool  $solo_sin_imagen
     * @param  string  $orden
     * @param  int  $limite
     * @return string
     */
    public static function clave(array $filtros, $solo_sin_imagen, $orden, $limite)
    {
        return 'imagenes_articulos:' . sha1((string) json_encode([$filtros, (bool) $solo_sin_imagen, (string) $orden, (int) $limite], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Manda la búsqueda. Corre en el request del clic (o en el turno del agente, en "resuelto"),
     * adentro de la transacción de EjecutorAccionesIaHelper.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            throw new AccionIaException(422, self::mensaje_sin_permiso());
        }

        if (is_null($contexto->owner)) {

            throw new AccionIaException(422, 'No pude identificar la cuenta del negocio.');
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $filter_form = isset($datos['filter_form']) && is_array($datos['filter_form']) ? $datos['filter_form'] : [];
        $imagen = isset($datos['imagen']) ? $datos['imagen'] : null;
        $orden = isset($datos['orden']) ? (string) $datos['orden'] : FiltroDeArticulosIaHelper::ORDEN_PRIMEROS_CREADOS;
        $limite = isset($datos['limite']) ? (int) $datos['limite'] : 0;

        $ids = FiltroDeArticulosIaHelper::ids($contexto->owner_id, $filter_form, $imagen, $orden, $limite);

        if (!count($ids)) {

            throw new AccionIaException(422, 'Ya no queda ningún artículo que cumpla ese filtro. Pedímelo de nuevo si hace falta.');
        }

        if (count($ids) > self::TOPE) {

            throw new AccionIaException(422, 'Son ' . count($ids) . ' artículos y el tope de una tanda es ' . self::TOPE . '. Pedímelo de nuevo con un filtro más acotado.');
        }

        // Se re-mira la cuota al confirmar: la tarjeta pudo quedar propuesta a la mañana y el lote
        // del listado gastar las búsquedas del día en el medio. Encolar con cuota cero es decir
        // "mandé a buscar" y que el job termine al toque con todo sin procesar.
        if ((int) ImagenesAutomaticasHelper::cuota_de($contexto->owner)['disponibles'] <= 0) {

            throw new AccionIaException(422, self::MENSAJE_SIN_CUOTA);
        }

        $persona = $contexto->persona;

        $auth_user_id = !is_null($persona) ? (int) $persona->id : (int) $contexto->conversation->auth_user_id;

        ImagenesAutomaticasHelper::encolar($contexto->owner, $ids, $auth_user_id);

        return [
            'texto' => 'Mandé a buscar imágenes para ' . count($ids) . ' artículos. Te va a aparecer en el sistema cuando termine.',
            'ruta'  => [
                'name'   => 'article',
                'params' => new \stdClass(),
                'texto'  => 'Ver el listado',
            ],
        ];
    }

    /**
     * "100 (los primeros 100 cargados, sin imagen)", "214 (sin imagen)", "50 (los últimos 50 cargados)".
     *
     * @param  int  $cantidad
     * @param  string  $orden
     * @param  int  $limite
     * @param  string|null  $imagen
     * @return string
     */
    protected static function detalle_de_articulos($cantidad, $orden, $limite, $imagen)
    {
        $partes = [];

        if ($limite > 0) {

            $partes[] = ($orden === FiltroDeArticulosIaHelper::ORDEN_ULTIMOS_CREADOS ? 'los últimos ' : 'los primeros ') . $cantidad . ' cargados';
        }

        if ($imagen === FiltroDeArticulosIaHelper::IMAGEN_EN_BLANCO) {

            $partes[] = 'sin imagen';

        } elseif ($imagen === FiltroDeArticulosIaHelper::IMAGEN_NO_EN_BLANCO) {

            $partes[] = 'con imagen';
        }

        return (string) $cantidad . (count($partes) ? ' (' . implode(', ', $partes) . ')' : '');
    }

    /**
     * @return string
     */
    protected static function mensaje_sin_permiso()
    {
        return PermisosIaHelper::mensaje_sin_permiso('imágenes de artículos');
    }
}
