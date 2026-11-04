<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Http\Controllers\Helpers\StoragePathHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\IaImagenPedido;
use App\Models\Image;
use App\Services\MercadoLibre\ProductService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * La foto de un ARTÍCULO, asignada por el asistente (misión asistente-ventas-y-fotos, 21/9/2026).
 *
 * El dueño manda una foto por WhatsApp diciendo "ponele esta foto al destornillador Phillips" y el
 * asistente se la cuelga al artículo. La mecánica de la foto es la misma que la de la sucursal
 * (PropuestaFotoSucursalIaHelper): la imagen sale de las que el dueño mandó y todavía no se usaron,
 * se relee con `lockForUpdate` al ejecutar y se sella con `marcar_gestionadas()`.
 *
 * 🔴 DOS COSAS NO SE COPIAN DE LA DE SUCURSAL, Y NO SON DETALLES:
 *
 * 1. EL FORMATO ES WEBP, NO PNG. El `.png` de ImageController::setImage() es una EXCEPCIÓN para
 *    `user` y `address` (ImageController.php:146-150): son logos que termina imprimiendo la copia
 *    de FPDF de este repo, que solo sabe parsear jpg, png y gif. Un artículo no va a ningún PDF por
 *    ese camino: va a la ficha, al listado y a la tienda, donde webp pesa la mitad. Guardarlo como
 *    png sería cargarle a cada catálogo el peso de una excepción que no le corresponde.
 *
 * 2. EL DESTINO NO ES UNA COLUMNA, ES UNA FILA EN `images` MÁS CUATRO EFECTOS. La sucursal tiene
 *    `addresses.image_url` y se termina con un `save()`. Un artículo tiene N imágenes por morph, y
 *    la pantalla, además de crear la fila, dispara cuatro cosas más (ImageController.php:171-189)
 *    que acá se copian tal cual, en el mismo orden. Si falta cualquiera de las cuatro, el resultado
 *    se PARECE al de la pantalla y no es igual: la foto queda en el sistema y no llega a la tienda,
 *    al ecommerce del cliente vinculado ni a Mercado Libre, y nadie se entera hasta que el dueño
 *    pregunta por qué el producto sigue sin foto en su tienda. Están enumeradas en ejecutar().
 *
 * 🔴 Y NO SE AUTO-CONFIRMA CON EL DUEÑO EN "RESUELTO" (decisión de Lucas, 21/9/2026), aunque la de
 * SUCURSAL sí: no está en HerramientasDeCarga::AUTO_CONFIRMABLES. El motivo es la diferencia con la
 * sucursal: acá el destino se INFIERE de un nombre que el dueño puede decir inexacto, y una foto
 * puesta en el artículo equivocado se PUBLICA (dispara Tienda Nube y Mercado Libre). La de sucursal
 * no puede equivocarse de destino —hay pocas, se eligen por nombre completo y no se publican en
 * ningún lado.
 *
 * ⚠️ Con el dueño en "directo" (misión asistente-capacidades-y-hilos, 22/9/2026) sí se ejecuta
 * sola: entra en AUTO_CONFIRMABLES_DIRECTO y su `case` pasa por quizas_auto_confirmar(), que es
 * quien mira el modo. Ahí el dueño ya pidió a conciencia, una vez y desde la configuración, que sus
 * cargas se hagan sin tarjeta.
 *
 * La foto se resuelve entre las imágenes sin gestionar de la conversación. Sin imagen_id conserva
 * la última enviada por el dueño; con imagen_id permite la foto profesional de una búsqueda previa.
 * Un id explícito inválido jamás cae en otra foto: eso publicaría un contenido distinto del elegido.
 *
 * 🔴 UNA FOTO, N ARTÍCULOS (misión agente-mejoras-uso-real, E1 puntos 1 y 2, 29/10/2026). En Ferre
 * Total (CONV 15) Pablo mandó UNA foto: «ponele esta imagen a los clavos punta París, que todos tengan
 * la misma». Eran 10 clavos; el agente se la puso a uno solo, le dijo que «la foto ya quedó usada» y le
 * pidió que se la reenviara una vez por cada tamaño. Desde esta misión:
 *   - La herramienta acepta `articulo_ids` (hasta TOPE_ARTICULOS) o `todos_los_que_coinciden` (todos
 *     los que encuentra la búsqueda por palabras, en vez de preguntar cuál), y arma UNA tarjeta que
 *     lista los N. `datos` lleva `article_ids` + `articulos` (y `article_id` + `articulo` cuando es uno
 *     solo, el formato de siempre). ejecutar() lee `article_ids` y, si no está, `[article_id]`: las
 *     tarjetas viejas ya guardadas en `ai_message_actions` se siguen ejecutando igual.
 *   - Al ejecutar se escribe UN ARCHIVO POR ARTÍCULO, con nombre propio: ImageController::deleteImage
 *     (ImageController.php:303) borra el archivo del disco al sacarle la foto a un artículo, y un
 *     archivo compartido se la borraría a todos los demás. El webp se codifica una sola vez.
 *   - Un artículo que se borró entre la tarjeta y el sí se SALTEA y se informa (`no_hechos`): no
 *     voltea la transacción de los demás. El resultado trae `hechos` y `no_hechos` por nombre, y el
 *     texto los dice, para que el agente no cierre la tarea diciendo que quedó todo.
 *   - LA MISMA FOTO SE PUEDE VOLVER A USAR por su `imagen_id` si ya se puso en un artículo de esta
 *     conversación (FotosDeLaConversacionIaHelper::reutilizable_para_articulo): «la misma para los
 *     otros 8» ya no exige reenviarla. Una foto que entró en una compra con factura, o una sellada que
 *     no fue a ningún artículo, no.
 *
 * 🔴 LA FOTO DE OTRO ARTÍCULO (misión agente-mejoras-diario-30-10, B3, 31/10/2026). Análisis diario del 30/10,
 * problema #5 (y el 29/10, #2): Pablo pidió «tomá la imagen del clavo de 1 1/2" y usala para todos». La foto
 * ya estaba en el sistema, en otro artículo, y esta herramienta solo sabía tomar fotos mandadas al chat
 * (`ai_message_imagenes`): el agente le contestaba que no le había llegado ninguna y le pedía que la mandara.
 * Desde esta misión:
 *   - `de_articulo_id` (o `de_articulo`, por nombre o código, con el mismo resolver de los destinos) toma la
 *     PORTADA de ese artículo (la de `first` distinto de cero si la columna existe, si no la primera: el mismo
 *     criterio que ArticleHelper::primera_imagen_publica). El origen se saca de los destinos y la tarjeta
 *     dice de qué artículo sale la foto. `datos` lleva `origen = 'articulo'`, `image_id` y `de_article_id`
 *     (y no `imagen_id`: no es una foto del chat, no hay nada que sellar).
 *   - 🔴 SE COPIA EL ARCHIVO, no se reusa la URL: ImageController::deleteImageModel borra por nombre de
 *     archivo, así que compartirla haría que sacarle la foto a un artículo se la saque a todos. Por eso el
 *     archivo tiene que estar en el disco público de ESTE frente (StoragePathHelper, con el nombre sacado de
 *     `hosting_url`): una URL de otro host o un archivo ausente es un error, sin tarjeta.
 *   - `solo_sin_foto` deja afuera a los destinos que ya tienen alguna foto.
 *   - La respuesta (también la de error, una vez resueltos los destinos) trae `articulos` con
 *     `ya_tiene_foto`, `cuantos` y `sin_foto`, de una consulta a `images` (resumen_de_los_destinos()).
 *   - El error de «no hay foto» pasa a CODIGO_FOTO_POR_LLEGAR y le da al modelo la frase para la persona
 *     («mandame la foto y se la pongo a estos N: …»), sin «no me llegó» ni «de nuevo».
 *
 * 🔴 RONDA DE ARREGLOS DE LA REVISIÓN INDEPENDIENTE (F2, F3 y F4, 31/10/2026):
 *   - F2: el origen y `solo_sin_foto` se aplican EN LA CONSULTA de los destinos, antes del tope de
 *     TOPE_ARTICULOS. Antes, «la del clavo de 1 1/2" a los que no tienen foto» con 45 clavos cortaba con «Hay
 *     más de 30 artículos…» aunque sin foto quedaran 8, y el origen contaba para el tope. Por eso el origen se
 *     resuelve PRIMERO (resolver_origen()) y resolver_articulos() recibe el origen y el filtro.
 *   - F3: el resumen de los destinos (`articulos`, `cuantos`, `sin_foto`) se arma con los destinos YA
 *     filtrados: lo que la respuesta cuenta es lo que va en la tarjeta (y en la lista de foto_por_llegar).
 *   - F4: `solo_sin_foto` viaja en `datos` y ejecutar() saltea los destinos que recibieron una foto entre la
 *     tarjeta y el sí; el resultado los nombra con MOTIVO_YA_TENIA_FOTO.
 */
class PropuestaFotoArticuloIaHelper
{
    /**
     * Permiso para colgarle una foto a un artículo: es actualizar el artículo, el mismo criterio
     * (y el mismo slug) que usa PropuestaImagenesArticulosIaHelper para mandar a buscar imágenes.
     * A diferencia de la sucursal —que es configuración del negocio y pide ser dueño— acá alcanza
     * con el permiso que la pantalla le pide a un empleado para editar un artículo.
     */
    const PERMISO = 'article.update';

    /*
     * Acá vivía MENSAJES_PARA_LA_FOTO = 6: la foto se buscaba en los últimos seis mensajes. Desde la
     * misión asistente-fotos-barras-y-compras (24/9/2026) la busca FotosDeLaConversacionIaHelper, por
     * tiempo (24 horas) y sólo entre las que mandó el dueño: en demo3 (conv 10) la ventana de seis
     * dejó afuera una foto que seguía sin usar y el asistente le pidió al dueño que la reenviara.
     */

    /** Tope de candidatos que se ofrecen cuando el nombre del artículo es ambiguo. */
    const TOPE_CANDIDATOS = 10;

    /**
     * Cuántos artículos entran en UNA tarjeta de foto (misión agente-mejoras-uso-real, 29/10/2026).
     * Treinta cubren una familia entera de medidas (los 10 clavos de Ferre Total, las 20 mechas de un
     * juego) sin que la tarjeta sea una lista que nadie lee antes de confirmar; más que eso es una
     * búsqueda de imágenes por filtro (proponer_imagenes_para_articulos), no una foto del dueño.
     */
    const TOPE_ARTICULOS = 30;

