<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una OFERTA POR CANTIDAD de un articulo: a partir de tantas unidades, tal precio.
 *
 * El nombre de la clase y de la tabla dicen "price range" porque nacieron asi (migracion
 * `2025_11_04_123716`), pero desde la mision oferta-por-cantidad-porcentaje (24/9/2026) lo que el
 * comerciante ve en el ABM se llama **"Oferta por cantidad"**. No se renombro la tabla a proposito:
 * la lee `tienda-api`, que se despliega a mano y por separado, y un rename la dejaria ciega.
 *
 * Columnas: `article_id`, `modo` ('Igual' | 'Mayor o igual'), `amount` decimal(10,2),
 * `price` decimal(20,2) NULLABLE, `porcentaje` decimal(8,2) NULLABLE.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * 🔴 `price` Y `porcentaje` SON EXCLUYENTES
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 *   - `price`      -> precio unitario FIJO Y ABSOLUTO. Cortocircuita la cadena normal de precios
 *                     (listas, recargos, descuentos por medio de pago). Se asume CON IVA.
 *   - `porcentaje` -> descuento sobre el precio que la linea IBA A TENER. Es el que hace que la
 *                     oferta siga al precio: si el comercio cambia el precio del articulo, el de
 *                     la oferta cambia solo.
 *
 * Quien decide cual de los dos manda es `CriterioDeOfertaPorCantidadHelper`, UNA sola vez y en un
 * solo lugar, y el controller normaliza el par al guardar: el que pierde se persiste en `null`.
 * **Nunca preguntes por estas dos columnas con un `if` propio** — esa es exactamente la forma en
 * que el bug hermano (margen de ganancia vs precio manual) dejo articulos sin ninguna manera de
 * cambiarles el precio desde la interfaz.
 *
 * ⚠️ QUIEN APLICA ESTO. `empresa-api` PERSISTE la oferta pero no la aplica al vender: el precio de
 * la linea lo resuelve el SPA (`empresa-spa/src/mixins/vender/article_price_range.js` y
 * `getPriceVender()`). En la tienda si la aplica el servidor, en
 * `tienda-api/.../ArticlePriceRangeHelper`. Son cuatro implementaciones del mismo criterio y
 * tienen que coincidir borde por borde.
 */
class ArticlePriceRange extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {

    }
}
