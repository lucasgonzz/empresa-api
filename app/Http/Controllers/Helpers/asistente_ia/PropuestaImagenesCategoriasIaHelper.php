<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\CategoriaImagenHelper;
use App\Jobs\ProcessCategoryImagesJob;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\BackgroundProcess;
use App\Models\Category;
use App\Models\GeocoderCounter;
use App\Models\User;
use Carbon\Carbon;

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
 * ⚠️ RESPALDO DE CREDENCIALES Y CUOTA. El plan pone la key/cx/cuota y el conteo del día en
 * ImagenesAutomaticasHelper::credenciales() / cuota_de() (constructor A). Como ese helper no
 * existía cuando se escribió esto, acá hay dos métodos protegidos con la misma lógica que
 * GoogleController::batch_assign_images (key del owner o config, cx fijo, cuota default 10,
 * contador GeocoderCounter del día). Cuando exista el de A, estos dos se reemplazan por una
 * llamada a él (queda anotado en el informe de la misión).
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

    /** Motor de búsqueda personalizado: el mismo que usa GoogleController. */
    const CX = 'c442e5f346f314951';

    /** Cuota diaria si el dueño no tiene una propia (users.google_cuota). */
    const CUOTA_POR_DEFECTO = 10;

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

        $nombres = [];

        foreach ($seleccion as $item) {
            $nombres[] = $item['nombre'];
        }

        $renglones = [
            ['etiqueta' => 'Categorías', 'valor' => count($seleccion).' ('.$descripcion.')'],
            ['etiqueta' => 'Cuáles', 'valor' => self::lista_de_nombres($nombres)],
            ['etiqueta' => 'Búsquedas disponibles hoy', 'valor' => $cuota['disponibles'].' de '.$cuota['cuota']],
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            self::TIPO,
            self::CLAVE,
            ['categorias' => $seleccion, 'alcance' => $alcance],
            ['titulo' => 'Imágenes para categorías', 'renglones' => $renglones, 'aviso' => self::AVISO],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Imágenes para '.count($seleccion).' categorías ('.$descripcion.')',
            [
                'busquedas_disponibles_hoy' => $cuota['disponibles'],
                'cuota_diaria'              => $cuota['cuota'],
            ]
        );
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

        $credenciales = self::credenciales($contexto->owner);

        $total = count($categorias);

        BackgroundProcessHelper::iniciar($contexto->owner_id, ProcessCategoryImagesJob::TIPO_PROCESO, ProcessCategoryImagesJob::TITULO_PROCESO, [
            'auth_user_id' => is_null($contexto->persona) ? null : (int) $contexto->persona->id,
            'total'        => $total,
            'unidad'       => 'categorías',
            'detalle'      => $total.' categorías',
            'status'       => BackgroundProcess::STATUS_PENDIENTE,
            'etapa'        => 'En espera del procesador',
        ]);

        ProcessCategoryImagesJob::dispatch(
            (int) $contexto->owner_id,
            is_null($contexto->persona) ? null : (int) $contexto->persona->id,
            (int) $contexto->conversation->id,
            $categorias,
            (string) $credenciales['api_key'],
            (string) $credenciales['cx'],
            (int) $credenciales['cuota']
        );

        return [
            'texto' => 'Mandé a buscar imágenes para '.$total.' '.($total === 1 ? 'categoría' : 'categorías').'. Cuando termine te escribo acá con el resultado, y en el sistema te aparece el proceso.',
            'ruta'  => PropuestaImagenCategoriaIaHelper::ruta_a_categorias(),
        ];
    }

    /**
     * Las categorías que la persona nombró, resueltas por nombre contra las del dueño.
     *
     * Coincidencia exacta (sin distinguir mayúsculas) gana; si no, LIKE %nombre%: una sola → esa;
     * varias → `faltan` con las opciones; ninguna → error. Se resuelve acá y no en el helper de
     * filtros de A para no depender del orden en que terminan los dos constructores.
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

            $candidatas = Category::where('user_id', $contexto->owner_id)
                ->where('name', 'LIKE', '%'.$nombre.'%')
                ->orderBy('name')
                ->get();

            $exactas = $candidatas->filter(function ($categoria) use ($nombre) {
                return mb_strtolower(trim((string) $categoria->name)) === mb_strtolower($nombre);
            })->values();

            if (count($exactas) === 1) {
                $elegida = $exactas[0];
            } elseif (count($candidatas) === 1) {
                $elegida = $candidatas[0];
            } elseif (count($candidatas) === 0) {
                return RespuestaDeCargaIa::error('No encontré ninguna categoría que se llame "'.$nombre.'".');
            } else {
                $opciones = [];

                foreach ($candidatas as $categoria) {
                    $opciones[] = ['id' => (int) $categoria->id, 'nombre' => (string) $categoria->name];
                }

                return RespuestaDeCargaIa::faltan(['a cuál categoría te referís con "'.$nombre.'"'], ['categorias' => $opciones]);
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
     * Cuota diaria de búsquedas del dueño y cuántas van hoy (respaldo de
     * ImagenesAutomaticasHelper::cuota_de, ver el docblock de la clase).
     *
     * @param  \App\Models\User|null  $owner
     * @return array  ['cuota' => int, 'usadas_hoy' => int, 'disponibles' => int]
     */
    protected static function cuota_de($owner)
    {
        $cuota = (!is_null($owner) && $owner->google_cuota) ? (int) $owner->google_cuota : self::CUOTA_POR_DEFECTO;

        $usadas = 0;

        if (!is_null($owner)) {
            $counter = GeocoderCounter::where('user_id', $owner->id)
                ->whereDate('created_at', Carbon::today())
                ->first();

            $usadas = is_null($counter) ? 0 : (int) $counter->counter;
        }

        return [
            'cuota'       => $cuota,
            'usadas_hoy'  => $usadas,
            'disponibles' => max(0, $cuota - $usadas),
        ];
    }

    /**
     * Key, cx y cuota con las que se despacha el job (respaldo de
     * ImagenesAutomaticasHelper::credenciales, misma lógica que GoogleController::batch_assign_images).
     *
     * @param  \App\Models\User  $owner
     * @return array  ['api_key' => string, 'cx' => string, 'cuota' => int]
     */
    protected static function credenciales(User $owner)
    {
        $api_key = $owner->google_custom_search_api_key
            ? (string) $owner->google_custom_search_api_key
            : (string) config('services.google_search.api_key');

        return [
            'api_key' => $api_key,
            'cx'      => self::CX,
            'cuota'   => $owner->google_cuota ? (int) $owner->google_cuota : self::CUOTA_POR_DEFECTO,
        ];
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
