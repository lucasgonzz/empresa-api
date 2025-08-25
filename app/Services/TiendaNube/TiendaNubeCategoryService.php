<?php

namespace App\Services\TiendaNube;

use App\Models\Article;
use App\Models\Category;
use App\Models\SubCategory;
use App\Services\TiendaNube\BaseTiendaNubeService;

/**
 * Responsabilidad: CRUD y resolución de categorías en Tienda Nube.
 * - Crea categorías raíz (Category) y subcategorías (SubCategory con parent)
 * - Resuelve el category_id de TN a asignar a un artículo
 */
class TiendaNubeCategoryService extends BaseTiendaNubeService
{
    /**
     * Crea/asegura una Category de primer nivel (raíz) en Tienda Nube y devuelve su ID en TN.
     */
    public function ensureRootCategory(Category $cat): int
    {
        if (!empty($cat->tiendanube_category_id)) {
            return (int) $cat->tiendanube_category_id;
        }

        $endpoint = "/{$this->store_id}/categories";
        $payload  = ['name' => ['es' => $cat->name]]; // raíz: sin parent

        $resp = $this->http()->post($endpoint, $payload);
        if ($resp->failed()) {
            throw new \RuntimeException('No se pudo crear Category en TN: '.$resp->body());
        }

        $data = $resp->json();
        $cat->tiendanube_category_id = $data['id'] ?? null;
        $cat->save();

        return (int) $cat->tiendanube_category_id;
    }

    /**
     * Crea/asegura una SubCategory como hija de una Category en Tienda Nube y devuelve su ID en TN.
     */
    public function ensureSubCategory(SubCategory $sub, Category $parentCat): int
    {
        if (!empty($sub->tiendanube_category_id)) {
            return (int) $sub->tiendanube_category_id;
        }

        $parentId = $this->ensureRootCategory($parentCat);

        $endpoint = "/{$this->store_id}/categories";
        $payload  = [
            'name'   => ['es' => $sub->name],
            'parent' => $parentId,
        ];

        $resp = $this->http()->post($endpoint, $payload);
        if ($resp->failed()) {
            throw new \RuntimeException('No se pudo crear SubCategory en TN: '.$resp->body());
        }

        $data = $resp->json();
        $sub->tiendanube_category_id = $data['id'] ?? null;
        $sub->save();

        return (int) $sub->tiendanube_category_id;
    }

    /**
     * Devuelve el category_id de TN que debe asignarse al artículo:
     * - Si tiene sub_category => usa sub_category (y asegura su parent)
     * - Si no, usa category (raíz)
     * - Si no tiene ninguna, retorna null
     */
    public function resolveTNCategoryIdForArticle(Article $article): ?int
    {
        // SubCategory primero
        if ($article->relationLoaded('sub_category') ? $article->sub_category : $article->sub_category()->exists()) {
            $sub    = $article->sub_category ?? $article->sub_category()->first();
            $parent = $sub->category ?? ($sub->category()->exists() ? $sub->category()->first() : null);
            if (!$sub || !$parent) return null;

            return $this->ensureSubCategory($sub, $parent);
        }

        // Category raíz
        if ($article->relationLoaded('category') ? $article->category : $article->category()->exists()) {
            $cat = $article->category ?? $article->category()->first();
            if (!$cat) return null;

            return $this->ensureRootCategory($cat);
        }

        return null;
    }

     /**
     * Sincroniza una Category raíz:
     * - Si no existe en TN: la crea
     * - Si existe: hace PUT para actualizar nombre (y nada más)
     */
    public function syncRootCategory(Category $cat): int
    {
        if (empty($cat->tiendanube_category_id)) {
            return $this->ensureRootCategory($cat);
        }

        $endpoint = "/{$this->store_id}/categories/{$cat->tiendanube_category_id}";
        $payload  = ['name' => ['es' => $cat->name]];

        $resp = $this->http()->put($endpoint, $payload);
        if ($resp->failed()) {
            // Si por algún motivo el id dejó de existir en TN, lo recreamos
            if ($resp->status() === 404) {
                return $this->ensureRootCategory($cat);
            }
            throw new \RuntimeException('No se pudo actualizar Category en TN: '.$resp->body());
        }

        return (int) $cat->tiendanube_category_id;
    }

    /**
     * Sincroniza una SubCategory:
     * - Garantiza el parent en TN
     * - Si no existe en TN: la crea
     * - Si existe: hace PUT para actualizar nombre y parent (si cambió)
     */
    public function syncSubCategory(SubCategory $sub): int
    {
        $parentCat = $sub->category; // asume relación belongsTo
        $parentId  = $this->ensureRootCategory($parentCat);

        if (empty($sub->tiendanube_category_id)) {
            return $this->ensureSubCategory($sub, $parentCat);
        }

        $endpoint = "/{$this->store_id}/categories/{$sub->tiendanube_category_id}";
        $payload  = [
            'name'   => ['es' => $sub->name],
            'parent' => $parentId,
        ];

        $resp = $this->http()->put($endpoint, $payload);
        if ($resp->failed()) {
            if ($resp->status() === 404) {
                // si no existe más, re-crear
                return $this->ensureSubCategory($sub, $parentCat);
            }
            throw new \RuntimeException('No se pudo actualizar SubCategory en TN: '.$resp->body());
        }

        return (int) $sub->tiendanube_category_id;
    }
}
