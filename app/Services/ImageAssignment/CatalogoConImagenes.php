<?php

namespace App\Services\ImageAssignment;

use App\Models\ImageAssignmentItem;
use Illuminate\Support\Facades\DB;

/**
 * Lo que `GET admin-sync/catalogo/articulos?con_imagenes=1` le suma a cada artículo de la página (misión
 * importaciones-de-martin, 11/11/2026), para que la tarea que trae fotos de la web de un proveedor sepa qué
 * artículos buscar y cuáles saltear:
 *   - `tiene_imagen`: tiene al menos una fila en `images`.
 *   - `codigos_pivot`: los `article_provider.provider_code` del artículo (sin vacíos ni repetidos).
 *   - `a_revisar`: tiene una imagen esperando revisión en alguna asignación.
 *
 * Tres consultas por página (no una por artículo). Sin el parámetro, la respuesta sigue igual que siempre.
 */
class CatalogoConImagenes
{
    /**
     * @param  array $articulos  los de CategoryProposalCatalogoHelper::articulos() (con `id`)
     * @param  int   $owner_id
     * @return array
     */
    public static function enriquecer(array $articulos, int $owner_id): array
    {
        $ids = array_map(function ($articulo) {
            return (int) $articulo['id'];
        }, $articulos);
        if ($ids === []) {
            return $articulos;
        }

        $con_imagen = array_flip(DB::table('images')->where('imageable_type', 'article')
            ->whereIn('imageable_id', $ids)->distinct()->pluck('imageable_id')->map(function ($id) {
                return (int) $id;
            })->all());

        $a_revisar = array_flip(ImageAssignmentItem::where('user_id', $owner_id)->whereIn('article_id', $ids)
            ->where('status', ImageAssignmentItem::STATUS_A_REVISAR)->distinct()->pluck('article_id')->map(function ($id) {
                return (int) $id;
            })->all());

        $pivot = [];
        foreach (DB::table('article_provider')->whereIn('article_id', $ids)->whereNotNull('provider_code')
            ->orderBy('id')->get(['article_id', 'provider_code']) as $fila) {
            $codigo = trim((string) $fila->provider_code);
            if ($codigo !== '' && !in_array($codigo, isset($pivot[(int) $fila->article_id]) ? $pivot[(int) $fila->article_id] : [], true)) {
                $pivot[(int) $fila->article_id][] = $codigo;
            }
        }

        foreach ($articulos as &$articulo) {
            $id = (int) $articulo['id'];
            $articulo['tiene_imagen'] = isset($con_imagen[$id]);
            $articulo['codigos_pivot'] = isset($pivot[$id]) ? $pivot[$id] : [];
            $articulo['a_revisar'] = isset($a_revisar[$id]);
        }
        unset($articulo);

        return $articulos;
    }
}
