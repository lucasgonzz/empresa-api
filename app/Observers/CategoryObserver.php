<?php

namespace App\Observers;

use App\Models\Category;
use App\Services\TiendaNube\TiendaNubeCategoryService;

class CategoryObserver
{
    public function updated(Category $category): void
    {

        if (env('USA_TIENDA_NUBE', false)) {
            // solo si cambió el nombre (o si cambiás la condición, ej. slug)
            if ($category->wasChanged('name')) {
                $svc = app(TiendaNubeCategoryService::class);
                $svc->syncRootCategory($category);
            }
        }
    }

    // Opcional: al crear, asegurarla ya en TN
    public function created(Category $category): void
    {
        if (env('USA_TIENDA_NUBE', false)) {
            $svc = app(TiendaNubeCategoryService::class);
            $svc->ensureRootCategory($category);
        }
    }
}
