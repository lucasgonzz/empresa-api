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
 * 🔴 LA FOTO NO SALE DEL PROMPT: SALE DE LAS IMÁGENES SIN GESTIONAR DE LA CONVERSACIÓN, igual que
 * la de sucursal y que la compra con factura. En la práctica esas fotos solo existen en el canal
 * WhatsApp (`ai_message_imagenes` las escribe solo AdminSync\AsistenteController): desde la pantalla
 * la herramienta contesta que no tiene ninguna foto. Se declara con las de carga igual, por el mismo
 * motivo que la de sucursal.
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
     * Columnas que alcanzan para RESOLVER y para nombrar el artículo en la tarjeta. Al ejecutar se
     * lee el modelo entero (ver ejecutar()): ahí hacen falta los campos de sincronización.
     */
    const COLUMNAS = ['id', 'user_id', 'name', 'bar_code', 'provider_code'];

    /**
     * Herramienta proponer_foto_articulo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  articulo (texto), articulo_id, reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('fotos de artículos'));
        }

        $articulo = self::resolver_articulo($contexto, $input);

        if (RespuestaDeCargaIa::es_negativa($articulo)) {

            return $articulo;
        }

        $foto = self::ultima_foto_sin_gestionar($contexto, $mensaje);

        if (is_null($foto)) {

            return RespuestaDeCargaIa::error(
                'No tengo ninguna foto tuya sin usar de las últimas ' . FotosDeLaConversacionIaHelper::HORAS . ' horas. Mandámela y se la pongo al artículo.'
            );
        }

        $nombre = self::nombre_de_articulo($articulo);

        $datos = [
            'article_id' => (int) $articulo->id,
            'articulo'   => $nombre,
            'imagen_id'  => (int) $foto->id,
        ];

        $renglones = [
            ['etiqueta' => 'Artículo', 'valor' => $nombre],
            ['etiqueta' => 'Qué se hace', 'valor' => 'La foto se suma a las imágenes del artículo'],
        ];

        /*
         * Cuándo llegó la foto (misión asistente-fotos-barras-y-compras): con la ventana de 24 horas
         * la foto puede ser de hace un rato largo, y la persona tiene que poder decir "esa no es"
         * antes de que se publique.
         */
        $cuando = FotosDeLaConversacionIaHelper::cuando_llego($foto);

        if ($cuando !== '') {

            $renglones[] = ['etiqueta' => 'Foto', 'valor' => 'La que mandaste ' . $cuando];
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_FOTO_ARTICULO,
            self::clave($articulo->id),
            $datos,
            [
                'titulo'    => 'Foto del artículo',
                'renglones' => $renglones,
                /*
                 * El aviso es la contracara de que esto NO se auto-confirme: la persona tiene que
                 * saber, antes de tocar Confirmar, que esta foto sale publicada.
                 */
                'aviso'     => 'La foto también se publica en la tienda online del negocio.',
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        return AccionesIaHelper::respuesta_de_propuesta($creada, 'Foto del artículo ' . $nombre);
    }

    /**
     * Identidad de la carga para el reemplazo: una foto nueva para el mismo artículo reemplaza la
     * tarjeta, y la de otro artículo no la pisa.
     *
     * @param  int  $article_id
     * @return string
     */
    public static function clave($article_id)
    {
        return 'foto_articulo:' . (int) $article_id;
    }

    /**
     * Guarda la foto como webp, la cuelga del artículo y sella la foto. Corre adentro de la
     * transacción de EjecutorAccionesIaHelper y con la persona ya autenticada.
     *
     * 🔴 TODO SE RE-VERIFICA ACÁ. Entre la propuesta y la confirmación pueden pasar horas: el
     * artículo pudo borrarse y la foto pudo usarla otra tarjeta. La foto se relee con
     * `lockForUpdate` (mismo motivo que la compra con factura: dos tarjetas no pueden llevarse la
     * misma foto).
     *
     * 🔴 LOS CUATRO EFECTOS DE ABAJO SON LOS DE LA PANTALLA (ImageController.php:171-189) Y VAN
     * COMPLETOS. Se copian y no se llama al controller porque `setImage()` es un método de request
     * (lee `$request->image_url`, baja la imagen por HTTP y responde un JSON), y acá la imagen ya
     * está en el disco privado del asistente. Lo que no se puede es quedarse con la fila de `images`
     * y saltear el resto.
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

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('fotos de artículos'));
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        /*
         * El modelo ENTERO y no las columnas de resolver_articulo(): acá se lo guarda y se lo pasa
         * a los tres servicios de sincronización, que leen campos suyos (mercado_libre,
         * meli_category_id, stock, tiendanube_product_id, disponible_tienda_nube). Es lo mismo que
         * hace la pantalla, que trabaja sobre `$model_name::find($request->model_id)`.
         */
        $articulo = Article::where('user_id', $contexto->owner_id)
                            ->where('id', isset($datos['article_id']) ? (int) $datos['article_id'] : 0)
                            ->first();

        if (is_null($articulo)) {

            throw new AccionIaException(422, 'Ese artículo ya no existe entre los tuyos. Pedímelo de nuevo.');
        }

        $imagen = self::foto_bloqueada($contexto, isset($datos['imagen_id']) ? (int) $datos['imagen_id'] : 0);

        if (is_null($imagen)) {

            throw new AccionIaException(422, 'Esa foto ya se usó o no está disponible. Mandámela de nuevo.');
        }

        self::asignar_imagen($contexto, $articulo, $imagen);

        return [
            'texto' => 'Foto agregada al artículo ' . self::nombre_de_articulo($articulo),
            /*
             * El listado de artículos, que es de donde se cargan y se sacan las fotos. Misma ruta
             * que usa la búsqueda de imágenes por filtro (PropuestaImagenesArticulosIaHelper). En
             * WhatsApp esta ruta no se usa: no hay navegación.
             */
            'ruta'  => [
                'name'   => 'article',
                'params' => new \stdClass(),
                'texto'  => 'Ver el listado',
            ],
        ];
    }

    /**
     * Cuelga una foto del asistente de un artículo con los CUATRO EFECTOS DE LA PANTALLA y la sella.
     * Es el cuerpo de ejecutar(), separado en la misión asistente-fotos-barras-y-compras (24/9/2026)
     * para que el alta de un artículo con su foto (AltaDeArticuloConFotoIaHelper) asigne la foto por
     * el MISMO camino y no por una copia que un día se olvide uno de los cuatro efectos.
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

        AsistenteImagenHelper::marcar_gestionadas([(int) $imagen->id]);

        return $image;
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

        /* El `%` y el `_` que escriba la persona son literales, no comodines de LIKE. */
        $escapado = addcslashes($texto, '%_\\');

        $candidatos = (clone $base)
            ->where(function ($sub) use ($escapado) {
                $sub->where('name', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('bar_code', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('provider_code', 'LIKE', '%' . $escapado . '%');
            })
            ->orderBy('name')
            ->limit(self::TOPE_CANDIDATOS + 1)
            ->get(self::COLUMNAS);

        if (count($candidatos) === 0) {

            return RespuestaDeCargaIa::error(
                'No encontré ningún artículo que se llame "' . $texto . '" ni con ese código. Buscalo con otra palabra o decime el código.'
            );
        }

        if (count($candidatos) === 1) {

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

        return RespuestaDeCargaIa::faltan(['a cuál de estos artículos le pongo la foto'], ['articulos' => $opciones]);
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
     * La foto de la tarjeta si sigue sin gestionar, CON LA FILA BLOQUEADA hasta el commit. El
     * candado es lo que evita que dos tarjetas se lleven la misma foto (mismo patrón que
     * PropuestaFotoSucursalIaHelper::foto_bloqueada y PropuestaCompraConFacturaIaHelper).
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

        return AiMessageImagen::where('user_id', $contexto->owner_id)
                                ->where('id', $imagen_id)
                                ->sinGestionar()
                                ->lockForUpdate()
                                ->first();
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