    /**
     * Columnas que alcanzan para RESOLVER y para nombrar el artículo en la tarjeta. Al ejecutar se
     * lee el modelo entero (ver ejecutar()): ahí hacen falta los campos de sincronización.
     */
    const COLUMNAS = ['id', 'user_id', 'name', 'bar_code', 'provider_code'];

    /** El `origen` de los `datos` de una tarjeta cuya foto sale de OTRO artículo (B3, 31/10/2026). */
    const ORIGEN_ARTICULO = 'articulo';

    const ORIGEN_GENERADA = 'generada';

    /**
     * El código del error cuando todavía no hay foto que poner (B3, 31/10/2026): la foto está por llegar. El
     * texto le da al modelo la frase para la persona y la lista de a quiénes va.
     */
    const CODIGO_FOTO_POR_LLEGAR = 'foto_por_llegar';

    /** Cuántos nombres de artículos se escriben en el texto del error «foto por llegar» (la lista completa va en `articulos`). */
    const TOPE_NOMBRES_EN_EL_TEXTO = 30;

    /**
     * El motivo de `no_hechos` para un destino que, con `solo_sin_foto`, recibió una foto entre la tarjeta y el sí
     * (ronda de arreglos, F4, 31/10/2026): se saltea, no se le suma otra.
     */
    const MOTIVO_YA_TENIA_FOTO = 'ya tenía foto';

    /**
     * Herramienta proponer_foto_articulo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  articulo (texto), articulo_id, imagen_id opcional, reemplaza_a, y desde la
     *                        misión agente-mejoras-uso-real articulo_ids (int[], hasta TOPE_ARTICULOS) y
     *                        todos_los_que_coinciden (bool); desde la misión agente-mejoras-diario-30-10 (B3)
     *                        de_articulo_id / de_articulo (la foto sale de ese artículo) y solo_sin_foto (bool)
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('fotos de artículos'));
        }

        /*
         * B3 (31/10/2026): la foto de OTRO artículo. El artículo de origen (del negocio) se resuelve PRIMERO
         * (ronda de arreglos, F2): «usala para todos» con todos_los_que_coinciden lo encuentra también a él, y
         * se lo saca de los destinos en la consulta, antes del tope.
         */
        $origen = null;

        if (self::pide_la_foto_de_otro_articulo($input)) {

            $origen = self::resolver_origen($contexto, $input);

            if (RespuestaDeCargaIa::es_negativa($origen)) {

                return $origen;
            }
        }

        $solo_sin_foto = self::es_si(EntradaDeCargaIa::valor($input, 'solo_sin_foto'));

        // F2: sin el origen y, con solo_sin_foto, sin los que ya tienen foto, ANTES del tope de TOPE_ARTICULOS.
        $articulos = self::resolver_articulos($contexto, $input, $origen, $solo_sin_foto);

        if (RespuestaDeCargaIa::es_negativa($articulos)) {

            return $articulos;
        }

        /*
         * Lo que la respuesta cuenta de los destinos (también en los errores de acá en adelante). F3: con los
         * destinos YA filtrados, así `cuantos` y `articulos` son los de la tarjeta.
         */
        $resumen = self::resumen_de_los_destinos($articulos);

        if (!is_null($origen)) {

            return self::proponer_con_la_foto_de_otro_articulo($contexto, $mensaje, $input, $articulos, $origen, $resumen, $solo_sin_foto);
        }

        $explicita = array_key_exists('imagen_id', $input);
        $foto = null;
        $reusa_usada = false;

        if ($explicita) {
            $id = $input['imagen_id'];
            if ((is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]*$/D', (string) $id)
                && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                $foto = FotosDeLaConversacionIaHelper::por_id($contexto, $id);

                /*
                 * Misión agente-mejoras-uso-real: si no está sin usar, puede ser la que YA se puso en
                 * otro artículo de esta conversación («la misma para los otros 8»). Sólo esa: nunca la
                 * de una factura ni una sellada que no fue a ningún artículo.
                 */
                if (is_null($foto)) {
                    $foto = FotosDeLaConversacionIaHelper::reutilizable_para_articulo($contexto, $id);
                    $reusa_usada = !is_null($foto);
                }
            }
            if (is_null($foto)) {
                return self::con_el_resumen(RespuestaDeCargaIa::error('Esa imagen_id no está disponible en esta conversación o ya se usó. Usá el id del hallazgo elegido; no se reemplazó por otra foto.'), $resumen);
            }
        } else {
            $foto = self::ultima_foto_sin_gestionar($contexto, $mensaje);
        }

        if (is_null($foto)) {

            /*
             * 🔴 Sin foto nueva pero con una que ya se puso en un artículo de esta charla: NO se toma
             * sola (el dueño puede estar por mandar otra), se le dice al MODELO con qué id volver a
             * llamar si la persona pidió «la misma». Es el «no se armó ninguna tarjeta» que el prompt
             * dice que es para él: en la CONV 15 de Ferre Total el agente le pidió a Pablo que
             * reenviara una foto que ya tenía.
             */
            $usada = FotosDeLaConversacionIaHelper::la_ultima_reutilizable_para_articulo($contexto, $mensaje);

            if (!is_null($usada)) {

                return self::con_el_resumen(RespuestaDeCargaIa::error(
                    CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'no hay ninguna foto nueva sin usar. Si la persona pidió la MISMA foto que ya le pusiste a otro artículo, '
                    . 'volvé a llamar en este mismo mensaje con imagen_id ' . (int) $usada->id . ' (la que mandó ' . FotosDeLaConversacionIaHelper::cuando_llego($usada) . '). '
                    . 'Si pidió otra foto, decile que te la mande.',
                    ['imagen_id' => (int) $usada->id]
                ), $resumen);
            }

            /*
             * 🔴 B3 (31/10/2026): la foto está POR LLEGAR, no «no llegó». Hasta acá decía «No tengo ninguna foto
             * tuya sin usar… Mandámela y se la pongo al artículo», sin decir a cuáles, y el agente lo traducía
             * en «no me llegó la foto, mandala de nuevo» aunque la persona hubiera pedido usar la de otro
             * artículo (30/10, #5). Ahora el texto le da al modelo la frase con la lista y la salida de la foto
             * de otro artículo.
             */
            return self::con_el_resumen(RespuestaDeCargaIa::error_con_codigo(
                self::CODIGO_FOTO_POR_LLEGAR,
                self::texto_de_la_foto_por_llegar($articulos)
            ), $resumen);
        }

        $ids = [];
        $nombres = [];

        foreach ($articulos as $articulo) {

            $ids[] = (int) $articulo->id;
            $nombres[] = self::nombre_de_articulo($articulo);
        }

        $uno_solo = count($ids) === 1;

        /*
         * `article_ids` + `articulos` siempre; `article_id` + `articulo` cuando es uno solo, que es el
         * formato de siempre (lo leen los tests y las tarjetas de antes de esta misión).
         */
        $datos = [];

        if ($uno_solo) {

            $datos['article_id'] = $ids[0];
            $datos['articulo'] = $nombres[0];
        }

        $datos['imagen_id'] = (int) $foto->id;
        $datos['article_ids'] = $ids;
        $datos['articulos'] = $nombres;
        $datos['reusa_usada'] = $reusa_usada;

        // F4: con solo_sin_foto, ejecutar() saltea a los que reciban una foto entre la tarjeta y el sí.
        if ($solo_sin_foto) {

            $datos['solo_sin_foto'] = true;
        }

        $renglones = $uno_solo
            ? [
                ['etiqueta' => 'Artículo', 'valor' => $nombres[0]],
                ['etiqueta' => 'Qué se hace', 'valor' => 'La foto se suma a las imágenes del artículo'],
            ]
            : [
                ['etiqueta' => 'Artículos (' . count($ids) . ')', 'valor' => implode(', ', $nombres)],
                ['etiqueta' => 'Qué se hace', 'valor' => 'La misma foto se suma a las imágenes de cada uno'],
            ];

        /*
         * Cuándo llegó la foto (misión asistente-fotos-barras-y-compras): con la ventana de 24 horas
         * la foto puede ser de hace un rato largo, y la persona tiene que poder decir "esa no es"
         * antes de que se publique.
         */
        $cuando = FotosDeLaConversacionIaHelper::cuando_llego($foto);

        if (!FotosDeLaConversacionIaHelper::la_mando_el_dueno($foto)) {

            $renglones[] = ['etiqueta' => 'Foto', 'valor' => 'Foto encontrada en internet'];

        } elseif ($reusa_usada) {

            $renglones[] = ['etiqueta' => 'Foto', 'valor' => 'La misma que ya usaste' . ($cuando === '' ? '' : ' (la que mandaste ' . $cuando . ')')];

        } elseif ($cuando !== '') {

            $renglones[] = ['etiqueta' => 'Foto', 'valor' => 'La que mandaste ' . $cuando];
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_FOTO_ARTICULO,
            self::clave($ids),
            $datos,
            [
                'titulo'    => $uno_solo ? 'Foto del artículo' : 'Foto de ' . count($ids) . ' artículos',
                'renglones' => $renglones,
                /*
                 * El aviso es la contracara de que esto NO se auto-confirme: la persona tiene que
                 * saber, antes de tocar Confirmar, que esta foto sale publicada.
                 */
                'aviso'     => 'La foto también se publica en la tienda online del negocio.',
                'imagen_url' => FotosDelMensajeIaHelper::url($foto->ai_message_id, $foto->orden),
                'imagen_mensaje' => ['ai_message_id' => (int) $foto->ai_message_id, 'orden' => (int) $foto->orden],
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $texto_del_resumen = $uno_solo
            ? 'Foto del artículo ' . $nombres[0]
            : 'Foto de ' . count($ids) . ' artículos: ' . implode(', ', $nombres);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $texto_del_resumen, $resumen);
    }

    public static function proponer_generada(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {
            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('fotos de artículos'));
        }

        $uuid = $input['pedido_imagen_uuid'] ?? null;

        if (!$mensaje->es_de_la_app() || !is_string($uuid) || !\Illuminate\Support\Str::isUuid($uuid)) {
            return RespuestaDeCargaIa::error('Elegí una imagen generada en esta conversación de ComercioCity IA.');
        }

        $pedido = self::pedido_generado($contexto, $uuid)->first();

        if (!$pedido || $pedido->estado !== IaImagenPedido::ESTADO_LISTA || !$pedido->article_id) {
            return RespuestaDeCargaIa::error('La imagen todavía no está lista o no pertenece a un artículo de esta conversación.');
        }

