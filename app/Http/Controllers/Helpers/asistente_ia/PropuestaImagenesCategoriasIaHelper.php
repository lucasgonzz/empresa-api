<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\CategoriaImagenHelper;
use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Jobs\ProcessCategoryImagesJob;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\BackgroundProcess;
use App\Models\Category;
use App\Models\User;

/**
 * Mandar a buscar imágenes para las categorías del comercio, desde el asistente (misión
 * asistente-masivas-imagenes-y-remito, 19/9/2026, contrato §1.1 y §1.2).
 *
 * `consultar()` es lo que devuelve la herramienta consultar_categorias_sin_imagen; `proponer()`
 * arma la tarjeta "Imágenes para categorías" (tipo 'imagenes_categorias'); `ejecutar()` encola
 * ProcessCategoryImagesJob y deja el registro visible en `pendiente`, para que la píldora lo
 * muestre al toque. Es una carga inocua y reversible (una imagen se cambia desde el ABM), así que
 * en modo "resuelto" se auto-confirma; en "cauteloso" queda la tarjeta.
 *
 * El tipo es el literal 'imagenes_categorias': la constante AiMessageAction::TIPO_IMAGENES_CATEGORIAS
 * con ese valor la declara el constructor A de esta misión.
 *
 * La key, el cx, la cuota diaria y el contador del día salen de ImagenesAutomaticasHelper
 * (credenciales() / cuota_de()): es el MISMO lugar del que sale el lote de imágenes de artículos y
 * el botón del listado, así que la cuota se cuenta de una sola forma para las tres puertas.
 */
class PropuestaImagenesCategoriasIaHelper
{
    /** Tipo de la tarjeta (= AiMessageAction::TIPO_IMAGENES_CATEGORIAS, que escribe A). */
    const TIPO = 'imagenes_categorias';

    /** Clave de reemplazo: una sola tanda propuesta por conversación. */
    const CLAVE = 'imagenes_categorias';

    const MENSAJE_SIN_PERMISO = 'Solo el dueño puede mandar a buscar imágenes para las categorías.';

    /** Hasta cuántas categorías lista consultar(). */
    const CATEGORIAS_EN_LA_CONSULTA = 50;

    /** Hasta cuántos nombres van en el renglón "Cuáles" de la tarjeta. */
    const NOMBRES_EN_LA_TARJETA = 10;

    /** Búsquedas que puede usar una categoría (q1 con fondo blanco y q2 sin filtro). */
    const BUSQUEDAS_POR_CATEGORIA = 2;

    const MENSAJE_SIN_CUOTA = 'No quedan búsquedas de imágenes por hoy: la cuota diaria de Google ya se usó. Pedímelo de nuevo mañana.';

    const AVISO = 'Cada categoría usa hasta 2 búsquedas de la cuota diaria de Google. Las que no me convenzan te las voy a mostrar acá para que decidas.';

    /**
     * Herramienta consultar_categorias_sin_imagen (contrato §1.1).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return array
     */
    public static function consultar(ContextoDeCargaIa $contexto)
    {
        $todas = CategoriaImagenHelper::todas($contexto->owner_id);

        $sin_imagen = $todas->filter(function ($categoria) {
            return is_null($categoria->image_url) || trim((string) $categoria->image_url) === '';
        })->sortByDesc('articles_count')->values();

        $categorias = [];

        foreach ($sin_imagen->take(self::CATEGORIAS_EN_LA_CONSULTA) as $categoria) {
            $categorias[] = [
                'id'        => (int) $categoria->id,
                'nombre'    => (string) $categoria->name,
                'articulos' => (int) $categoria->articles_count,
            ];
        }

        $cuota = self::cuota_de($contexto->owner);

        return [
            'ok'                        => true,
            'total_categorias'          => count($todas),
            'sin_imagen'                => count($sin_imagen),
            'categorias'                => $categorias,
            'busquedas_disponibles_hoy' => $cuota['disponibles'],
            'cuota_diaria'              => $cuota['cuota'],
            'nota'                      => 'Cada categoría usa hasta '.self::BUSQUEDAS_POR_CATEGORIA.' búsquedas de la cuota diaria.',
        ];
    }

