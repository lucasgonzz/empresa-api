<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Description;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El ALTA DE UN ARTÍCULO CON SU FOTO Y SU DESCRIPCIÓN, en UNA sola tarjeta (misión
 * asistente-fotos-barras-y-compras, 24/9/2026).
 *
 * El caso: el dueño manda la foto de un producto y dice "cargalo". Antes eran dos tarjetas —el alta
 * con proponer_alta y, recién con el artículo creado, la foto con proponer_foto_articulo—, o sea dos
 * "¿lo registro?" y dos "sí" para una sola cosa que la persona pidió de una vez. Y la segunda tarjeta
 * no se podía armar hasta que la primera se confirmara, porque el artículo todavía no existía.
 *
 * Ahora proponer_alta de `article` acepta tres extras opcionales, que NO van al controller (la
 * pantalla de artículos no los recibe en su store(): la foto y la descripción se cargan después,
 * cada una por su pantalla) sino en `datos.extras` de la tarjeta:
 *
 *   - `con_foto_de_la_conversacion`: la foto más nueva que el DUEÑO mandó y no se usó
 *     (FotosDeLaConversacionIaHelper::la_mas_nueva, la misma ventana que la foto de un artículo).
 *   - `imagen_id`: una foto puntual por su id. Es la que devuelve la búsqueda por código de barras
 *     (constructor B de la misión), guardada como `ai_message_imagenes` colgada del mensaje del
 *     ASISTENTE. Se valida que sea de esta conversación, del dueño y que no se haya usado.
 *   - `descripcion`: el texto de la descripción del producto.
 *
 * Al confirmar, primero se crea el artículo por el controller de la pantalla (EjecutorGenericoIaHelper,
 * igual que cualquier alta) y DESPUÉS, con el artículo ya creado, se le asigna la foto con los mismos
 * cuatro efectos de la pantalla (PropuestaFotoArticuloIaHelper::asignar_imagen) y se crea la
 * descripción como la crea la pantalla (ArticleDescriptionAiController::store).
 *
 * 🔴 SI LA FOTO O LA DESCRIPCIÓN FALLAN, EL ARTÍCULO NO SE DESHACE. El alta ya pasó por el controller
 * de la pantalla, que pudo haber hablado con Tienda Nube: deshacerla desde acá sería borrar de este
 * lado algo que ya salió del sistema (el mismo motivo por el que las altas van en dos etapas, ver
 * EjecutorAccionesIaHelper::TIPOS_DE_DOS_ETAPAS). El resultado lo dice: "creado, pero la foto no se
 * pudo asignar: <motivo>", y la foto queda sin usar para volver a intentarla con la foto de un
 * artículo.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * Misión alta-por-agente-margen-y-stock (29/9/2026, caso demo3 artículo 17320, la Cera Nic)
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * El mismo mecanismo de extras lleva ahora dos cosas más que la persona dicta en el mismo pedido
 * y que la pantalla de artículos tampoco recibe en su store():
 *
 *   - `margenes_por_lista`: el margen de cada lista de precio en un negocio con listas. SÍ termina
 *     en el controller, pero no como campo: EjecutorGenericoIaHelper lo convierte en el
 *     `price_types` que manda la ficha (MargenesPorListaIaHelper::price_types_para_el_payload)
 *     antes de llamar a store(). Acá se separa, se hereda, se resuelve y, después del alta, se
 *     VERIFICA releyendo el pivote.
 *   - `stock_inicial` (y `deposito` si el negocio tiene depósitos): se resuelve al proponer
 *     (PropuestaStockIaHelper::resolver_stock_inicial) y se carga DESPUÉS del alta, como la foto,
 *     por el camino del botón "Asignar Stock" (PropuestaStockIaHelper::cargar_stock_inicial).
 *
 * 🔴 En demo3 el agente dijo "las 20 unidades ya quedaron cargadas" y el margen "del 30 %"
 * quedó en 0 % en las dos listas: el resultado del alta decía "creado" y nada más. Por eso
 * completar() enumera lo que QUEDÓ (el precio de cada lista releído del pivote) y lo que falló:
 * es lo único que el modelo repite.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class AltaDeArticuloConFotoIaHelper
{
    /** Las claves de los extras, tal como las recibe proponer_alta. */
    const CON_FOTO = 'con_foto_de_la_conversacion';
    const IMAGEN_ID = 'imagen_id';
    const DESCRIPCION = 'descripcion';

    /** El margen por lista (misión alta-por-agente-margen-y-stock, 29/9/2026). */
    const MARGENES = MargenesPorListaIaHelper::CLAVE;

    /** El stock inicial y su depósito (misión alta-por-agente-margen-y-stock, 29/9/2026). */
    const STOCK_INICIAL = 'stock_inicial';
    const DEPOSITO = 'deposito';

    /** La única entidad que acepta extras. */
    const ENTIDAD = 'article';

    /**
     * Techo de la descripción: el texto de una ficha de tienda, no un documento. Era 5000 hasta el
     * segundo chequeo adversarial (24/9/2026); la tienda la pinta tal cual y se sincroniza a Tienda
     * Nube, así que un texto largo del modelo no tiene por qué llegar entero.
     */
    const LARGO_MAXIMO_DESCRIPCION = 1200;

    /**
     * Marcas internas de "la persona pidió QUITAR" la foto o la descripción heredadas en una
     * corrección (ver heredar()). No viajan a la tarjeta: sólo cortan la herencia.
     */
    const QUITAR_FOTO = 'quitar_foto';
    const QUITAR_DESCRIPCION = 'quitar_descripcion';
    const QUITAR_MARGENES = 'quitar_margenes_por_lista';
    const QUITAR_STOCK = 'quitar_stock_inicial';

    /** Cuánto de la descripción se muestra en la tarjeta: la persona la ve, pero no entera. */
    const LARGO_EN_LA_TARJETA = 300;

    /** El título de la sección de descripción, como la guarda la pantalla. */
    const TITULO_DESCRIPCION = 'Descripción';

    /**
     * Separa los extras de lo que va al controller. Los acepta como parámetros propios de la
     * herramienta y TAMBIÉN adentro de `datos` (el modelo a veces los pone ahí): si quedaran en
     * `datos`, validar_campos() los rechazaría con "el campo no existe", que es verdad para la
     * pantalla de artículos pero no para esta tarjeta.
     *
     * 🔴 De `datos` se sacan SÓLO si la entidad es un artículo: otra entidad puede tener de verdad
     * un campo que se llame `descripcion`, y ése tiene que seguir yendo a su pantalla.
     *
     * @param  array  $input  El input crudo de la herramienta.
     * @param  array  $datos  `datos` ya convertido a array.
     * @return array  [$datos_sin_extras, $extras]
     */
    public static function separar(array $input, array $datos)
    {
        $extras = [];

        $entidad = isset($input['entidad']) && is_scalar($input['entidad']) ? trim((string) $input['entidad']) : '';

        $declaracion = $entidad === '' ? null : CatalogoDeEscrituraIaHelper::declaracion($entidad);

        $es_articulo = !is_null($declaracion) && $declaracion['entidad'] === self::ENTIDAD;

        foreach (self::claves() as $clave) {

            if ($es_articulo && array_key_exists($clave, $datos)) {

                $extras[$clave] = $datos[$clave];
                unset($datos[$clave]);
            }

            /*
             * Un valor "vacío" (false, 0, "") que vino EXPLÍCITO también se toma: en un artículo es
             * la forma de decir "sin foto" o "sin descripción" en una corrección (ver limpiar()).
             */
            if (array_key_exists($clave, $input) && !is_null($input[$clave])) {

                $extras[$clave] = $input[$clave];
            }
        }

        return [$datos, self::limpiar($extras, $es_articulo)];
    }

    /**
     * Las claves de los extras que acepta proponer_alta, en el orden en que se separan.
     *
     * @return array<int, string>
     */
    public static function claves()
    {
        return [self::CON_FOTO, self::IMAGEN_ID, self::DESCRIPCION, self::MARGENES, self::STOCK_INICIAL, self::DEPOSITO];
    }

    /**
     * Los extras que una CORRECCIÓN del alta no volvió a mandar, heredados de la tarjeta que
     * reemplaza (correcciones del 24/9/2026).
     *
     * 🔴 POR QUÉ ES DETERMINISTA Y NO UNA REGLA DE PROMPT. En la prueba real, con "sí, pero cambiale
     * el nombre" el modelo armó la tarjeta nueva con reemplaza_a e INVENTÓ `imagen_id: 310` (la línea
     * de historial de la tarjeta dice "Foto encontrada en internet", sin id), y en otra corrida
     * reescribió la descripción inventando una frase (la línea de historial recorta los renglones a
     * AccionesIaHelper::LARGO_RENGLONES_HISTORIAL). Lo que el modelo no manda se toma de la tarjeta
     * anterior tal cual estaba —la foto ya resuelta por su id, la descripción entera—; lo que manda,
     * manda él.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $reemplaza_a
     * @param  array  $extras  Lo que devolvió separar().
     * @return array
     */
    public static function heredar(ContextoDeCargaIa $contexto, $reemplaza_a, array $extras)
    {
        $reemplaza_a = is_numeric($reemplaza_a) ? (int) $reemplaza_a : 0;

        if ($reemplaza_a <= 0) {

            return $extras;
        }

        /*
         * 🔴 SOLO DE UNA TARJETA VIVA —propuesta, o recién reemplazada en una cadena de
         * correcciones—, igual que con_los_campos_de_la_tarjeta_reemplazada() (ronda de correcciones
         * del 29/9/2026). En "directo" un reemplaza_a mal usado sobre un alta YA CONFIRMADA heredaba
         * su stock inicial de 20 y el alta nueva se ejecutaba sola cargándolo otra vez en otro
         * artículo. De una tarjeta confirmada, cancelada o vencida no se hereda nada.
         */
        $anterior = AiMessageAction::where('id', $reemplaza_a)
                                    ->where('ai_conversation_id', $contexto->conversation->id)
                                    ->where('tipo', AiMessageAction::TIPO_ALTA)
                                    ->whereIn('estado', [AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::ESTADO_REEMPLAZADA])
                                    ->first();

        if (is_null($anterior) || !is_array($anterior->datos) || !isset($anterior->datos['extras']) || !is_array($anterior->datos['extras'])) {

            return $extras;
        }

        $de_antes = $anterior->datos['extras'];

        $trae_foto = isset($extras[self::IMAGEN_ID]) || !empty($extras[self::CON_FOTO]) || !empty($extras[self::QUITAR_FOTO]);

        if (!$trae_foto && !empty($de_antes['imagen_id'])) {

            $extras[self::IMAGEN_ID] = (int) $de_antes['imagen_id'];

            /* La foto del código de barras que anotó la tarjeta anterior se sigue sellando con ésta. */
            if (!empty($de_antes['fotos_del_pedido']) && is_array($de_antes['fotos_del_pedido'])) {

                $extras['fotos_del_pedido'] = $de_antes['fotos_del_pedido'];
            }
        }

        if (!isset($extras[self::DESCRIPCION]) && empty($extras[self::QUITAR_DESCRIPCION]) && isset($de_antes['descripcion']) && trim((string) $de_antes['descripcion']) !== '') {

            $extras[self::DESCRIPCION] = (string) $de_antes['descripcion'];
        }

        /*
         * Misión alta-por-agente-margen-y-stock (29/9/2026): el margen por lista se hereda igual que
         * la descripción. Va con el `price_type_id` ya resuelto en la tarjeta anterior (además del
         * nombre, para el renglón): "sí, pero cambiale el nombre" no tiene por qué volver a
         * adivinar a qué lista se refería "la general". `[]` explícito (QUITAR_MARGENES) corta la
         * herencia.
         *
         * 🔴 Y SI LA CORRECCIÓN MANDA MÁRGENES, SE SUMAN A LOS HEREDADOS, no los pisan (ronda de
         * correcciones del 29/9/2026): "sí, y a la mayorista 40 también" sobre una tarjeta con la
         * minorista en 30 deja las dos; si repite una lista, gana lo nuevo. Ver
         * MargenesPorListaIaHelper::mezclar_con_heredados().
         */
        if (empty($extras[self::QUITAR_MARGENES])
            && !empty($de_antes[self::MARGENES])
            && is_array($de_antes[self::MARGENES])) {

            $mezclados = MargenesPorListaIaHelper::mezclar_con_heredados(
                array_key_exists(self::MARGENES, $extras) ? $extras[self::MARGENES] : null,
                $de_antes[self::MARGENES]
            );

            if (!is_null($mezclados)) {

                $extras[self::MARGENES] = $mezclados;
            }
        }

        /*
         * El stock inicial también: la cantidad y el depósito se heredan cada uno por su lado ("sí,
         * pero que sean 30" cambia la cantidad y deja el depósito; "ponelo en Florida" al revés).
         * `stock_inicial: 0` explícito (QUITAR_STOCK) saca los dos.
         */
        if (empty($extras[self::QUITAR_STOCK]) && !empty($de_antes[self::STOCK_INICIAL]) && is_array($de_antes[self::STOCK_INICIAL])) {

            $stock_de_antes = $de_antes[self::STOCK_INICIAL];

            if (!array_key_exists(self::STOCK_INICIAL, $extras) && isset($stock_de_antes['cantidad'])) {

                $extras[self::STOCK_INICIAL] = (float) $stock_de_antes['cantidad'];
            }

            if (!array_key_exists(self::DEPOSITO, $extras) && !empty($stock_de_antes['deposito'])) {

                $extras[self::DEPOSITO] = (string) $stock_de_antes['deposito'];
            }
        }

        return $extras;
    }

    /**
     * Resuelve los extras al proponer: busca la foto y arma lo que se guarda en la tarjeta, los
     * renglones que la persona ve y la miniatura. Devuelve la respuesta negativa si falta la foto
     * que se pidió.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $extras  Lo que devolvió separar() (y heredar()).
     * @param  mixed  $unidades_individuales  Las unidades por bulto que trae el alta (para el renglón del stock), o null.
     * @return array  ['extras' => array, 'renglones' => array, 'imagen_url' => string|null] o la respuesta negativa.
     */
    public static function resolver(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $extras, $unidades_individuales = null)
    {
        $guardar = [];
        $renglones = [];

        $imagen = null;

        if (isset($extras[self::IMAGEN_ID])) {

            $imagen = FotosDeLaConversacionIaHelper::por_id($contexto, $extras[self::IMAGEN_ID]);

            /*
             * 🔴 Un imagen_id que no existe NO cae en otra foto en silencio: es casi seguro un id
             * inventado (prueba real del 24/9/2026, el 310), y la foto que quedaría publicada sería
             * otra que la que la persona vio.
             */
            if (is_null($imagen)) {

                return RespuestaDeCargaIa::error(
                    'La foto con imagen_id ' . (int) $extras[self::IMAGEN_ID] . ' no existe entre las de esta conversación o ya se usó. '
                    . 'No inventes ids: usá sólo el imagen_id que te devolvió una herramienta. Si estás corrigiendo una tarjeta con '
                    . 'reemplaza_a y la foto no cambia, no mandes imagen_id: se hereda sola.'
                );
            }

        } elseif (!empty($extras[self::CON_FOTO])) {

            $imagen = FotosDeLaConversacionIaHelper::la_mas_nueva($contexto, $mensaje);

            if (is_null($imagen)) {

                return RespuestaDeCargaIa::error(
                    'No tengo ninguna foto tuya sin usar de las últimas ' . FotosDeLaConversacionIaHelper::HORAS . ' horas. Mandámela y te cargo el artículo con ella.'
                );
            }
        }

        $imagen_url = null;

        if (!is_null($imagen)) {

            $del_dueno = FotosDeLaConversacionIaHelper::la_mando_el_dueno($imagen);

            $guardar['imagen_id'] = (int) $imagen->id;
            $guardar['imagen_origen'] = $del_dueno ? 'conversacion' : 'internet';

            /* El mismo endpoint autenticado que usa el chat para las fotos: no filtra por rol. */
            $imagen_url = FotosDelMensajeIaHelper::url((int) $imagen->ai_message_id, (int) $imagen->orden);

            /*
             * 🔴 Con la foto de INTERNET, la foto que mandó el dueño en el pedido (la del código de
             * barras) ya cumplió: sirvió para leer el código. Si no se sella al ejecutar, queda 24
             * horas como "foto sin usar" y se cuela en la próxima carga (la foto de otro artículo,
             * una página de factura). Se anotan acá y se sellan en completar().
             */
            if (!$del_dueno) {

                $heredadas = isset($extras['fotos_del_pedido']) && is_array($extras['fotos_del_pedido']) ? $extras['fotos_del_pedido'] : [];

                $guardar['fotos_del_pedido'] = array_values(array_unique(array_merge(
                    array_map('intval', $heredadas),
                    self::fotos_del_pedido($contexto, $mensaje)
                )));
            }

            $cuando = FotosDeLaConversacionIaHelper::cuando_llego($imagen);

            $renglones[] = [
                'etiqueta' => 'Foto',
                'valor'    => $del_dueno
                    ? 'La que mandaste' . ($cuando === '' ? '' : ' (' . $cuando . ')')
                    : 'Foto encontrada en internet',
            ];
        }

        if (isset($extras[self::DESCRIPCION])) {

            $guardar['descripcion'] = $extras[self::DESCRIPCION];

            $renglones[] = ['etiqueta' => 'Descripción', 'valor' => self::recortar($extras[self::DESCRIPCION], self::LARGO_EN_LA_TARJETA)];
        }

        /*
         * El margen por lista: se resuelve contra las listas del dueño AL PROPONER (una lista que no
         * existe o que es ambigua vuelve como error o `faltan`, sin tarjeta) y queda guardado ya
         * resuelto, con el id de cada lista. La tarjeta muestra un renglón por lista nombrada; el
         * aviso con las demás lo arma proponer_alta, porque va aunque no venga ningún margen.
         */
        if (array_key_exists(self::MARGENES, $extras)) {

            $margenes = MargenesPorListaIaHelper::resolver($contexto, $extras[self::MARGENES]);

            if (RespuestaDeCargaIa::es_negativa($margenes)) {

                return $margenes;
            }

            if (count($margenes)) {

                $guardar[self::MARGENES] = $margenes;

                foreach (MargenesPorListaIaHelper::renglones_del_alta($margenes) as $renglon) {

                    $renglones[] = $renglon;
                }
            }
        }

        /*
         * 🔴 El stock inicial (misión alta-por-agente-margen-y-stock, 29/9/2026): si la persona lo
         * dijo, va en un renglón de la tarjeta o no va. En demo3 el agente dijo "tengo listo el
         * alta con 20 unidades" sin ningún lugar donde cargarlas. Se resuelve AL PROPONER (permiso,
         * cantidad y depósito) y se carga después del alta, en completar().
         */
        if (array_key_exists(self::STOCK_INICIAL, $extras)) {

            $stock = PropuestaStockIaHelper::resolver_stock_inicial(
                $contexto,
                $extras[self::STOCK_INICIAL],
                isset($extras[self::DEPOSITO]) ? $extras[self::DEPOSITO] : ''
            );

            if (RespuestaDeCargaIa::es_negativa($stock)) {

                return $stock;
            }

            $guardar[self::STOCK_INICIAL] = $stock;

            /*
             * Con unidades por bulto, el renglón dice que son unidades SUELTAS (ronda de correcciones
             * del 29/9/2026): el asistente no multiplica por el bulto a propósito (ver
             * PropuestaStockIaHelper::cargar_stock_inicial), y la persona tiene que poder ver eso
             * ANTES de confirmar, no descubrirlo en el Listado.
             */
            $renglones[] = [
                'etiqueta' => 'Stock inicial',
                'valor'    => PropuestaStockIaHelper::texto_de_cantidad($stock['cantidad'], $unidades_individuales)
                    . (is_null($stock['deposito']) ? '' : ' (en ' . $stock['deposito'] . ')'),
            ];

        } elseif (isset($extras[self::DEPOSITO])) {

            /* Un depósito suelto no carga nada: se dice, no se ignora. */
            return RespuestaDeCargaIa::error(
                MargenesPorListaIaHelper::NO_SE_CARGO . 'mandaste un depósito sin stock_inicial, y el depósito es para el stock inicial del artículo. Si la persona dijo cuántas unidades, volvé a llamar con stock_inicial; si no, volvé a llamar sin deposito.'
            );
        }

        return ['extras' => $guardar, 'renglones' => $renglones, 'imagen_url' => $imagen_url];
    }

    /**
     * Los ids de las fotos sin usar del mensaje del dueño que disparó esta propuesta (el último
     * `user` anterior al assistant que propone).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @return array<int, int>
     */
    protected static function fotos_del_pedido(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        $pedido = AiMessage::where('ai_conversation_id', $contexto->conversation->id)
                            ->where('rol', 'user')
                            ->where('id', '<', (int) $mensaje->id)
                            ->orderBy('id', 'DESC')
                            ->value('id');

        if (is_null($pedido)) {

            return [];
        }

        $ids = [];

        foreach (AiMessageImagen::where('ai_message_id', (int) $pedido)
                                ->where('user_id', $contexto->owner_id)
                                ->sinGestionar()
                                ->pluck('id') as $id) {

            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * Después del alta: le asigna la foto y le crea la descripción al artículo recién creado, y
     * completa el texto del resultado con lo que pasó. Nunca lanza (ver el 🔴 del docblock).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $resultado  El resultado del alta (EjecutorGenericoIaHelper), con `id` y `texto`.
     * @param  array  $extras  `datos.extras` de la tarjeta.
     * @return array  El mismo resultado, con `texto` completado y `extras` con lo que se hizo.
     */
    public static function completar(ContextoDeCargaIa $contexto, array $resultado, array $extras)
    {
        $articulo = Article::where('user_id', $contexto->owner_id)
                            ->where('id', isset($resultado['id']) ? (int) $resultado['id'] : 0)
                            ->first();

        if (is_null($articulo)) {

            return $resultado;
        }

        $hechos = [];
        $fallas = [];

        if (!empty($extras['imagen_id'])) {

            $motivo = self::asignar_foto($contexto, $articulo, (int) $extras['imagen_id']);

            if (is_null($motivo)) {

                $hechos[] = 'la foto';

            } else {

                $fallas[] = 'la foto no se pudo asignar: ' . $motivo;
            }
        }

        if (isset($extras['descripcion']) && trim((string) $extras['descripcion']) !== '') {

            $motivo = self::crear_descripcion($articulo, (string) $extras['descripcion']);

            if (is_null($motivo)) {

                $hechos[] = 'la descripción';

            } else {

                $fallas[] = 'la descripción no se pudo guardar: ' . $motivo;
            }
        }

        /*
         * El margen por lista ya lo escribió el controller (entró como `price_types`, el mismo
         * camino que la ficha). Acá se RELEE el pivote: el resultado dice el precio que quedó en
         * cada lista, y si una lista no quedó con ese margen, lo dice como falla. Ver el 🔴 de
         * MargenesPorListaIaHelper::verificar().
         */
        if (!empty($extras[self::MARGENES]) && is_array($extras[self::MARGENES])) {

            // Protegida, como la foto: una relectura que falla no puede volver 500 un alta hecha.
            $verificado = MargenesPorListaIaHelper::verificar_protegido((int) $articulo->id, $extras[self::MARGENES]);

            foreach ($verificado['hechos'] as $hecho) {

                $hechos[] = $hecho;
            }

            foreach ($verificado['fallas'] as $falla) {

                $fallas[] = $falla;
            }
        }

        /*
         * El stock inicial va DESPUÉS de la foto y la descripción, con el artículo ya creado: el
         * movimiento necesita el artículo, y en uno recién creado CheckToAddress le abre el
         * depósito porque no hay stock global que perder. Si falla, el artículo no se deshace (ver
         * el 🔴 del docblock): el resultado lo dice y el stock se puede pedir de nuevo.
         */
        if (!empty($extras[self::STOCK_INICIAL]) && is_array($extras[self::STOCK_INICIAL])) {

            $stock = $extras[self::STOCK_INICIAL];

            $cantidad = isset($stock['cantidad']) ? (float) $stock['cantidad'] : 0.0;

            $address_id = isset($stock['address_id']) && !is_null($stock['address_id']) ? (int) $stock['address_id'] : null;

            $motivo = PropuestaStockIaHelper::cargar_stock_inicial($contexto, $articulo, $cantidad, $address_id);

            if (is_null($motivo)) {

                $hechos[] = 'stock inicial ' . PropuestaStockIaHelper::texto_de_cantidad($cantidad, $articulo->unidades_individuales)
                    . (empty($stock['deposito']) ? '' : ' en ' . $stock['deposito']);

            } else {

                // cargar_stock_inicial() es atómico: si devolvió un motivo, no quedó nada escrito.
                $fallas[] = 'el stock inicial NO se cargó (' . $motivo . ')';
            }
        }

        /*
         * La foto del código de barras del pedido se sella con el alta hecha, haya quedado o no la
         * foto de internet: ver el 🔴 de resolver(). Protegido: un sello que falla no deshace nada.
         */
        if (!empty($extras['fotos_del_pedido']) && is_array($extras['fotos_del_pedido'])) {

            try {

                AsistenteImagenHelper::marcar_gestionadas(array_map('intval', $extras['fotos_del_pedido']));

            } catch (\Throwable $e) {

                Log::warning('AltaDeArticuloConFotoIaHelper: no se pudieron sellar las fotos del pedido', [
                    'article_id' => (int) $articulo->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $texto = isset($resultado['texto']) ? (string) $resultado['texto'] : '';

        if (count($hechos)) {

            // "la foto y la descripción" como siempre; con más de dos, "a, b y c".
            $texto .= ', con ' . MargenesPorListaIaHelper::enumerar($hechos);
        }

        if (count($fallas)) {

            $texto .= ', pero ' . implode('; y ', $fallas);
        }

        $resultado['texto'] = $texto;
        $resultado['extras'] = ['hechos' => $hechos, 'fallas' => $fallas];

        return $resultado;
    }

    /**
     * Asigna la foto con el candado de la fila (dos tarjetas no se llevan la misma foto) y los
     * cuatro efectos de la pantalla. Devuelve null si quedó, o el motivo si no.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Article  $articulo
     * @param  int  $imagen_id
     * @return string|null
     */
    protected static function asignar_foto(ContextoDeCargaIa $contexto, Article $articulo, $imagen_id)
    {
        try {

            DB::transaction(function () use ($contexto, $articulo, $imagen_id) {

                $imagen = AiMessageImagen::where('user_id', $contexto->owner_id)
                                            ->where('id', (int) $imagen_id)
                                            ->sinGestionar()
                                            ->lockForUpdate()
                                            ->first();

                if (is_null($imagen)) {

                    throw new AccionIaException(422, 'esa foto ya se usó o no está disponible');
                }

                PropuestaFotoArticuloIaHelper::asignar_imagen($contexto, $articulo, $imagen);
            });

        } catch (AccionIaException $e) {

            return $e->getMessage();

        } catch (\Throwable $e) {

            Log::warning('AltaDeArticuloConFotoIaHelper: no se pudo asignar la foto del artículo recién creado', [
                'article_id' => (int) $articulo->id,
                'imagen_id'  => (int) $imagen_id,
                'error'      => $e->getMessage(),
            ]);

            return 'falló el sistema al guardarla';
        }

        return null;
    }

    /**
     * Crea la descripción como ArticleDescriptionAiController::store(): una fila de `descriptions`
     * colgada del artículo, marcada como generada por IA y revisada —la persona la vio en la tarjeta
     * antes de confirmar, que es lo mismo que confirmar el preview de la pantalla—, y el aviso a
     * Tienda Nube si el cliente la usa. Devuelve null si quedó, o el motivo si no.
     *
     * @param  \App\Models\Article  $articulo
     * @param  string  $texto
     * @return string|null
     */
    protected static function crear_descripcion(Article $articulo, $texto)
    {
        try {

            Description::create([
                'title'          => self::TITULO_DESCRIPCION,
                'content'        => trim($texto),
                'article_id'     => (int) $articulo->id,
                'ai_generated'   => true,
                /*
                 * 'low' es lo que la pantalla asume cuando nadie dice la confianza: nunca se
                 * sobreestima la certeza de un texto que redactó una IA.
                 */
                'ai_confidence'  => 'low',
                'ai_sources'     => [],
                'ai_reviewed_at' => Carbon::now(),
            ]);

            if (env('USA_TIENDA_NUBE', false)) {

                dispatch(new \App\Jobs\ProcessSyncArticleDescriptionTiendaNube($articulo));
            }

        } catch (\Throwable $e) {

            Log::warning('AltaDeArticuloConFotoIaHelper: no se pudo crear la descripción del artículo recién creado', [
                'article_id' => (int) $articulo->id,
                'error'      => $e->getMessage(),
            ]);

            return 'falló el sistema al guardarla';
        }

        return null;
    }

    /**
     * Normaliza los extras crudos: el sí/no, el id como entero y la descripción como texto recortado.
     * Los vacíos se descartan.
     *
     * @param  array  $extras
     * @return array
     */
    protected static function limpiar(array $extras, $es_articulo = true)
    {
        $limpios = [];

        if (array_key_exists(self::CON_FOTO, $extras)) {

            $valor = $extras[self::CON_FOTO];

            $si = $valor === true || $valor === 1 || $valor === '1'
                || (is_string($valor) && in_array(mb_strtolower(trim($valor)), ['si', 'sí', 'true', 'yes'], true));

            $no = $valor === false || $valor === 0 || $valor === '0'
                || (is_string($valor) && in_array(mb_strtolower(trim($valor)), ['no', 'false'], true));

            if ($si) {

                $limpios[self::CON_FOTO] = true;

            } elseif ($no && $es_articulo) {

                $limpios[self::QUITAR_FOTO] = true;
            }
        }

        if (array_key_exists(self::IMAGEN_ID, $extras) && is_numeric($extras[self::IMAGEN_ID])) {

            if ((int) $extras[self::IMAGEN_ID] > 0) {

                $limpios[self::IMAGEN_ID] = (int) $extras[self::IMAGEN_ID];

            } elseif ($es_articulo) {

                /* imagen_id 0 explícito = "sin foto" (segundo chequeo adversarial, 24/9/2026). */
                $limpios[self::QUITAR_FOTO] = true;
            }
        }

        if (array_key_exists(self::DESCRIPCION, $extras) && is_scalar($extras[self::DESCRIPCION])) {

            $texto = self::sanear_descripcion((string) $extras[self::DESCRIPCION]);

            if ($texto !== '') {

                $limpios[self::DESCRIPCION] = $texto;

            } elseif ($es_articulo) {

                /* descripcion "" explícita = "sin descripción". */
                $limpios[self::QUITAR_DESCRIPCION] = true;
            }
        }

        /*
         * El margen por lista (misión alta-por-agente-margen-y-stock, 29/9/2026) viaja CRUDO: lo
         * valida y lo resuelve MargenesPorListaIaHelper::resolver(), que es quien sabe decir qué
         * está mal ("esa lista no existe", "el margen tiene que ser un número"). Descartarlo acá por
         * un formato raro sería callarse una parte del pedido. `[]` explícito = QUITAR el heredado.
         */
        if (array_key_exists(self::MARGENES, $extras) && !is_null($extras[self::MARGENES])) {

            $valor = $extras[self::MARGENES];

            if ($valor instanceof \stdClass) {

                $valor = json_decode(json_encode($valor), true);
            }

            /*
             * Vacío es [], "", {} y TAMBIÉN el texto "[]" (ronda de correcciones del 29/9/2026):
             * antes "[]" como texto llegaba crudo a resolver() y, en una cuenta SIN listas, cortaba
             * con "no trabaja con listas" un pedido que no traía ningún margen.
             */
            $vacio = MargenesPorListaIaHelper::vino_vacio($valor);

            if ($vacio && $es_articulo) {

                $limpios[self::QUITAR_MARGENES] = true;

            } elseif (!$vacio) {

                $limpios[self::MARGENES] = $valor;
            }
        }

        /*
         * El stock inicial también viaja crudo (lo valida PropuestaStockIaHelper::resolver_stock_inicial).
         * Un 0 explícito —0, "0", "0 unidades", "" o false— es la forma de decir "sin stock" en una
         * corrección: QUITA el heredado, igual que `imagen_id: 0` con la foto.
         */
        if (array_key_exists(self::STOCK_INICIAL, $extras) && !is_null($extras[self::STOCK_INICIAL])) {

            $valor = $extras[self::STOCK_INICIAL];

            $numero = PropuestaStockIaHelper::cantidad_de_stock($valor);

            $es_cero = $valor === false
                || (is_string($valor) && trim($valor) === '')
                || (!is_null($numero) && abs($numero) < 0.0001);

            if ($es_cero && $es_articulo) {

                $limpios[self::QUITAR_STOCK] = true;

            } elseif (!$es_cero) {

                $limpios[self::STOCK_INICIAL] = $valor;
            }
        }

        if (array_key_exists(self::DEPOSITO, $extras) && is_scalar($extras[self::DEPOSITO]) && trim((string) $extras[self::DEPOSITO]) !== '') {

            $limpios[self::DEPOSITO] = trim((string) $extras[self::DEPOSITO]);
        }

        /* Sin stock no hay depósito del stock: una corrección que saca el stock saca los dos. */
        if (!empty($limpios[self::QUITAR_STOCK])) {

            unset($limpios[self::DEPOSITO]);
        }

        /*
         * ⚠️ POR QUÉ LAS MARCAS DE QUITAR (segundo chequeo adversarial, 24/9/2026). Antes un
         * `con_foto_de_la_conversacion: false`, un `imagen_id: 0` o una `descripcion: ""` se
         * descartaban como "no vino nada", y heredar() volvía a poner la foto de la tarjeta
         * reemplazada: "sí, pero sin foto" la seguía publicando. Explícito, ahora QUITA lo heredado.
         */
        if (!empty($limpios[self::IMAGEN_ID]) || !empty($limpios[self::CON_FOTO])) {

            unset($limpios[self::QUITAR_FOTO]);
        }

        return $limpios;
    }

    /**
     * La descripción tal como puede llegar a la ficha y a la tienda: sin HTML, con los espacios
     * colapsados y con techo de LARGO_MAXIMO_DESCRIPCION.
     *
     * 🔴 La tienda la pinta con v-html y se sincroniza a Tienda Nube (segundo chequeo adversarial,
     * 24/9/2026): lo que escribe el modelo —o lo que copió de una página web en la búsqueda por
     * código de barras— no puede meter etiquetas en el catálogo publicado.
     *
     * @param  string  $texto
     * @return string
     */
    public static function sanear_descripcion($texto)
    {
        $texto = strip_tags((string) $texto);

        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return self::recortar($texto, self::LARGO_MAXIMO_DESCRIPCION);
    }

    /**
     * @param  string  $texto
     * @param  int  $largo
     * @return string
     */
    protected static function recortar($texto, $largo)
    {
        $texto = trim((string) $texto);

        return mb_strlen($texto) > $largo ? rtrim(mb_substr($texto, 0, $largo - 1)) . '…' : $texto;
    }
}