        $articulo = Article::where('user_id', $contexto->owner_id)->find($pedido->article_id);

        if (!$articulo || ($input['articulo_id'] ?? $articulo->id) != $articulo->id) {
            return RespuestaDeCargaIa::error('La imagen fue generada para otro artículo.');
        }

        if ($pedido->publicada_image_id) {
            return RespuestaDeCargaIa::error('Esa imagen ya se publicó en el artículo.');
        }

        $principal = self::es_si($input['como_principal'] ?? false);
        $nombre = self::nombre_de_articulo($articulo);
        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_FOTO_ARTICULO,
            self::clave([$articulo->id]),
            [
                'article_id' => (int) $articulo->id,
                'articulo' => $nombre,
                'origen' => self::ORIGEN_GENERADA,
                'pedido_imagen_uuid' => $pedido->uuid,
                'como_principal' => $principal,
            ],
            [
                'titulo' => 'Publicar imagen generada',
                'renglones' => [
                    ['etiqueta' => 'Artículo', 'valor' => $nombre],
                    ['etiqueta' => 'Qué se hace', 'valor' => $principal ? 'Usar como foto principal y conservar la anterior' : 'Agregar a la galería'],
                ],
                'aviso' => 'La foto también se publica en la tienda online del negocio.',
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        return AccionesIaHelper::respuesta_de_propuesta($creada, 'Publicar la imagen generada de ' . $nombre);
    }

    protected static function pedido_generado(ContextoDeCargaIa $contexto, $uuid)
    {
        return IaImagenPedido::where('uuid', $uuid)
            ->where('user_id', $contexto->owner_id)
            ->whereHas('mensaje', function ($query) use ($contexto) {
                $query->where('ai_conversation_id', $contexto->conversation->id);
            });
    }

