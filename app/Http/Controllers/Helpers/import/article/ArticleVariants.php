<?php

namespace App\Http\Controllers\Helpers\import\article;

class ProcessRow {
	
	protected function expandir_combinaciones_de_variantes(): void
	{
	    // Trabajamos sobre ambos buckets del cache (crear/actualizar)
	    foreach (['articulos_para_crear_CACHE', 'articulos_para_actualizar_CACHE'] as $bucket) {
	        if (!isset($this->{$bucket}) || !is_array($this->{$bucket})) continue;

	        foreach ($this->{$bucket} as $idx => $artCache) {
	            // Soportar tanto 'variants_data' como 'VariantData'
	            $variants_key = isset($artCache['variants_data']) ? 'variants_data' : (isset($artCache['VariantData']) ? 'VariantData' : null);
	            if ($variants_key === null) continue;

	            $variants = $artCache[$variants_key] ?? [];
	            if (empty($variants)) continue;

	            // 1) Construir el set por tipo de propiedad con TODOS los valores detectados
	            //    Ej: ['color' => ['rojo','negro'], 'talle' => ['40','41','42']]
	            $map = $this->construir_mapa_propiedades_y_valores($variants);

	            if (empty($map)) continue; // no hay props válidas

	            // 2) Generar producto cartesiano con TODOS los valores
	            //    Devuelve una lista de combinaciones como arrays asociativos
	            $combos = $this->cartesiano_assoc($map);
	            if (empty($combos)) continue;

	            // 3) Normalizar variantes ya existentes (por si vinieron desde el Excel explícitas)
	            $existing_signatures = [];
	            $unificados = [];

	            // a) agregar las variantes ya presentes (respetar sus extra fields si los tienen)
	            foreach ($variants as $v) {
	                if (!isset($v['properties']) || !is_array($v['properties'])) continue;
	                $sig = $this->firma_combinacion_por_nombre($v['properties']); // p.ej. 'color:rojo|talle:42'
	                if (!isset($existing_signatures[$sig])) {
	                    $existing_signatures[$sig] = true;
	                    $unificados[] = $v; // mantener extras: price, stock, sku, image_url, etc.
	                }
	            }

	            // b) agregar las combinaciones faltantes (sin duplicar)
	            foreach ($combos as $comboProps) {
	                $sig = $this->firma_combinacion_por_nombre($comboProps);
	                if (!isset($existing_signatures[$sig])) {
	                    $existing_signatures[$sig] = true;
	                    $unificados[] = [
	                        'properties' => $comboProps,
	                        // sin price → que herede del artículo en guardar_variantes()
	                        'price'      => null,
	                        'stock'      => null,
	                        'sku'        => null,
	                        'image_url'  => null,
	                    ];
	                }
	            }

	            // 4) Reemplazar en el cache del artículo con la lista unificada
	            $this->{$bucket}[$idx][$variants_key] = $unificados;
	        }
	    }
	}

	/**
	 * Construye el mapa tipo -> set de valores (en minúsculas para normalizar)
	 * a partir de la lista de variantes ya detectadas en cache.
	 * Cada variante trae: ['properties' => ['color'=>'Rojo','talle'=>'42'], ...]
	 */
	protected function construir_mapa_propiedades_y_valores(array $variants): array
	{
	    $map = []; // ['color' => ['rojo'=>true, 'negro'=>true], 'talle' => ['40'=>true,...]]

	    foreach ($variants as $v) {
	        if (!isset($v['properties']) || !is_array($v['properties'])) continue;
	        foreach ($v['properties'] as $typeName => $valueText) {
	            $t = mb_strtolower(trim((string)$typeName));
	            $val = mb_strtolower(trim((string)$valueText));
	            if ($t === '' || $val === '') continue;
	            if (!isset($map[$t])) $map[$t] = [];
	            $map[$t][$val] = true; // set
	        }
	    }

	    // pasar de set => lista ordenada (orden estable por nombre de tipo y valor)
	    ksort($map);
	    foreach ($map as $t => $set) {
	        $vals = array_keys($set);
	        sort($vals, SORT_NATURAL);
	        $map[$t] = $vals;
	    }

	    return $map;
	}

	/**
	 * Producto cartesiano con preservación de claves de propiedad.
	 * $map = ['color'=>['rojo','negro'],'talle'=>['40','41']]
	 * => [
	 *   ['color'=>'rojo','talle'=>'40'],
	 *   ['color'=>'rojo','talle'=>'41'],
	 *   ['color'=>'negro','talle'=>'40'],
	 *   ['color'=>'negro','talle'=>'41'],
	 * ]
	 */
	protected function cartesiano_assoc(array $map): array
	{
	    $result = [[]];

	    foreach ($map as $prop => $values) {
	        $new = [];
	        foreach ($result as $partial) {
	            foreach ($values as $v) {
	                $tmp = $partial;
	                $tmp[$prop] = $v;
	                $new[] = $tmp;
	            }
	        }
	        $result = $new;
	    }
	    return $result;
	}

	/**
	 * Firma determinística por NOMBRE de tipo y valor (minúsculas, ordenada por tipo)
	 * Para deduplicar en cache sin ir a la BD.
	 */
	protected function firma_combinacion_por_nombre(array $props): string
	{
	    $norm = [];
	    foreach ($props as $k => $v) {
	        $kk = mb_strtolower(trim((string)$k));
	        $vv = mb_strtolower(trim((string)$v));
	        if ($kk === '' || $vv === '') continue;
	        $norm[$kk] = $vv;
	    }
	    ksort($norm);
	    $parts = [];
	    foreach ($norm as $k => $v) $parts[] = "{$k}:{$v}";
	    return implode('|', $parts);
	}

}