    /**
     * Herramienta proponer_imagenes_para_categorias (contrato §1.2).
     *
     * @param  ContextoDeCargaIa      $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array                  $input    alcance ('sin_imagen' | 'todas'), categorias
     *                                          ([{nombre, buscar_como}], opcional), reemplaza_a.
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::es_admin($contexto->persona)) {
            return RespuestaDeCargaIa::error(self::MENSAJE_SIN_PERMISO);
        }

        $alcance = EntradaDeCargaIa::texto($input, 'alcance');
        $alcance = $alcance === 'todas' ? 'todas' : 'sin_imagen';

        $pedidas = EntradaDeCargaIa::valor($input, 'categorias');

        if (is_array($pedidas) && count($pedidas)) {
            $seleccion = self::resolver_pedidas($contexto, $pedidas);

            if (RespuestaDeCargaIa::es_negativa($seleccion)) {
                return $seleccion;
            }

            /*
             * Una categoría NOMBRADA se procesa aunque ya tenga imagen ("buscá una nueva para
             * Pinturas"): la persona la pidió por su nombre, no "las que no tienen". Por eso el
             * alcance que viaja al job es 'todas' — con 'sin_imagen' el job la saltearía al llegar
             * y la tarjeta habría prometido un reemplazo que nunca pasa (lo encontró el revisor de
             * merge). Si alguna ya tiene imagen, más abajo la tarjeta lo dice y exige confirmación.
             */
            $alcance = 'todas';