    /**
     * La tarjeta cuando la foto sale de OTRO artículo (B3, 31/10/2026). El origen ya está resuelto y fuera de
     * los destinos; acá se busca su portada y se verifica que el archivo exista en el disco público de este
     * frente. Una URL de otro host, un archivo que no está o un artículo sin fotos cortan SIN tarjeta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input
     * @param  array<int, \App\Models\Article>  $articulos  Los destinos, sin el origen.
     * @param  \App\Models\Article  $origen
     * @param  array  $resumen  resumen_de_los_destinos(), para la respuesta.
     * @param  bool  $solo_sin_foto  F4: viaja en `datos` para que ejecutar() saltee a los que ya tengan foto.
     * @return array
     */
    protected static function proponer_con_la_foto_de_otro_articulo(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input, array $articulos, Article $origen, array $resumen, $solo_sin_foto = false)
    {
        $nombre_del_origen = self::nombre_de_articulo($origen);

        $portada = self::portada_del_articulo((int) $origen->id);

        if (is_null($portada)) {

            return self::con_el_resumen(RespuestaDeCargaIa::error(
                CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'el artículo "' . $nombre_del_origen . '" no tiene ninguna foto cargada, así que no hay de dónde copiarla. '
                . 'Si la persona tiene la foto, decile que te la mande.'
            ), $resumen);
        }

        $archivo = self::archivo_de_la_imagen($portada);

        if ($archivo['motivo'] === 'externa') {

            return self::con_el_resumen(RespuestaDeCargaIa::error(
                CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'la foto del artículo "' . $nombre_del_origen . '" no está guardada en este sistema (es un link de otro sitio), '
                . 'así que no la puedo copiar a otros artículos. Si la persona tiene la foto, decile que te la mande.'
            ), $resumen);
        }

        if (is_null($archivo['ruta'])) {

            return self::con_el_resumen(RespuestaDeCargaIa::error(
                CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'la foto del artículo "' . $nombre_del_origen . '" figura cargada, pero no encuentro el archivo en este sistema, '
                . 'así que no la puedo copiar. Si la persona tiene la foto, decile que te la mande.'
            ), $resumen);
        }

        $ids = [];
        $nombres = [];

        foreach ($articulos as $articulo) {

            $ids[] = (int) $articulo->id;
            $nombres[] = self::nombre_de_articulo($articulo);
        }

        $uno_solo = count($ids) === 1;

        /* Mismo formato que la foto del chat (article_id + articulo cuando es uno), sin imagen_id: no hay foto del chat. */
        $datos = [];

        if ($uno_solo) {

            $datos['article_id'] = $ids[0];
            $datos['articulo'] = $nombres[0];
        }

        $datos['origen'] = self::ORIGEN_ARTICULO;
        $datos['image_id'] = (int) $portada->id;
        $datos['de_article_id'] = (int) $origen->id;
        $datos['de_articulo'] = $nombre_del_origen;
        $datos['article_ids'] = $ids;
        $datos['articulos'] = $nombres;

        // F4: con solo_sin_foto, ejecutar() saltea a los que reciban una foto entre la tarjeta y el sí.
        if ($solo_sin_foto) {

            $datos['solo_sin_foto'] = true;
        }

        $renglones = $uno_solo
            ? [
                ['etiqueta' => 'Artículo', 'valor' => $nombres[0]],
                ['etiqueta' => 'Qué se hace', 'valor' => 'La foto se suma a las imágenes del artículo'],
            ]
            : [
                ['etiqueta' => 'Artículos (' . count($ids) . ')', 'valor' => implode(', ', $nombres)],
                ['etiqueta' => 'Qué se hace', 'valor' => 'La misma foto se suma a las imágenes de cada uno'],
            ];

        // De qué artículo sale la foto: la persona tiene que poder decir «esa no» antes de confirmar.
        $renglones[] = ['etiqueta' => 'Foto', 'valor' => 'La de ' . $nombre_del_origen . ' (su foto principal)'];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_FOTO_ARTICULO,
            self::clave($ids),
            $datos,
            [
                'titulo'     => $uno_solo ? 'Foto del artículo' : 'Foto de ' . count($ids) . ' artículos',
                'renglones'  => $renglones,
                'aviso'      => 'La foto también se publica en la tienda online del negocio.',
                // La miniatura es la foto del origen, por su URL pública (no hay mensaje del chat que mostrar).
                'imagen_url' => ApiUrlHelper::url_publica_de_imagen($portada->hosting_url),
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $texto_del_resumen = ($uno_solo
            ? 'Foto del artículo ' . $nombres[0]
            : 'Foto de ' . count($ids) . ' artículos: ' . implode(', ', $nombres))
            . ' (la de ' . $nombre_del_origen . ')';

        return AccionesIaHelper::respuesta_de_propuesta($creada, $texto_del_resumen, $resumen);
    }

    /**
     * true si el modelo pidió la foto de OTRO artículo (`de_articulo_id` o `de_articulo`). DeepSeek rellena
     * los opcionales con 0 o "": eso cuenta como que no vino.
     *
     * @param  array  $input
     * @return bool
     */
    protected static function pide_la_foto_de_otro_articulo(array $input)
    {
        $id = EntradaDeCargaIa::valor($input, 'de_articulo_id');

        if (is_numeric($id) && (int) $id > 0) {

            return true;
        }

        return EntradaDeCargaIa::texto($input, 'de_articulo') !== '';
    }

    /**
     * El artículo de donde sale la foto: por `de_articulo_id` (del negocio) o por `de_articulo` (nombre o
     * código, con el mismo resolver que los destinos: una ambigüedad se pregunta, nunca se toma el primero).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $input
     * @return \App\Models\Article|array  El artículo o la respuesta negativa.
     */
    protected static function resolver_origen(ContextoDeCargaIa $contexto, array $input)
    {
        $id = EntradaDeCargaIa::valor($input, 'de_articulo_id');

        if (is_numeric($id) && (int) $id > 0) {

            $articulo = Article::where('user_id', $contexto->owner_id)->where('id', (int) $id)->first(self::COLUMNAS);

            if (is_null($articulo)) {

                return RespuestaDeCargaIa::error(
                    CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'el de_articulo_id ' . (int) $id . ' no es un artículo del negocio. Usá el id que te devolvió una herramienta, o pasá el nombre en de_articulo.'
                );
            }

            return $articulo;
        }

        $texto = EntradaDeCargaIa::texto($input, 'de_articulo');

        $articulo = self::resolver_articulo($contexto, ['articulo' => $texto]);

        if (!RespuestaDeCargaIa::es_negativa($articulo)) {

            return $articulo;
        }

        // Varios candidatos: se pregunta de CUÁL sale la foto (no «a cuál se la pongo»).
        if (isset($articulo['faltan']) && count($articulo['faltan'])) {

            $opciones = [];

            $candidatos = is_array($articulo['opciones']) && isset($articulo['opciones']['articulos']) ? $articulo['opciones']['articulos'] : [];

            foreach ($candidatos as $candidato) {

                $opciones[] = ['de_articulo_id' => (int) $candidato['articulo_id'], 'nombre' => (string) $candidato['nombre']];
            }

            return RespuestaDeCargaIa::faltan(['de cuál de estos artículos tomo la foto'], ['articulos' => $opciones]);
        }

        return RespuestaDeCargaIa::error(
            CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'no encontré el artículo "' . $texto . '" para tomar su foto. Buscalo con otra palabra o pasame su id en de_articulo_id.'
        );
    }

    /**
     * El error cuando, sacando el origen, no queda ningún destino (B3; desde la ronda de arreglos, F2, lo arma
     * resolver_articulos(), que es donde se saca el origen).
     *
     * @param  \App\Models\Article  $origen
     * @return array
     */
    protected static function error_el_unico_es_el_origen(Article $origen)
    {
        return RespuestaDeCargaIa::error(
            CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'el único artículo de la lista es el mismo de donde sale la foto ("'
            . self::nombre_de_articulo($origen) . '"). Decime a qué otros artículos se la pongo.'
        );
    }

    /**
     * El error cuando, con `solo_sin_foto`, todos los destinos ya tienen alguna foto (B3; desde la ronda de
     * arreglos, F2, lo arma resolver_articulos()). Lleva el resumen de ESOS destinos (todos con
     * `ya_tiene_foto`), para que el modelo pueda decir cuáles son.
     *
     * @param  array  $resumen  resumen_de_los_destinos() de los destinos que ya tienen foto.
     * @return array
     */
    protected static function error_todos_tienen_foto(array $resumen)
    {
        return self::con_el_resumen(RespuestaDeCargaIa::error(
            CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'todos esos artículos ya tienen al menos una foto, así que con solo_sin_foto no queda ninguno. '
            . 'Si la persona quiere sumarles la foto igual, volvé a llamar sin solo_sin_foto.'
        ), $resumen);
    }

    /**
     * El error del tope de TOPE_ARTICULOS cuando los destinos vienen por id (misión agente-mejoras-uso-real).
     * Desde la ronda de arreglos (F2) se cuenta sin el origen y, con `solo_sin_foto`, sin los que ya tienen foto.
     *
     * @param  int  $cuantos
     * @param  bool  $solo_sin_foto
     * @return array
     */
    protected static function error_de_mas_ids($cuantos, $solo_sin_foto)
    {
        return RespuestaDeCargaIa::error(
            'En una tarjeta entra la misma foto para hasta ' . self::TOPE_ARTICULOS . ' artículos, y '
            . ($solo_sin_foto ? 'sin foto quedan ' : 'me pasaste ') . (int) $cuantos . '. '
            . 'Decime cuáles van primero y armo las tarjetas de a ' . self::TOPE_ARTICULOS . '.'
        );
    }

    /**
     * La portada de un artículo (B3): la última con `first` distinto de cero si la columna existe y alguna lo
     * tiene, si no la primera por id. Es el mismo criterio que ArticleHelper::primera_imagen_publica (que
     * recorre las imágenes y se queda con la última marcada). Null si no tiene ninguna.
     *
     * @param  int  $article_id
     * @return \App\Models\Image|null
     */
    public static function portada_del_articulo($article_id)
    {
        $imagenes = Image::where('imageable_type', 'article')
                            ->where('imageable_id', (int) $article_id)
                            ->orderBy('id')
                            ->get();

        if (!count($imagenes)) {

            return null;
        }

        $portada = $imagenes[0];

        foreach ($imagenes as $imagen) {

            // getAttribute() devuelve null si la columna `first` no existe en esta instalación.
            if ((int) $imagen->getAttribute('first') !== 0) {

                $portada = $imagen;
            }
        }

        return $portada;
    }

    public static function huella_del_articulo(Article $articulo): string
    {
        return hash('sha256', serialize([
            (string) $articulo->name,
            (string) $articulo->descripcion,
            (string) $articulo->presentacion,
            (string) $articulo->unidades_por_bulto,
        ]));
    }

    /**
     * Dónde está, en el disco público de ESTE frente, el archivo de una fila de `images` (B3). El nombre sale
     * de `hosting_url`: se normaliza con ApiUrlHelper::url_publica_de_imagen() (que arregla los `/public`
     * de más o de menos de las filas viejas) y tiene que caer adentro de ApiUrlHelper::storage() de esta
     * instalación; si no, es una URL de otro host (R2, la demo, un inventario vinculado) y no hay archivo
     * propio que copiar. La ruta se confina con StoragePathHelper::inspeccionar() (realpath, sin «..»).
     *
     * @param  \App\Models\Image  $imagen
     * @return array{ruta: string|null, motivo: string}  motivo 'ok', 'externa' o 'ausente'.
     */
    public static function archivo_de_la_imagen(Image $imagen)
    {
        $publica = ApiUrlHelper::url_publica_de_imagen($imagen->hosting_url);

        $prefijo = ApiUrlHelper::storage() . '/';

        if (is_null($publica) || strpos($publica, $prefijo) !== 0) {

            return ['ruta' => null, 'motivo' => 'externa'];
        }

        // El nombre relativo al disco público, sin query ni fragmento.
        $nombre = rawurldecode((string) parse_url(substr($publica, strlen($prefijo)), PHP_URL_PATH));

        $inspeccion = StoragePathHelper::inspeccionar(storage_path('app/public'), $nombre);

        if (is_null($inspeccion['path'])) {

            return ['ruta' => null, 'motivo' => 'ausente'];
        }

        return ['ruta' => $inspeccion['path'], 'motivo' => 'ok'];
    }

    /**
     * Lo que la respuesta cuenta de los destinos (B3): cada uno con `ya_tiene_foto` (si tiene alguna fila en
     * `images`), cuántos son (`cuantos`) y cuántos no tienen ninguna (`sin_foto`). Una sola consulta.
     *
     * @param  array<int, \App\Models\Article>  $articulos
     * @return array{articulos: array<int, array{article_id: int, nombre: string, ya_tiene_foto: bool}>, cuantos: int, sin_foto: int}
     */
    protected static function resumen_de_los_destinos(array $articulos)
    {
        $ids = [];

        foreach ($articulos as $articulo) {

            $ids[] = (int) $articulo->id;
        }

        $con_foto = count($ids)
            ? Image::where('imageable_type', 'article')->whereIn('imageable_id', $ids)->distinct()->pluck('imageable_id')->map(function ($id) {
                return (int) $id;
            })->all()
            : [];

        $lista = [];
        $sin_foto = 0;

        foreach ($articulos as $articulo) {

            $tiene = in_array((int) $articulo->id, $con_foto, true);

            if (!$tiene) {

                $sin_foto++;
            }

            $lista[] = [
                'article_id'    => (int) $articulo->id,
                'nombre'        => self::nombre_de_articulo($articulo),
                'ya_tiene_foto' => $tiene,
            ];
        }

        return [
            'articulos' => $lista,
            'cuantos'   => count($lista),
            'sin_foto'  => $sin_foto,
        ];
    }

    /**
     * Los destinos que todavía no tienen ninguna foto, según el resumen (solo_sin_foto, B3).
     *
     * @param  array<int, \App\Models\Article>  $articulos
     * @param  array  $resumen  resumen_de_los_destinos() de esos mismos artículos.
     * @return array<int, \App\Models\Article>
     */
    protected static function solo_los_sin_foto(array $articulos, array $resumen)
    {
        $con_foto = [];

        foreach ($resumen['articulos'] as $fila) {

            if ($fila['ya_tiene_foto']) {

                $con_foto[] = (int) $fila['article_id'];
            }
        }

        $quedan = [];

        foreach ($articulos as $articulo) {

            if (!in_array((int) $articulo->id, $con_foto, true)) {

                $quedan[] = $articulo;
            }
        }

        return $quedan;
    }

    /**
     * Una respuesta (propuesta o negativa) con el resumen de los destinos sumado arriba de todo (B3).
     *
     * @param  array  $respuesta
     * @param  array  $resumen
     * @return array
     */
    protected static function con_el_resumen(array $respuesta, array $resumen)
    {
        return array_merge($respuesta, $resumen);
    }

    /**
     * El texto del error CODIGO_FOTO_POR_LLEGAR (B3): para el modelo, con la frase para la persona y la lista
     * de a quiénes va (hasta TOPE_NOMBRES_EN_EL_TEXTO nombres; la lista entera va en `articulos`). 🔴 Sin «no
     * me llegó» ni «de nuevo»: la persona puede no haberla mandado nunca, o haber pedido la de otro artículo.
     *
     * @param  array<int, \App\Models\Article>  $articulos  Los destinos (ya filtrados por solo_sin_foto).
     * @return string
     */
    protected static function texto_de_la_foto_por_llegar(array $articulos)
    {
        $nombres = [];

        foreach (array_slice($articulos, 0, self::TOPE_NOMBRES_EN_EL_TEXTO) as $articulo) {

            $nombres[] = self::nombre_de_articulo($articulo);
        }

        $lista = implode(', ', $nombres) . (count($articulos) > count($nombres) ? ' y ' . (count($articulos) - count($nombres)) . ' más' : '');

        /*
         * Empieza con «No tengo ninguna foto» como el texto de antes (lo lee el test de WhatsApp de la foto de un
         * mensaje del asistente, AsistenteWhatsapp/10): es el estado —no hay foto sin usar—, no un «no me llegó».
         */
        return 'No tengo ninguna foto sin usar en esta conversación (de las últimas ' . FotosDeLaConversacionIaHelper::HORAS . ' horas), así que no '
            . 'se armó ninguna tarjeta. Decile a la persona: "Mandame la foto y se la pongo a '
            . (count($articulos) === 1 ? 'este: ' : 'estos ' . count($articulos) . ': ') . $lista . '." '
            . 'Si pidió la foto que YA TIENE otro artículo ("usá la del clavo de 1 1/2"), no le pidas nada: volvé a llamar con de_articulo_id '
            . '(o de_articulo) de ese artículo.';
    }

    /**
     * Identidad de la carga para el reemplazo: una foto nueva para el mismo artículo (o el mismo
     * grupo de artículos) reemplaza la tarjeta, y la de otro artículo no la pisa.
     *
     * Un solo artículo conserva la clave de siempre (`foto_articulo:{id}`), así una corrección sigue
     * reemplazando a una tarjeta vieja. Varios: `foto_articulo:` + sha1 de los ids ORDENADOS, para que
     * el mismo grupo dé la misma clave sin importar en qué orden lo mandó el modelo (y para que entre
     * en los 100 caracteres de la columna).
     *
     * @param  int|array<int, int>  $article_ids
     * @return string
     */
    public static function clave($article_ids)
    {
        $ids = is_array($article_ids) ? array_values(array_map('intval', $article_ids)) : [(int) $article_ids];

        if (count($ids) === 1) {

            return 'foto_articulo:' . $ids[0];
        }

        sort($ids);

        return 'foto_articulo:' . sha1(implode(',', $ids));
    }

    /**
     * Guarda la foto como webp, la cuelga de cada artículo y sella la foto. Corre adentro de la
     * transacción de EjecutorAccionesIaHelper y con la persona ya autenticada.
     *
     * 🔴 TODO SE RE-VERIFICA ACÁ. Entre la propuesta y la confirmación pueden pasar horas: un artículo
     * pudo borrarse y la foto pudo usarla otra tarjeta. La foto se relee con `lockForUpdate` (mismo
     * motivo que la compra con factura: dos tarjetas no pueden llevarse la misma foto a una factura);
     * si ya se usó, sólo sirve si fue para un artículo de esta conversación (ver foto_bloqueada()).
     *
     * 🔴 LOS CUATRO EFECTOS DE ABAJO SON LOS DE LA PANTALLA (ImageController.php:171-189) Y VAN
     * COMPLETOS, POR CADA ARTÍCULO. Se copian y no se llama al controller porque `setImage()` es un
     * método de request (lee `$request->image_url`, baja la imagen por HTTP y responde un JSON), y acá
     * la imagen ya está en el disco privado del asistente. Lo que no se puede es quedarse con la fila
     * de `images` y saltear el resto.
     *
     * Misión agente-mejoras-uso-real (29/10/2026): con N artículos, el que ya no existe se saltea y
     * queda en `no_hechos` con su nombre, sin voltear a los demás; si no queda NINGUNO, sí corta (no
     * hay nada que hacer y la persona tiene que saberlo). La foto se sella al final, una vez.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta, hechos: [{article_id, articulo}], no_hechos: [{article_id, articulo, motivo}]}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('fotos de artículos'));
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        if (($datos['origen'] ?? null) === self::ORIGEN_GENERADA) {
            return self::ejecutar_generada($contexto, $datos);
        }

        $pedidos = self::articulos_de_la_tarjeta($datos);

        /*
         * El modelo ENTERO y no las columnas de resolver_articulo(): acá se lo guarda y se lo pasa
         * a los tres servicios de sincronización, que leen campos suyos (mercado_libre,
         * meli_category_id, stock, tiendanube_product_id, disponible_tienda_nube). Es lo mismo que
         * hace la pantalla, que trabaja sobre `$model_name::find($request->model_id)`.
         */
        $existentes = Article::where('user_id', $contexto->owner_id)
                                ->whereIn('id', array_keys($pedidos))
                                ->get()
                                ->keyBy('id');

        if (!count($existentes)) {

            throw new AccionIaException(
                422,
                count($pedidos) > 1
                    ? 'Ninguno de esos artículos existe ya entre los tuyos. Pedímelo de nuevo.'
                    : 'Ese artículo ya no existe entre los tuyos. Pedímelo de nuevo.'
            );
        }

        /*
         * F4 de la ronda de arreglos (31/10/2026): con `solo_sin_foto`, los destinos que recibieron una foto
         * entre la tarjeta y el sí (la pantalla, otra tarjeta) se saltean: la persona pidió la foto para los que
         * NO tenían, y sumarles otra sería justo lo contrario. Se informan en `no_hechos` con
         * MOTIVO_YA_TENIA_FOTO. Si ya la tienen todos, no hay nada que hacer: corta antes de leer la foto.
         */
        $ya_con_foto = [];

        if (!empty($datos['solo_sin_foto'])) {

            $ya_con_foto = Image::where('imageable_type', 'article')
                                ->whereIn('imageable_id', $existentes->keys()->all())
                                ->distinct()
                                ->pluck('imageable_id')
                                ->map(function ($id) {
                                    return (int) $id;
                                })
                                ->all();

            if (count($ya_con_foto) >= count($existentes)) {

                throw new AccionIaException(
                    422,
                    count($existentes) > 1
                        ? 'Esos artículos ya tienen foto (se la pusieron después de armar la tarjeta), así que no les puse otra.'
                        : 'Ese artículo ya tiene foto (se la pusieron después de armar la tarjeta), así que no le puse otra.'
                );
            }
        }

        /*
         * B3 (31/10/2026): con la foto de OTRO artículo el binario sale del disco público de este frente (se
         * revalida: entre la tarjeta y el sí la foto del origen pudo borrarse) y no hay foto del chat que
         * bloquear ni sellar. Con la del chat, todo como siempre.
         */
        $imagen = null;

        if (isset($datos['origen']) && $datos['origen'] === self::ORIGEN_ARTICULO) {

            $binario = self::binario_de_otro_articulo($contexto, $datos);

        } else {

            $imagen = self::foto_bloqueada($contexto, isset($datos['imagen_id']) ? (int) $datos['imagen_id'] : 0);

            if (is_null($imagen)) {

                throw new AccionIaException(422, 'Esa foto ya se usó o no está disponible. Mandámela de nuevo.');
            }

            $binario = AsistenteImagenHelper::binario($imagen);

            if (is_null($binario)) {

                throw new AccionIaException(422, 'No pude leer esa foto. Mandámela de nuevo.');
            }
        }

        /* El webp se codifica UNA vez; después se escribe un archivo por artículo (ver el docblock de la clase). */
        $webp = self::codificar_webp($binario);

        if (is_null($webp)) {

            throw new AccionIaException(422, 'No pude guardar la foto. Probá de nuevo en un momento.');
        }

        $hechos = [];
        $no_hechos = [];

        /*
         * Los archivos que esta ejecución ya escribió en storage/app/public. 🔴 Si algo falla A MITAD
         * (revisión independiente, arreglo 10, 30/10/2026), la transacción del ejecutor se lleva las
         * filas de `images`, pero los webp del disco no: quedaban huérfanos ocupando lugar, uno por cada
         * artículo que alcanzó a pasar. El catch los borra antes de volver a lanzar.
         */
        $escritos = [];

        try {

            foreach ($pedidos as $article_id => $nombre_pedido) {

                if (!isset($existentes[$article_id])) {

                    $no_hechos[] = ['article_id' => (int) $article_id, 'articulo' => $nombre_pedido, 'motivo' => 'ya no existe entre tus artículos'];

                    continue;
                }

                $articulo = $existentes[$article_id];

                // F4: con solo_sin_foto, el que ya recibió una foto se saltea (ver arriba).
                if (in_array((int) $article_id, $ya_con_foto, true)) {

                    $no_hechos[] = ['article_id' => (int) $articulo->id, 'articulo' => self::nombre_de_articulo($articulo), 'motivo' => self::MOTIVO_YA_TENIA_FOTO];

                    continue;
                }

                $name = self::escribir_webp($webp, (int) $articulo->id);

                if (is_null($name)) {

                    $no_hechos[] = ['article_id' => (int) $articulo->id, 'articulo' => self::nombre_de_articulo($articulo), 'motivo' => 'no pude guardar el archivo de la foto'];

                    continue;
                }

                $escritos[] = $name;

                self::colgar_y_sincronizar($contexto, $articulo, $name);

                $hechos[] = ['article_id' => (int) $articulo->id, 'articulo' => self::nombre_de_articulo($articulo)];
            }

            if (!count($hechos)) {

                throw new AccionIaException(422, 'No pude guardar la foto en ninguno de los artículos. Probá de nuevo en un momento.');
            }

            // La foto del chat se sella; la de otro artículo no es del chat y no hay nada que sellar.
            if (!is_null($imagen)) {

                AsistenteImagenHelper::marcar_gestionadas([(int) $imagen->id]);
            }

        } catch (\Throwable $e) {

            self::borrar_archivos($escritos);

            throw $e;
        }

        return [
            'texto'     => self::texto_del_resultado($hechos, $no_hechos),
            /*
             * El listado de artículos, que es de donde se cargan y se sacan las fotos. Misma ruta
             * que usa la búsqueda de imágenes por filtro (PropuestaImagenesArticulosIaHelper). En
             * WhatsApp esta ruta no se usa: no hay navegación.
             */
            'ruta'      => [
                'name'   => 'article',
                'params' => new \stdClass(),
                'texto'  => 'Ver el listado',
            ],
            'hechos'    => $hechos,
            'no_hechos' => $no_hechos,
        ];
    }

