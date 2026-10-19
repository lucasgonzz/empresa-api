<?php

namespace App\Services\ProductFacts;

use App\Models\Brand;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Persistencia exclusiva del alta confirmada. Las ediciones locales jamás se envían al catálogo común. */
class ApplyProductFacts
{
    public static function validate(Request $request, $ownerId)
    {
        if (!$request->has('product_facts_apply')) {
            return;
        }
        $request->validate([
            'product_facts_apply' => 'array',
            'product_facts_apply.brand_name' => 'nullable|string|max:190',
            'product_facts_apply.description' => 'nullable|string|max:6500',
            'product_facts_apply.sources' => 'nullable|array|max:4',
            'product_facts_apply.sources.*.url' => 'required|url|max:2000|regex:#^https?://#i',
            'product_facts_apply.sources.*.title' => 'nullable|string|max:255',
            'peso' => 'nullable|numeric|min:0|max:100000', 'alto' => 'nullable|numeric|min:0|max:100000',
            'ancho' => 'nullable|numeric|min:0|max:100000', 'profundidad' => 'nullable|numeric|min:0|max:100000',
        ]);
        if ($request->category_id && !Category::where('user_id', $ownerId)->where('id', $request->category_id)->exists()) {
            throw ValidationException::withMessages(['category_id' => 'La categoría no pertenece a tu comercio.']);
        }
        if ($request->sub_category_id && !SubCategory::where('user_id', $ownerId)->where('category_id', $request->category_id)->where('id', $request->sub_category_id)->exists()) {
            throw ValidationException::withMessages(['sub_category_id' => 'La subcategoría no pertenece a esa categoría.']);
        }
        if ($request->brand_id && !Brand::where('user_id', $ownerId)->where('id', $request->brand_id)->exists()) {
            throw ValidationException::withMessages(['brand_id' => 'La marca no pertenece a tu comercio.']);
        }
    }

    public static function brand(Request $request, $ownerId)
    {
        $name = trim((string) $request->input('product_facts_apply.brand_name', ''));
        if ($name === '' || $request->brand_id) {
            return $request->brand_id;
        }
        return Brand::firstOrCreate(['user_id' => $ownerId, 'name' => $name])->id;
    }

    public static function description(Request $request, $article)
    {
        $text = trim((string) $request->input('product_facts_apply.description', ''));
        if ($text === '') {
            return;
        }
        $description = $article->descriptions()->create([
            'title' => 'Información del producto',
            'content' => '<p>' . nl2br(e($text)) . '</p>',
            'ai_generated' => true, 'ai_confidence' => 'high', 'ai_reviewed_at' => now(),
            'ai_sources' => $request->input('product_facts_apply.sources', []),
        ]);
        (new \App\Http\Controllers\DescriptionController())->check_tienda_nube($description);
    }
}
