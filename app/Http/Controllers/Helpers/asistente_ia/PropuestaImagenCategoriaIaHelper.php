<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\CategoriaImagenHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Category;

/**
 * La tarjeta de UNA imagen candidata dudosa para una categoría (misión
 * asistente-masivas-imagenes-y-remito, 19/9/2026, contrato §2).
 *
 * A diferencia de las demás propuestas, esta NO la arma la IA en un turno: la crea
 * ProcessCategoryImagesJob al terminar, colgada del mensaje de cierre que él mismo escribe, con
 * la imagen que encontró y el motivo por el que no se animó a asignarla. La persona la mira y
 * toca "Usar esta imagen" o "No usarla". Al confirmar, se asigna por el mismo camino que la
 * pantalla (CategoriaImagenHelper::asignar, con el sync a Tienda Nube).
 *
 * El tipo es el literal 'imagen_categoria': la constante AiMessageAction::TIPO_IMAGEN_CATEGORIA con
 * ese valor la declara el constructor A de esta misión (se usa el literal para no depender del
 * orden en que terminan los dos).
 */
class PropuestaImagenCategoriaIaHelper
{
    /** Tipo de la tarjeta (= AiMessageAction::TIPO_IMAGEN_CATEGORIA, que escribe A). */
    const TIPO = 'imagen_categoria';

    const MENSAJE_SIN_PERMISO = 'Solo el dueño puede cambiar la imagen de una categoría.';

    const MENSAJE_CATEGORIA_INEXISTENTE = 'Esa categoría ya no existe entre las tuyas. Pedime que la busque de nuevo.';

    const MENSAJE_IMAGEN_INEXISTENTE = 'Esa imagen ya no está; pedime que la busque de nuevo.';

    /**
     * Crea la tarjeta desde el job, colgada del mensaje de cierre.
     *
     * @param  ContextoDeCargaIa      $contexto
     * @param  \App\Models\AiMessage  $mensaje      El assistant de cierre que escribió el job.
     * @param  \App\Models\Category   $categoria
     * @param  string                 $archivo      catcand_<uuid>.webp, en storage/app/public.
     * @param  string                 $url          URL pública de ese archivo.
     * @param  string                 $motivo       Por qué la IA dudó (se muestra tal cual).
     * @param  string|null            $buscar_como  Con qué término se buscó, si no fue el nombre.
     * @return \App\Models\AiMessageAction
     */
    public static function crear_desde_job(ContextoDeCargaIa $contexto, AiMessage $mensaje, Category $categoria, $archivo, $url, $motivo, $buscar_como = null)
    {
        $nombre = (string) $categoria->name;

        $datos = [
            'category_id' => (int) $categoria->id,
            'archivo'     => (string) $archivo,
            'url'         => (string) $url,
            'buscar_como' => is_null($buscar_como) || trim((string) $buscar_como) === '' ? null : trim((string) $buscar_como),
        ];

        $presentacion = [
            'titulo'          => 'Imagen para la categoría '.$nombre,
            'renglones'       => [
                ['etiqueta' => 'Categoría', 'valor' => $nombre],
                ['etiqueta' => 'Por qué dudo', 'valor' => (string) $motivo],
            ],
            'aviso'           => 'Si la usás, queda como imagen de la categoría en el sistema y en la tienda.',
            'imagen_url'      => (string) $url,
            'texto_confirmar' => 'Usar esta imagen',
            'texto_cancelar'  => 'No usarla',
            'texto_cancelada' => 'No usaste esta imagen.',
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            self::TIPO,
            self::clave($categoria->id),
            $datos,
            $presentacion
        );

        return $creada['accion'];
    }

    /**
     * Identidad de la carga: una candidata nueva para la misma categoría reemplaza la tarjeta
     * anterior que siguiera propuesta.
     *
     * @param  int  $category_id
     * @return string
     */
    public static function clave($category_id)
    {
        return 'imagen_categoria:'.(int) $category_id;
    }

    /**
     * "Usar esta imagen": re-verifica que la categoría siga siendo del dueño y que el archivo
     * siga en el disco (entre la tarjeta y el clic pueden pasar horas, y la purga se lleva las
     * candidatas de más de tres días), y la asigna. Corre adentro de la transacción de
     * EjecutorAccionesIaHelper.
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

        $categoria = Category::where('user_id', $contexto->owner_id)
            ->where('id', isset($datos['category_id']) ? (int) $datos['category_id'] : 0)
            ->first();

        if (is_null($categoria)) {
            throw new AccionIaException(422, self::MENSAJE_CATEGORIA_INEXISTENTE);
        }

        $ruta = CategoriaImagenHelper::ruta_de_candidata(isset($datos['archivo']) ? $datos['archivo'] : '');

        if (is_null($ruta) || !is_file($ruta)) {
            throw new AccionIaException(422, self::MENSAJE_IMAGEN_INEXISTENTE);
        }

        /*
         * Se COPIA a un nombre definitivo y se asigna esa copia, no la candidata: la candidata la
         * purga el job a los tres días, y la tarjeta ya confirmada sigue mostrando su miniatura
         * mientras tanto. Ver CategoriaImagenHelper::promover_candidata.
         */
        $definitiva = CategoriaImagenHelper::promover_candidata($datos['archivo'], true);

        if (is_null($definitiva)) {
            throw new AccionIaException(422, self::MENSAJE_IMAGEN_INEXISTENTE);
        }

        CategoriaImagenHelper::asignar($categoria, $definitiva['url']);

        return [
            'texto' => 'Imagen asignada a la categoría '.$categoria->name,
            'ruta'  => self::ruta_a_categorias(),
        ];
    }

    /**
     * ABM > Artículos > Categorías. `sub_view` es routeString(plural('category')) del SPA
     * (`Categorias` → `categorias`), que es lo que Abm.vue resuelve desde /abm/:view/:sub_view.
     *
     * @return array
     */
    public static function ruta_a_categorias()
    {
        return [
            'name'   => 'abm',
            'params' => ['view' => 'articulos', 'sub_view' => 'categorias'],
            'texto'  => 'Ver categorías',
        ];
    }

    /**
     * "No usarla": la candidata que la tarjeta tenía preparada en disco se borra. Best-effort y
     * después de que la tarjeta ya quedó cancelada (lo llama EjecutorAccionesIaHelper::cancelar
     * afuera de su transacción): un archivo que no se deja borrar no deshace la cancelación, y la
     * purga de tres días lo agarra después. El nombre se valida con ruta_de_candidata(): nunca se
     * borra algo que no sea un `catcand_<uuid>.webp`.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @return bool  true si se borró un archivo.
     */
    public static function al_cancelar(AiMessageAction $accion)
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];

        $ruta = CategoriaImagenHelper::ruta_de_candidata(isset($datos['archivo']) ? $datos['archivo'] : '');

        if (is_null($ruta) || !is_file($ruta)) {
            return false;
        }

        return (bool) @unlink($ruta);
    }
}