    protected static function ejecutar_generada(ContextoDeCargaIa $contexto, array $datos)
    {
        $articulo = Article::where('user_id', $contexto->owner_id)->lockForUpdate()->find($datos['article_id']);
        $pedido = self::pedido_generado($contexto, $datos['pedido_imagen_uuid'])->lockForUpdate()->first();

        if (!$articulo || !$pedido || (int) $pedido->article_id !== (int) $articulo->id
            || $pedido->estado !== IaImagenPedido::ESTADO_LISTA || !$pedido->path) {
            throw new AccionIaException(422, 'La imagen generada ya no está disponible para ese artículo.');
        }

        $hechos = [['article_id' => (int) $articulo->id, 'articulo' => self::nombre_de_articulo($articulo)]];

        if (!$pedido->publicada_image_id) {
            if (!hash_equals((string) $pedido->referencia_articulo_sha256, self::huella_del_articulo($articulo))) {
                throw new AccionIaException(422, 'El artículo cambió. Pedime una imagen nueva con los datos actuales.');
            }

            $portada = self::portada_del_articulo($articulo->id);
            $ruta = $portada ? self::archivo_de_la_imagen($portada)['ruta'] : null;

            if (!$ruta || (int) $portada->id !== (int) $pedido->referencia_imagen_id
                || !hash_equals((string) $pedido->referencia_sha256, (string) hash_file('sha256', $ruta))) {
                throw new AccionIaException(422, 'La foto original cambió. Pedime una imagen nueva con la foto actual.');
            }

            $disco = Storage::disk('local');
            $webp = $disco->exists($pedido->path) ? self::codificar_webp($disco->get($pedido->path)) : null;
            $nombre = $webp === null ? null : self::escribir_webp($webp, $articulo->id);

            if ($nombre === null) {
                throw new AccionIaException(422, 'No pude guardar la foto generada.');
            }

            try {
                $imagen = self::colgar_y_sincronizar($contexto, $articulo, $nombre, !empty($datos['como_principal']));
                $pedido->publicada_image_id = $imagen->id;
                $pedido->save();
            } catch (\Throwable $e) {
                self::borrar_archivos([$nombre]);
                throw $e;
            }
        }

        return ['texto' => self::texto_del_resultado($hechos, []), 'hechos' => $hechos, 'no_hechos' => []];
    }

    /**
     * El binario de la foto de OTRO artículo para ejecutar() (B3, 31/10/2026), revalidado: el artículo de origen
     * tiene que seguir siendo del negocio, la fila de `images` tiene que seguir colgando de él y el archivo
     * tiene que estar en el disco público de este frente. Después ejecutar() sigue el camino de siempre
     * (codificar_webp → un archivo por artículo con escribir_webp → colgar_y_sincronizar): se COPIA el archivo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $datos  Los `datos` de la tarjeta (image_id, de_article_id, de_articulo).
     * @return string
     *
     * @throws AccionIaException  422 si la foto del origen ya no está.
     */
    protected static function binario_de_otro_articulo(ContextoDeCargaIa $contexto, array $datos)
    {
        $de_article_id = isset($datos['de_article_id']) ? (int) $datos['de_article_id'] : 0;
        $nombre = isset($datos['de_articulo']) ? (string) $datos['de_articulo'] : 'el artículo de origen';

        $es_del_negocio = $de_article_id > 0 && Article::where('user_id', $contexto->owner_id)->where('id', $de_article_id)->exists();

        $portada = $es_del_negocio
            ? Image::where('id', isset($datos['image_id']) ? (int) $datos['image_id'] : 0)
                    ->where('imageable_type', 'article')
                    ->where('imageable_id', $de_article_id)
                    ->first()
            : null;

        if (is_null($portada)) {

            throw new AccionIaException(422, 'La foto de ' . $nombre . ' ya no está (la sacaron o se borró el artículo después de armar la tarjeta). Pedime la foto otra vez.');
        }

        $archivo = self::archivo_de_la_imagen($portada);

        $binario = is_null($archivo['ruta']) ? false : @file_get_contents($archivo['ruta']);

        if ($binario === false || $binario === '') {

            throw new AccionIaException(422, 'No encuentro el archivo de la foto de ' . $nombre . ' en este sistema: no la pude copiar.');
        }

        return (string) $binario;
    }

