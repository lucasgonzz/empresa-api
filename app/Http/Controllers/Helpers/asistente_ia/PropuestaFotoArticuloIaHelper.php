<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Image;
use App\Services\MercadoLibre\ProductService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Support\Facades\Log;
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

    /**
     * Herramienta proponer_foto_articulo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  articulo (texto), articulo_id, imagen_id opcional, reemplaza_a, y desde la
     *                        misión agente-mejoras-uso-real articulo_ids (int[], hasta TOPE_ARTICULOS) y
     *                        todos_los_que_coinciden (bool)
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('fotos de artículos'));
        }

        $articulos = self::resolver_articulos($contexto, $input);

        if (RespuestaDeCargaIa::es_negativa($articulos)) {

            return $articulos;
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
                return RespuestaDeCargaIa::error('Esa imagen_id no está disponible en esta conversación o ya se usó. Usá el id del hallazgo elegido; no se reemplazó por otra foto.');
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

                return RespuestaDeCargaIa::error(
                    CategoriasDelNegocioIaHelper::NO_SE_ARMO . 'no hay ninguna foto nueva sin usar. Si la persona pidió la MISMA foto que ya le pusiste a otro artículo, '
                    . 'volvé a llamar en este mismo mensaje con imagen_id ' . (int) $usada->id . ' (la que mandó ' . FotosDeLaConversacionIaHelper::cuando_llego($usada) . '). '
                    . 'Si pidió otra foto, decile que te la mande.',
                    ['imagen_id' => (int) $usada->id]
                );
            }

            return RespuestaDeCargaIa::error(
                'No tengo ninguna foto tuya sin usar de las últimas ' . FotosDeLaConversacionIaHelper::HORAS . ' horas. Mandámela y se la pongo al artículo.'
            );
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

        $resumen = $uno_solo
            ? 'Foto del artículo ' . $nombres[0]
            : 'Foto de ' . count($ids) . ' artículos: ' . implode(', ', $nombres);

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
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

        $imagen = self::foto_bloqueada($contexto, isset($datos['imagen_id']) ? (int) $datos['imagen_id'] : 0);

        if (is_null($imagen)) {

            throw new AccionIaException(422, 'Esa foto ya se usó o no está disponible. Mandámela de nuevo.');
        }

        $binario = AsistenteImagenHelper::binario($imagen);

        if (is_null($binario)) {

            throw new AccionIaException(422, 'No pude leer esa foto. Mandámela de nuevo.');
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

            AsistenteImagenHelper::marcar_gestionadas([(int) $imagen->id]);

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
    protected static function colgar_y_sincronizar(ContextoDeCargaIa $contexto, Article $articulo, $name)
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

        if (count($no_hechos)) {

            $faltan = [];

            foreach ($no_hechos as $no_hecho) {

                $faltan[] = $no_hecho['articulo'] . ' (' . $no_hecho['motivo'] . ')';
            }

            $texto .= ' NO se la pude poner a ' . (count($no_hechos) === 1 ? '1' : count($no_hechos)) . ': ' . implode(', ', $faltan) . '.';
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
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $input
     * @return array<int, \App\Models\Article>|array  Lista de artículos, o la respuesta negativa.
     */
    protected static function resolver_articulos(ContextoDeCargaIa $contexto, array $input)
    {
        $ids = self::ids_del_input(EntradaDeCargaIa::valor($input, 'articulo_ids'));

        if (count($ids)) {

            $articulo_id = EntradaDeCargaIa::valor($input, 'articulo_id');

            if (is_numeric($articulo_id) && (int) $articulo_id > 0 && !in_array((int) $articulo_id, $ids, true)) {

                array_unshift($ids, (int) $articulo_id);
            }

            if (count($ids) > self::TOPE_ARTICULOS) {

                return RespuestaDeCargaIa::error(
                    'En una tarjeta entra la misma foto para hasta ' . self::TOPE_ARTICULOS . ' artículos, y me pasaste ' . count($ids) . '. '
                    . 'Decime cuáles van primero y armo las tarjetas de a ' . self::TOPE_ARTICULOS . '.'
                );
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

            return $articulos;
        }

        $texto = EntradaDeCargaIa::texto($input, 'articulo');

        if ($texto !== '' && self::es_si(EntradaDeCargaIa::valor($input, 'todos_los_que_coinciden'))) {

            return self::todos_los_que_coinciden($contexto, $texto);
        }

        $articulo = self::resolver_articulo($contexto, $input);

        if (RespuestaDeCargaIa::es_negativa($articulo)) {

            return $articulo;
        }

        return [$articulo];
    }

    /**
     * Todos los artículos del dueño que encuentra la búsqueda por palabras para `$texto` (frase entera
     * en nombre o códigos, o cada palabra en el nombre: BusquedaPorPalabrasIaHelper::filtrar), hasta
     * TOPE_ARTICULOS, en orden de nombre. Para «a todos los clavos punta París». Si son más, se pide
     * acotar; si no hay ninguno, el mismo error que con un solo artículo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $texto
     * @return array<int, \App\Models\Article>|array
     */
    protected static function todos_los_que_coinciden(ContextoDeCargaIa $contexto, $texto)
    {
        $columnas = ['name', 'bar_code', 'provider_code'];

        $consulta = Article::query()
                            ->where('user_id', $contexto->owner_id)
                            ->where(function ($sub) use ($texto, $columnas) {
                                BusquedaPorPalabrasIaHelper::filtrar($sub, $texto, $columnas);
                            });

        $candidatos = BusquedaPorPalabrasIaHelper::ordenar_por_frase($consulta, $texto, $columnas)
                                                ->orderBy('name')
                                                ->limit(self::TOPE_ARTICULOS + 1)
                                                ->get(self::COLUMNAS);

        if (count($candidatos) === 0) {

            return RespuestaDeCargaIa::error(
                'No encontré ningún artículo que se llame "' . $texto . '" ni con ese código. Buscalo con otra palabra o decime el código.'
            );
        }

        if (count($candidatos) > self::TOPE_ARTICULOS) {

            return RespuestaDeCargaIa::error(
                'Hay más de ' . self::TOPE_ARTICULOS . ' artículos que encajan con "' . $texto . '". Decime una palabra más (una medida, una marca) '
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
