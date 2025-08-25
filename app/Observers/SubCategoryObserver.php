<?php

namespace App\Observers;

use App\Models\SubCategory;
use App\Services\TiendaNube\TiendaNubeCategoryService;

class SubCategoryObserver
{
    public function updated(SubCategory $sub): void
    {
        // si cambia el nombre o la categoría padre, re-sincronizar
        if (env('USA_TIENDA_NUBE', false)) {
            if ($sub->wasChanged('name') || $sub->wasChanged('category_id')) {
                $svc = app(TiendaNubeCategoryService::class);
                $svc->syncSubCategory($sub);
            }
        }
    }

    public function created(SubCategory $sub): void
    {
        if (env('USA_TIENDA_NUBE', false)) {
            $svc = app(TiendaNubeCategoryService::class);
            $svc->ensureSubCategory($sub, $sub->category);
        }
    }
}