    /**
     * Cuelga una foto del asistente de un artículo con los CUATRO EFECTOS DE LA PANTALLA y la sella.
     * Separado de ejecutar() en la misión asistente-fotos-barras-y-compras (24/9/2026) para que el
     * alta de un artículo con su foto (AltaDeArticuloConFotoIaHelper) asigne la foto por el MISMO
     * camino y no por una copia que un día se olvide uno de los cuatro efectos.
     *
     * La foto tiene que venir ya bloqueada por quien llama (lockForUpdate adentro de su
     * transacción): acá no se relee.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Article  $articulo  El modelo ENTERO (ver el comentario de ejecutar()).
     * @param  \App\Models\AiMessageImagen  $imagen
     * @return \App\Models\Image
     *
     * @throws AccionIaException  422 si la foto no se puede leer o guardar.
     */
    public static function asignar_imagen(ContextoDeCargaIa $contexto, Article $articulo, AiMessageImagen $imagen)
    {
        $binario = AsistenteImagenHelper::binario($imagen);

        if (is_null($binario)) {

            throw new AccionIaException(422, 'No pude leer esa foto. Mandámela de nuevo.');
        }

        $name = self::guardar_webp($binario);

        if (is_null($name)) {

            throw new AccionIaException(422, 'No pude guardar la foto. Probá de nuevo en un momento.');
        }

        $image = self::colgar_y_sincronizar($contexto, $articulo, $name);

        AsistenteImagenHelper::marcar_gestionadas([(int) $imagen->id]);

        return $image;
    }

    /**
     * La fila de `images` del artículo con un archivo ya guardado en storage/app/public, y los CUATRO
     * EFECTOS de la pantalla (ImageController.php:171-189). Es lo común entre asignar_imagen() (una
     * foto, un artículo) y ejecutar() (una foto, N artículos, un archivo por cada uno), separado en la
     * misión agente-mejoras-uso-real para que los dos caminos no se separen nunca.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\Article  $articulo  El modelo ENTERO.
     * @param  string  $name  Nombre del archivo en storage/app/public.
     * @return \App\Models\Image
     */
    protected static function colgar_y_sincronizar(ContextoDeCargaIa $contexto, Article $articulo, $name, $principal = false)
    {
        /*
         * 🔴 `imageable_type` es el string 'article' EN MINÚSCULA y no la clase con namespace: el
         * morph map de AppServiceProvider.php:55-56 mapea 'article' => App\Models\Article, y todas
         * las filas del parque están escritas así. Guardar la FQCN dejaría una imagen que ninguna
         * relación `images()` encuentra.
         */
        $image = Image::create([
            'hosting_url'    => ApiUrlHelper::storage($name),
            'imageable_id'   => (int) $articulo->id,
            'imageable_type' => 'article',
            'temporal_id'    => null,
        ]);

        if ($principal) {
            $articulo->images()->where('first', '<>', 0)->update(['first' => 0]);
            $image->first = 1;
            $image->save();
        }

        /*
         * 1) Vinculación de inventarios: si este comercio le sirve el catálogo a otro, la misma
         * imagen se replica en el artículo espejo del cliente. El owner va EXPLÍCITO y no por
         * sesión: el constructor sin argumentos resuelve por Auth, y al confirmar desde WhatsApp
         * esto corre adentro del job (ver ConfirmacionPorTextoIaHelper::autenticado_como(), que sí
         * autentica, pero el dato que hace falta acá es el dueño de la cuenta, que ya está en el
         * contexto).
         */
        $helper = new InventoryLinkageHelper(null, $contexto->owner_id);
        $helper->check_created_image($articulo, $image);

        /*
         * 2) La marca de sincronización con la tienda, con TIMESTAMPS: el save() tiene que bumpear
         * `updated_at` porque el sync incremental del front (sync_articles.js) se guía por él para
         * volver a bajar el artículo con su imagen nueva. Desactivar los timestamps "para no
         * ensuciar la fecha" es exactamente lo que deja al listado sin la foto.
         */
        $articulo->needs_sync_with_tn = true;
        $articulo->save();

        /* 3) Mercado Libre y 4) Tienda Nube: las dos encolan y no hacen ninguna llamada externa acá. */
        ProductService::add_article_to_sync($articulo);
        TiendaNubeSyncArticleService::add_article_to_sync($articulo);

        return $image;
    }

    /**
     * Los artículos de la tarjeta, `[article_id => nombre]`, en el orden de la tarjeta: `article_ids`
     * (+ `articulos`) de las tarjetas nuevas o `article_id` (+ `articulo`) de las de antes de la misión
     * agente-mejoras-uso-real. Una tarjeta sin ninguno de los dos da la lista vacía, y ejecutar() la
     * corta como "ya no existe".
     *
     * @param  array  $datos
     * @return array<int, string>
     */
    protected static function articulos_de_la_tarjeta(array $datos)
    {
        $pedidos = [];

        if (isset($datos['article_ids']) && is_array($datos['article_ids']) && count($datos['article_ids'])) {

            $nombres = isset($datos['articulos']) && is_array($datos['articulos']) ? array_values($datos['articulos']) : [];

            foreach (array_values($datos['article_ids']) as $i => $id) {

                if ((int) $id > 0) {

                    $pedidos[(int) $id] = isset($nombres[$i]) ? (string) $nombres[$i] : 'Artículo ' . (int) $id;
                }
            }

            return $pedidos;
        }

        if (isset($datos['article_id']) && (int) $datos['article_id'] > 0) {

            $pedidos[(int) $datos['article_id']] = isset($datos['articulo']) ? (string) $datos['articulo'] : 'Artículo ' . (int) $datos['article_id'];
        }

        return $pedidos;
    }

    /**
     * El texto del resultado: con uno solo, el de siempre («Foto agregada al artículo X»); con varios,
     * a cuántos se les puso y, por nombre, a cuáles NO y por qué. El agente lo cuenta tal cual, y por
     * eso el «a cuáles no» va acá y no queda librado a que el modelo se acuerde (CONV 15: a los clavos
     * que no recibieron la foto nunca se les avisó).
     *
     * @param  array  $hechos
     * @param  array  $no_hechos
     * @return string
     */
    protected static function texto_del_resultado(array $hechos, array $no_hechos)
    {
        if (count($hechos) === 1 && !count($no_hechos)) {

            return 'Foto agregada al artículo ' . $hechos[0]['articulo'];
        }

        $nombres = [];

        foreach ($hechos as $hecho) {

            $nombres[] = $hecho['articulo'];
        }

        $texto = 'Foto agregada a ' . (count($hechos) === 1 ? '1 artículo' : count($hechos) . ' artículos') . ': ' . implode(', ', $nombres) . '.';

        /*
         * F4 de la ronda de arreglos (31/10/2026): los que se saltearon porque ya tenían foto (solo_sin_foto) no
         * son una falla: van en su propia oración, para que el agente no le diga a la persona que «no se pudo».
         */
        $faltan = [];
        $ya_tenian = [];

        foreach ($no_hechos as $no_hecho) {

            if ($no_hecho['motivo'] === self::MOTIVO_YA_TENIA_FOTO) {

                $ya_tenian[] = $no_hecho['articulo'];

                continue;
            }

            $faltan[] = $no_hecho['articulo'] . ' (' . $no_hecho['motivo'] . ')';
        }

        if (count($faltan)) {

            $texto .= ' NO se la pude poner a ' . (count($faltan) === 1 ? '1' : count($faltan)) . ': ' . implode(', ', $faltan) . '.';
        }

        if (count($ya_tenian)) {

            $texto .= ' No se la puse a ' . (count($ya_tenian) === 1 ? '1 porque ya tenía foto' : count($ya_tenian) . ' porque ya tenían foto') . ': ' . implode(', ', $ya_tenian) . '.';
        }

        return $texto;
    }