            $descripcion = count($seleccion) === 1 ? 'la que me pediste' : 'las que me pediste';
        } else {
            $seleccion = [];

            $categorias = $alcance === 'todas'
                ? CategoriaImagenHelper::todas($contexto->owner_id)
                : CategoriaImagenHelper::sin_imagen($contexto->owner_id);

            foreach ($categorias as $categoria) {
                $seleccion[] = ['id' => (int) $categoria->id, 'nombre' => (string) $categoria->name, 'buscar_como' => null];
            }

            if (!count($seleccion)) {
                return RespuestaDeCargaIa::error(
                    $alcance === 'todas'
                        ? 'No tenés categorías cargadas.'
                        : 'Todas tus categorías ya tienen imagen. Si querés reemplazar alguna, decime cuál.'
                );
            }

            $descripcion = $alcance === 'todas' ? 'todas, se reemplaza la imagen de las que ya tienen' : 'las que no tienen imagen';
        }

        $cuota = self::cuota_de($contexto->owner);

        if ((int) $cuota['disponibles'] <= 0) {
            return RespuestaDeCargaIa::error(self::MENSAJE_SIN_CUOTA);
        }

        $nombres = [];

        foreach ($seleccion as $item) {
            $nombres[] = $item['nombre'];
        }

        /*
         * 🔴 Reemplazar una imagen que ya está cargada NO es inocuo: la foto que el negocio eligió
         * a mano se pisa y no vuelve sola. Por eso, si la selección incluye categorías que ya tienen
         * imagen (alcance 'todas', o una nombrada que ya la tiene), la tarjeta se marca como
         * "requiere confirmación" y HerramientasDeCarga no la auto-confirma ni en "resuelto": la
         * persona ve cuáles se van a reemplazar y decide con el botón. Lo que solo llena huecos
         * sigue yendo directo.
         */
        $con_imagen = self::nombres_con_imagen($contexto->owner_id, $seleccion);

        $renglones = [
            ['etiqueta' => 'Categorías', 'valor' => count($seleccion).' ('.$descripcion.')'],
            ['etiqueta' => 'Cuáles', 'valor' => self::lista_de_nombres($nombres)],
        ];

        if (count($con_imagen)) {
            $renglones[] = ['etiqueta' => 'Se reemplaza la imagen de', 'valor' => self::lista_de_nombres($con_imagen)];
        }

        $renglones[] = ['etiqueta' => 'Búsquedas disponibles hoy', 'valor' => $cuota['disponibles'].' de '.$cuota['cuota']];

        $aviso = count($con_imagen)
            ? self::AVISO.' Las que ya tienen imagen la van a perder si encuentro otra: por eso te pido que confirmes.'
            : self::AVISO;

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            self::TIPO,
            self::CLAVE,
            ['categorias' => $seleccion, 'alcance' => $alcance, 'reemplaza_existentes' => count($con_imagen) > 0],
            ['titulo' => 'Imágenes para categorías', 'renglones' => $renglones, 'aviso' => $aviso],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $extra = [
            'busquedas_disponibles_hoy' => $cuota['disponibles'],
            'cuota_diaria'              => $cuota['cuota'],
        ];

        if (count($con_imagen)) {
            $extra['requiere_confirmacion'] = true;
            $extra['reemplaza_la_imagen_de'] = $con_imagen;
            $extra['nota'] = 'Esta tanda reemplaza imágenes que ya están cargadas: queda la tarjeta para que la persona confirme, aunque su confianza esté en "resuelto". No digas que ya la mandaste.';
        }

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Imágenes para '.count($seleccion).' categorías ('.$descripcion.')',
            $extra
        );
    }

    /**
     * Los nombres de las categorías de la selección que YA tienen imagen cargada (las que una
     * tanda reemplazaría), en el orden de la selección.
     *
     * @param  int    $owner_id
     * @param  array  $seleccion  [['id', 'nombre', 'buscar_como'], ...]
     * @return array<int, string>
     */
    protected static function nombres_con_imagen($owner_id, array $seleccion)
    {
        $ids = [];

        foreach ($seleccion as $item) {
            $ids[] = (int) $item['id'];
        }

        if (!count($ids)) {
            return [];
        }

        $con_imagen = Category::where('user_id', $owner_id)
            ->whereIn('id', $ids)
            ->whereNotNull('image_url')
            ->where('image_url', '!=', '')
            ->pluck('id')
            ->all();

        $nombres = [];

        foreach ($seleccion as $item) {
            if (in_array((int) $item['id'], $con_imagen, true)) {
                $nombres[] = $item['nombre'];
            }
        }

        return $nombres;
    }

    /**
     * Encola el job y deja el registro visible `pendiente`. Corre adentro de la transacción de
     * EjecutorAccionesIaHelper: si algo de acá tira, no queda ni el proceso ni el job.
     *
     * @param  ContextoDeCargaIa            $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::es_admin($contexto->persona)) {
            throw new AccionIaException(422, self::MENSAJE_SIN_PERMISO);
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $pedidas = isset($datos['categorias']) && is_array($datos['categorias']) ? $datos['categorias'] : [];

        // Se re-verifica contra la base: entre la tarjeta y el clic pueden pasar horas.
        $categorias = [];

        foreach ($pedidas as $pedida) {
            $id = isset($pedida['id']) ? (int) $pedida['id'] : 0;

            if ($id <= 0 || !Category::where('user_id', $contexto->owner_id)->where('id', $id)->exists()) {
                continue;
            }

            $categorias[] = [
                'id'          => $id,
                'buscar_como' => isset($pedida['buscar_como']) && trim((string) $pedida['buscar_como']) !== '' ? trim((string) $pedida['buscar_como']) : null,
            ];
        }

        if (!count($categorias)) {
            throw new AccionIaException(422, 'Esas categorías ya no existen. Pedímelo de nuevo.');
        }

        if (is_null($contexto->owner)) {
            throw new AccionIaException(422, 'No pude identificar la cuenta. Pedímelo de nuevo.');
        }

        // Se re-mira la cuota al confirmar: la tarjeta pudo quedar propuesta a la mañana y el
        // lote del listado gastar las búsquedas del día en el medio. Encolar con cuota cero es
        // decirle "mandé a buscar" y que el job termine al toque con todo en sin_cuota.
        if ((int) self::cuota_de($contexto->owner)['disponibles'] <= 0) {
            throw new AccionIaException(422, self::MENSAJE_SIN_CUOTA);
        }

        $credenciales = self::credenciales($contexto->owner);

        $alcance = isset($datos['alcance']) && $datos['alcance'] === 'todas' ? 'todas' : 'sin_imagen';

        $total = count($categorias);

        $proceso = BackgroundProcessHelper::iniciar($contexto->owner_id, ProcessCategoryImagesJob::TIPO_PROCESO, ProcessCategoryImagesJob::TITULO_PROCESO, [
            'auth_user_id' => is_null($contexto->persona) ? null : (int) $contexto->persona->id,
            'total'        => $total,
            'unidad'       => 'categorías',
            'detalle'      => $total.' categorías',
            'status'       => BackgroundProcess::STATUS_PENDIENTE,
            'etapa'        => 'En espera del procesador',
        ]);

        /*
         * 🔴 `afterCommit()` EXPLÍCITO, el mismo motivo que ImagenesAutomaticasHelper::encolar(): esto
         * corre adentro de la transacción de EjecutorAccionesIaHelper (el clic en Confirmar o la
         * auto-confirmación del agente), y con la cola en redis (el VPS) un worker libre puede tomar
         * el job ANTES del commit, no encontrar el registro `pendiente` que se acaba de abrir y abrir
         * otro, dejando el primero colgado hasta que el listado lo dé por muerto. El default de config
         * no alcanza: `after_commit` es true solo en la conexión `database`; en `redis` está en false.
         * Sin transacción abierta despacha en el acto, igual que siempre.
         */
        ProcessCategoryImagesJob::dispatch(
            (int) $contexto->owner_id,
            is_null($contexto->persona) ? null : (int) $contexto->persona->id,
            (int) $contexto->conversation->id,
            $categorias,
            (string) $credenciales['api_key'],
            (string) $credenciales['cx'],
            (int) $credenciales['cuota'],
            $alcance,
            is_null($proceso) ? null : (int) $proceso->id
        )->afterCommit();

        return [
            'texto' => 'Mandé a buscar imágenes para '.$total.' '.($total === 1 ? 'categoría' : 'categorías').'. Cuando termine te escribo acá con el resultado, y en el sistema te aparece el proceso.',
            'ruta'  => PropuestaImagenCategoriaIaHelper::ruta_a_categorias(),
        ];
    }

    /**
     * Las categorías que la persona nombró, resueltas por nombre contra las del dueño.
     *
     * Usa FiltroDeArticulosIaHelper::resolver_relacion, que es la MISMA resolución que aplica el
     * filtro de artículos y la masiva ("categoría = Tornillos"): coincidencia exacta gana, LIKE
     * con los comodines escapados, una sola → esa, varias → `faltan` con las opciones, ninguna →
     * error. Un solo criterio para "a qué categoría te referís" en todo el asistente.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array              $pedidas  [{nombre, buscar_como}]
     * @return array  [['id', 'nombre', 'buscar_como'], ...] o la respuesta negativa.
     */
    protected static function resolver_pedidas(ContextoDeCargaIa $contexto, array $pedidas)
    {
        $seleccion = [];

        foreach ($pedidas as $pedida) {
            if (!is_array($pedida)) {
                continue;
            }

            $nombre = EntradaDeCargaIa::texto($pedida, 'nombre');

            if ($nombre === '') {
                continue;
            }

            $elegida = FiltroDeArticulosIaHelper::resolver_relacion($contexto->owner_id, 'categoria', $nombre);

            if (RespuestaDeCargaIa::es_negativa($elegida)) {
                return $elegida;
            }

            $buscar_como = EntradaDeCargaIa::texto($pedida, 'buscar_como');

            $seleccion[(int) $elegida->id] = [
                'id'          => (int) $elegida->id,
                'nombre'      => (string) $elegida->name,
                'buscar_como' => $buscar_como === '' ? null : $buscar_como,
            ];
        }

        if (!count($seleccion)) {
            return RespuestaDeCargaIa::error('No encontré esa categoría.');
        }

        return array_values($seleccion);
    }

    /**
     * Cuota diaria de búsquedas del dueño y cuántas van hoy. Delega en ImagenesAutomaticasHelper
     * (la misma cuenta que usa el lote de artículos y el botón del listado); sin dueño resuelto no
     * hay nada que contar y se contesta cero, que es lo que hace que consultar() diga "sin cuota".
     *
     * @param  \App\Models\User|null  $owner
     * @return array  ['cuota' => int, 'usadas_hoy' => int, 'disponibles' => int]
     */
    protected static function cuota_de($owner)
    {
        if (is_null($owner)) {
            return ['cuota' => 0, 'usadas_hoy' => 0, 'disponibles' => 0];
        }

        return ImagenesAutomaticasHelper::cuota_de($owner);
    }

    /**
     * Key, cx y cuota con las que se despacha el job: exactamente las mismas que el lote de
     * artículos (ImagenesAutomaticasHelper::credenciales). Si algún día cambia el cx o la cuota por
     * defecto, cambia en un solo lugar para las tres puertas.
     *
     * @param  \App\Models\User  $owner
     * @return array  ['api_key' => string, 'cx' => string, 'cuota' => int]
     */
    protected static function credenciales(User $owner)
    {
        return ImagenesAutomaticasHelper::credenciales($owner);
    }

    /**
     * "A, B, C +N más", hasta NOMBRES_EN_LA_TARJETA nombres.
     *
     * @param  array  $nombres
     * @return string
     */
    protected static function lista_de_nombres(array $nombres)
    {
        $visibles = array_slice(array_values($nombres), 0, self::NOMBRES_EN_LA_TARJETA);
        $restan   = count($nombres) - count($visibles);

        return implode(', ', $visibles).($restan > 0 ? ' +'.$restan.' más' : '');
    }
}