    /**
     * Los artículos a los que va la foto, o la respuesta de negocio (falta, ambigüedad, tope).
     *
     * Tres caminos, en este orden (misión agente-mejoras-uso-real, 29/10/2026):
     *   1. `articulo_ids` (más `articulo_id` si vino): esos y solo esos, del dueño. Un id que no es
     *      suyo corta SIN tarjeta con el prefijo NO_SE_ARMO (es para el modelo: casi siempre un id mal
     *      copiado), y más de TOPE_ARTICULOS también.
     *   2. `todos_los_que_coinciden` con `articulo`: TODOS los que encuentra la búsqueda por palabras
     *      (la misma de resolver_articulo()) en vez de preguntar cuál. Más de TOPE_ARTICULOS se pide
     *      acotar.
     *   3. Lo de siempre: UN artículo (resolver_articulo()).
     *
     * 🔴 Ronda de arreglos (F2, 31/10/2026): el artículo de ORIGEN (la foto de otro artículo) y `solo_sin_foto` se
     * aplican acá, ANTES del tope. Con `todos_los_que_coinciden` van en la consulta (`id <>` y `whereNotExists`
     * de `images`); con ids, el origen se saca de la lista antes de contar y los que tienen foto se filtran antes
     * del tope. Los dos casos en que no queda nadie tienen su error (error_el_unico_es_el_origen(),
     * error_todos_tienen_foto()).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $input
     * @param  \App\Models\Article|null  $origen  El artículo de donde sale la foto (B3), que no es destino.
     * @param  bool  $solo_sin_foto  Solo los destinos que todavía no tienen ninguna foto.
     * @return array<int, \App\Models\Article>|array  Lista de artículos, o la respuesta negativa.
     */
    protected static function resolver_articulos(ContextoDeCargaIa $contexto, array $input, $origen = null, $solo_sin_foto = false)
    {
        $ids = self::ids_del_input(EntradaDeCargaIa::valor($input, 'articulo_ids'));

        if (count($ids)) {

            $articulo_id = EntradaDeCargaIa::valor($input, 'articulo_id');

            if (is_numeric($articulo_id) && (int) $articulo_id > 0 && !in_array((int) $articulo_id, $ids, true)) {

                array_unshift($ids, (int) $articulo_id);
            }

            // F2: el origen no es destino ni cuenta para el tope.
            if (!is_null($origen)) {

                $ids = array_values(array_filter($ids, function ($id) use ($origen) {
                    return (int) $id !== (int) $origen->id;
                }));

                if (!count($ids)) {

                    return self::error_el_unico_es_el_origen($origen);
                }
            }

            // Con solo_sin_foto el tope se mira DESPUÉS de filtrar (abajo): los que tienen foto no cuentan.
            if (!$solo_sin_foto && count($ids) > self::TOPE_ARTICULOS) {

                return self::error_de_mas_ids(count($ids), false);
            }

            $encontrados = Article::query()
                                    ->where('user_id', $contexto->owner_id)
                                    ->whereIn('id', $ids)
                                    ->get(self::COLUMNAS)
                                    ->keyBy('id');

            $articulos = [];
            $ajenos = [];

            foreach ($ids as $id) {

                if (isset($encontrados[$id])) {

                    $articulos[] = $encontrados[$id];

                } else {

                    $ajenos[] = $id;
                }
            }

            if (count($ajenos)) {

                return RespuestaDeCargaIa::error(
                    CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'estos articulo_ids no son artículos del negocio: ' . implode(', ', $ajenos) . '. '
                    . 'Usá solo los ids que te devolvió una herramienta, o buscalos de nuevo por nombre (articulo con todos_los_que_coinciden).'
                );
            }

            if ($solo_sin_foto) {

                $articulos = self::filtrar_los_sin_foto($articulos);

                if (RespuestaDeCargaIa::es_negativa($articulos)) {

                    return $articulos;
                }

                if (count($articulos) > self::TOPE_ARTICULOS) {

                    return self::error_de_mas_ids(count($articulos), true);
                }
            }

            return $articulos;
        }

        $texto = EntradaDeCargaIa::texto($input, 'articulo');

        if ($texto !== '' && self::es_si(EntradaDeCargaIa::valor($input, 'todos_los_que_coinciden'))) {

            return self::todos_los_que_coinciden($contexto, $texto, $origen, $solo_sin_foto);
        }

        $articulo = self::resolver_articulo($contexto, $input);

        if (RespuestaDeCargaIa::es_negativa($articulo)) {

            return $articulo;
        }

        // F2: un solo destino que es el mismo origen, o que ya tiene foto con solo_sin_foto, no deja a nadie.
        if (!is_null($origen) && (int) $articulo->id === (int) $origen->id) {

            return self::error_el_unico_es_el_origen($origen);
        }

        if ($solo_sin_foto) {

            return self::filtrar_los_sin_foto([$articulo]);
        }

        return [$articulo];
    }

    /**
     * Los destinos sin ninguna foto, o el error de que todos ya tienen (solo_sin_foto; ronda de arreglos, F2).
     * Para las listas que ya se resolvieron (por ids o un solo artículo): una consulta a `images`.
     *
     * @param  array<int, \App\Models\Article>  $articulos
     * @return array<int, \App\Models\Article>|array  Los que no tienen foto, o la respuesta negativa.
     */
    protected static function filtrar_los_sin_foto(array $articulos)
    {
        $resumen = self::resumen_de_los_destinos($articulos);

        $quedan = self::solo_los_sin_foto($articulos, $resumen);

        if (!count($quedan)) {

            return self::error_todos_tienen_foto($resumen);
        }

        return $quedan;
    }

    /**
     * Todos los artículos del dueño que encuentra la búsqueda por palabras para `$texto` (frase entera
     * en nombre o códigos, o cada palabra en el nombre: BusquedaPorPalabrasIaHelper::filtrar), hasta
     * TOPE_ARTICULOS, en orden de nombre. Para «a todos los clavos punta París». Si son más, se pide
     * acotar; si no hay ninguno, el mismo error que con un solo artículo.
     *
     * 🔴 Ronda de arreglos (F2, 31/10/2026): el origen (`id <>`) y `solo_sin_foto` (`whereNotExists` de una
     * fila en `images`) van EN LA CONSULTA, antes del límite: con 45 clavos de los que 8 no tienen foto, la
     * tarjeta lleva los 8 en vez de cortar con «Hay más de 30». Si con los filtros no queda ninguno, se mira por
     * qué (ninguno encaja, solo encaja el origen, o todos los demás ya tienen foto) para dar el error justo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $texto
     * @param  \App\Models\Article|null  $origen  El artículo de donde sale la foto (no es destino).
     * @param  bool  $solo_sin_foto
     * @return array<int, \App\Models\Article>|array
     */
    protected static function todos_los_que_coinciden(ContextoDeCargaIa $contexto, $texto, $origen = null, $solo_sin_foto = false)
    {
        $columnas = ['name', 'bar_code', 'provider_code'];

        $consulta = Article::query()
                            ->where('user_id', $contexto->owner_id)
                            ->where(function ($sub) use ($texto, $columnas) {
                                BusquedaPorPalabrasIaHelper::filtrar($sub, $texto, $columnas);
                            });

        // Sin el origen: no es destino ni cuenta para el tope.
        $sin_el_origen = clone $consulta;

        if (!is_null($origen)) {

            $sin_el_origen->where('articles.id', '<>', (int) $origen->id);
        }

        // Con solo_sin_foto, sin los que ya tienen alguna fila en `images` (el mismo criterio que resumen_de_los_destinos()).
        $filtrada = clone $sin_el_origen;

        if ($solo_sin_foto) {

            $filtrada->whereNotExists(function ($con_foto) {
                $con_foto->selectRaw('1')
                         ->from('images')
                         ->where('images.imageable_type', 'article')
                         ->whereColumn('images.imageable_id', 'articles.id');
            });
        }

        $candidatos = BusquedaPorPalabrasIaHelper::ordenar_por_frase($filtrada, $texto, $columnas)
                                                ->orderBy('name')
                                                ->limit(self::TOPE_ARTICULOS + 1)
                                                ->get(self::COLUMNAS);

        if (count($candidatos) === 0) {

            if (!(clone $consulta)->exists()) {

                return RespuestaDeCargaIa::error(
                    'No encontré ningún artículo que se llame "' . $texto . '" ni con ese código. Buscalo con otra palabra o decime el código.'
                );
            }

            if (!is_null($origen) && !(clone $sin_el_origen)->exists()) {

                return self::error_el_unico_es_el_origen($origen);
            }

            // Encajan otros, pero con solo_sin_foto no queda ninguno: todos ya tienen foto (el resumen, hasta el tope).
            $con_foto = BusquedaPorPalabrasIaHelper::ordenar_por_frase(clone $sin_el_origen, $texto, $columnas)
                                                   ->orderBy('name')
                                                   ->limit(self::TOPE_ARTICULOS)
                                                   ->get(self::COLUMNAS)
                                                   ->all();

            return self::error_todos_tienen_foto(self::resumen_de_los_destinos($con_foto));
        }

        if (count($candidatos) > self::TOPE_ARTICULOS) {

            return RespuestaDeCargaIa::error(
                'Hay más de ' . self::TOPE_ARTICULOS . ' artículos ' . ($solo_sin_foto ? 'sin foto ' : '') . 'que encajan con "' . $texto . '". Decime una palabra más (una medida, una marca) '
                . 'para acotar: en una tarjeta entran hasta ' . self::TOPE_ARTICULOS . '.'
            );
        }

        return $candidatos->all();
    }

    /**
     * Los `articulo_ids` del input como enteros positivos, sin repetidos y en el orden en que vinieron.
     * Cualquier cosa que no sea una lista (un número suelto, un texto) cuenta como que no vino.
     *
     * @param  mixed  $valor
     * @return array<int, int>
     */
    protected static function ids_del_input($valor)
    {
        if (!is_array($valor)) {

            return [];
        }

        $ids = [];

        foreach ($valor as $id) {

            if (is_numeric($id) && (int) $id > 0 && !in_array((int) $id, $ids, true)) {

                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * true solo con un sí explícito (true, 1, "true", "1", "si", "sí"): DeepSeek rellena los booleanos
     * opcionales con false o "", y eso es como si no hubiera venido.
     *
     * @param  mixed  $valor
     * @return bool
     */
    protected static function es_si($valor)
    {
        if (is_bool($valor)) {

            return $valor;
        }

        if (is_int($valor) || is_float($valor)) {

            return (int) $valor === 1;
        }

        return is_string($valor) && in_array(mb_strtolower(trim($valor)), ['true', '1', 'si', 'sí'], true);
    }

    /**
     * El artículo que nombró la persona, o la respuesta de negocio que pide desambiguar.
     *
     * Mismo criterio que PropuestaVentaIaHelper::resolver_articulo, y por el mismo motivo: esto
     * elige el destino de una foto que se PUBLICA, así que una ambigüedad corta y se pregunta, nunca
     * se toma "el primero por nombre". `articulo_id` directo → LIKE escapado sobre nombre, código de
     * barras y código de proveedor → desempate por coincidencia exacta normalizada (sin acentos ni
     * mayúsculas) → si siguen siendo varios, `faltan` con los candidatos por nombre.
     *
     * 🔴 SIN EMBEDDINGS, a propósito. ArticleEmbeddingService::search_similar_articles existe, pero
     * cuesta una llamada a OpenAI por búsqueda y solo rinde de verdad con pgvector: para "ponele
     * esta foto al X" el LIKE que ya usa la pantalla alcanza, y si no alcanza, se pregunta.
     *
     * 🔴 Y NO SE FILTRA POR `status`. Vender solo ofrece los activos porque un pausado no se puede
     * vender; una foto sí se le puede poner a un artículo pausado, y de hecho es lo normal (se
     * pausa justamente mientras se le completa la ficha). Los borrados quedan afuera solos, por el
     * SoftDeletes del modelo. Mismo criterio que AdjuntosIaHelper con las fotos que muestra.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $input
     * @return \App\Models\Article|array
     */
    protected static function resolver_articulo(ContextoDeCargaIa $contexto, array $input)
    {
        $base = Article::query()->where('user_id', $contexto->owner_id);

        $articulo_id = EntradaDeCargaIa::valor($input, 'articulo_id');

        if (!EntradaDeCargaIa::vacio($articulo_id) && is_numeric($articulo_id) && (int) $articulo_id > 0) {

            $articulo = (clone $base)->where('id', (int) $articulo_id)->first(self::COLUMNAS);

            if (is_null($articulo)) {

                return RespuestaDeCargaIa::error('Ese artículo no existe entre los tuyos. Buscalo por nombre antes de mandarme la foto.');
            }

            return $articulo;
        }

        $texto = EntradaDeCargaIa::texto($input, 'articulo');

        if ($texto === '') {

            return RespuestaDeCargaIa::faltan(['a qué artículo le pongo la foto']);
        }

        $normalizado = ConsultasSistemaIaHelper::normalize_text($texto);

        /* El `%` y el `_` que escriba la persona son literales: los escapa BusquedaPorPalabrasIaHelper. */
        $candidatos = (clone $base)
            ->where(function ($sub) use ($texto) {
                // Por palabras sueltas (misión asistente-altas-y-conversacion): ver BusquedaPorPalabrasIaHelper.
                BusquedaPorPalabrasIaHelper::filtrar($sub, $texto, ['name', 'bar_code', 'provider_code']);
            });

        $candidatos = BusquedaPorPalabrasIaHelper::ordenar_por_frase($candidatos, $texto, ['name', 'bar_code', 'provider_code'])
            ->orderBy('name')
            ->limit(self::TOPE_CANDIDATOS + 1)
            ->get(self::COLUMNAS);

        if (count($candidatos) === 0) {

            return RespuestaDeCargaIa::error(
                'No encontré ningún artículo que se llame "' . $texto . '" ni con ese código. Buscalo con otra palabra o decime el código. '
                /*
                 * Correcciones del 24/9/2026: si el artículo es NUEVO, la foto va en su alta, en UNA
                 * tarjeta. Sin esta línea el modelo proponía el alta y después la foto aparte (dos
                 * "¿lo registro?" para un solo pedido), o insistía con esta herramienta.
                 */
                . 'Si es un artículo NUEVO, no va acá: proponé el alta con proponer_alta de article y con_foto_de_la_conversacion, que lo crea con la foto en una sola tarjeta.'
            );
        }

        /*
         * 🔴 Lo que tiene la FRASE ENTERA manda, como antes de la búsqueda por palabras; si nada la
         * tiene, un artículo que encajó solo por palabras se toma sin preguntar únicamente si es
         * seguro (cada número del pedido es una medida entera de su nombre): "pintura blanca 4 litros"
         * no puede terminar en la de 1 litro. Si no, va como opción (chequeo independiente, 16/10/2026).
         */
        $con_la_frase = BusquedaPorPalabrasIaHelper::por_frase($candidatos, $texto, ['name', 'bar_code', 'provider_code']);

        if (count($con_la_frase)) {

            $candidatos = collect($con_la_frase);
        }

        if (count($candidatos) === 1
            && (count($con_la_frase) || BusquedaPorPalabrasIaHelper::es_seguro((string) $candidatos[0]->name, $texto))) {

            return $candidatos[0];
        }

        /* El nombre o el código escrito completo gana sobre los parciales. */
        foreach ($candidatos as $candidato) {

            if (ConsultasSistemaIaHelper::normalize_text((string) $candidato->name) === $normalizado
                || (trim((string) $candidato->bar_code) !== '' && ConsultasSistemaIaHelper::normalize_text((string) $candidato->bar_code) === $normalizado)
                || (trim((string) $candidato->provider_code) !== '' && ConsultasSistemaIaHelper::normalize_text((string) $candidato->provider_code) === $normalizado)) {

                return $candidato;
            }
        }

        $opciones = [];

        foreach ($candidatos->take(self::TOPE_CANDIDATOS) as $candidato) {

            $opciones[] = [
                'articulo_id' => (int) $candidato->id,
                'nombre'      => self::nombre_de_articulo($candidato),
            ];
        }

        /*
         * Misión agente-mejoras-uso-real: si la persona pidió la foto para TODOS ellos («que todos
         * tengan la misma»), no se pregunta cuál: se vuelve a llamar con todos_los_que_coinciden o con
         * los articulo_ids. La pista va en `opciones` y no en `faltan` (que el modelo le pregunta a la
         * persona tal cual).
         */
        return RespuestaDeCargaIa::faltan(['a cuál de estos artículos le pongo la foto'], [
            'articulos'   => $opciones,
            'como_seguir' => 'Si la persona pidió la misma foto para varios o para todos, no preguntes: volvé a llamar con articulo_ids (los de estas opciones que correspondan) o con todos_los_que_coinciden: true.',
        ]);
    }

    /**
     * La foto más nueva que el DUEÑO mandó en las últimas 24 horas y no se usó, o null. Se toma UNA
     * sola, la más nueva, que es la que acaba de mandar. La ventana y el filtro por rol viven en
     * FotosDeLaConversacionIaHelper (ver ahí por qué dejó de ser "los últimos seis mensajes").
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @return \App\Models\AiMessageImagen|null
     */
    protected static function ultima_foto_sin_gestionar(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        return FotosDeLaConversacionIaHelper::la_mas_nueva($contexto, $mensaje);
    }

    /**
     * La foto de la tarjeta CON LA FILA BLOQUEADA hasta el commit, si se puede usar: sin gestionar, o
     * ya usada pero en un artículo de esta conversación (misión agente-mejoras-uso-real: la misma foto
     * para varios artículos). El candado es lo que evita que dos tarjetas se lleven la misma foto a
     * destinos incompatibles (mismo patrón que PropuestaFotoSucursalIaHelper::foto_bloqueada y
     * PropuestaCompraConFacturaIaHelper): una foto que se fue a una factura nunca vuelve a un artículo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  int  $imagen_id
     * @return \App\Models\AiMessageImagen|null
     */
    protected static function foto_bloqueada(ContextoDeCargaIa $contexto, $imagen_id)
    {
        if ($imagen_id <= 0) {

            return null;
        }

        $imagen = AiMessageImagen::where('user_id', $contexto->owner_id)
                                    ->where('id', $imagen_id)
                                    ->lockForUpdate()
                                    ->first();

        if (is_null($imagen)) {

            return null;
        }

        /*
         * Una foto SIN USAR tiene que ser de esta conversación, como siempre (un id de otro hilo no se
         * publica en este catálogo). Una YA USADA puede venir de un hilo hermano del mismo canal
         * (revisión independiente, arreglo 11): lo decide es_reutilizable_para_articulo().
         */
        if (is_null($imagen->gestionada_at)) {

            $de_esta = AiMessage::where('id', (int) $imagen->ai_message_id)
                                ->where('ai_conversation_id', (int) $contexto->conversation->id)
                                ->exists();

            return $de_esta ? $imagen : null;
        }

        return FotosDeLaConversacionIaHelper::es_reutilizable_para_articulo($contexto, $imagen) ? $imagen : null;
    }

    /**
     * Guarda el binario como WEBP en storage/app/public y devuelve el nombre del archivo, o null si
     * no se pudo. Mismo nombre que ImageController::setImage() —`time().rand(1,100000)`— y misma
     * forma de guardar: Intervention infiere el formato de la extensión del archivo destino.
     *
     * @param  string  $binario
     * @return string|null
     */
    protected static function guardar_webp($binario)
    {
        $name = time() . rand(1, 100000) . '.webp';

        try {

            $manager = new ImageManager();
            $img = $manager->make($binario);
            $img->save(storage_path() . '/app/public/' . $name);

        } catch (\Throwable $e) {

            Log::warning('PropuestaFotoArticuloIaHelper: no se pudo guardar la foto del artículo como webp', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $name;
    }

    /**
     * El binario codificado como WEBP, una sola vez para todos los artículos de la tarjeta, o null si
     * Intervention no lo pudo leer.
     *
     * @param  string  $binario
     * @return string|null
     */
    protected static function codificar_webp($binario)
    {
        try {

            return (string) (new ImageManager())->make($binario)->encode('webp');

        } catch (\Throwable $e) {

            Log::warning('PropuestaFotoArticuloIaHelper: no se pudo codificar la foto como webp', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Escribe el webp ya codificado en storage/app/public con un nombre PROPIO del artículo y devuelve
     * ese nombre, o null si no se pudo. El nombre lleva el id del artículo: diez archivos escritos en
     * el mismo segundo no pueden chocar (con `time().rand()` solo, sí podían), y cada uno se puede
     * borrar sin tocar a los demás (ver el docblock de la clase).
     *
     * @param  string  $webp
     * @param  int  $article_id
     * @return string|null
     */
    protected static function escribir_webp($webp, $article_id)
    {
        $name = time() . rand(1, 100000) . '_' . (int) $article_id . '.webp';

        try {

            if (file_put_contents(storage_path() . '/app/public/' . $name, $webp) === false) {

                return null;
            }

        } catch (\Throwable $e) {

            Log::warning('PropuestaFotoArticuloIaHelper: no se pudo escribir la foto de un artículo', [
                'article_id' => (int) $article_id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }

        return $name;
    }

    /**
     * Borra de storage/app/public los webp que una ejecución alcanzó a escribir antes de fallar (ver el
     * 🔴 del catch de ejecutar()). Nunca lanza: está adentro de un catch que vuelve a lanzar el error
     * original, y ése es el que tiene que llegar.
     *
     * @param  array<int, string>  $nombres
     * @return void
     */
    protected static function borrar_archivos(array $nombres)
    {
        foreach ($nombres as $nombre) {

            try {

                $ruta = storage_path() . '/app/public/' . $nombre;

                if ($nombre !== '' && is_file($ruta)) {

                    @unlink($ruta);
                }

            } catch (\Throwable $e) {

                Log::warning('PropuestaFotoArticuloIaHelper: no se pudo borrar un webp de una ejecución que falló', [
                    'archivo' => $nombre,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Cómo se nombra un artículo en la tarjeta y en las opciones: su nombre, y si no tiene, el
     * código con el que se lo encuentra. Nunca queda vacío: "Foto del artículo " sin nada atrás no
     * le dice a nadie qué está por confirmar.
     *
     * @param  \App\Models\Article  $articulo
     * @return string
     */
    protected static function nombre_de_articulo(Article $articulo)
    {
        $nombre = trim((string) $articulo->name);

        if ($nombre !== '') {

            return $nombre;
        }

        $codigo = trim((string) $articulo->bar_code);

        if ($codigo === '') {

            $codigo = trim((string) $articulo->provider_code);
        }

        return $codigo === '' ? 'Artículo ' . $articulo->id : $codigo;
    }
}
