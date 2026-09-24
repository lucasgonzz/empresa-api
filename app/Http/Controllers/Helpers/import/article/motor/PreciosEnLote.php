<?php

namespace App\Http\Controllers\Helpers\import\article\motor;

/**
 * Modo "lote" del cálculo de precios finales de la importación de artículos.
 *
 * Esqueleto compartido (misión importacion-excel-motor-rapido, 24/9/2026): lo rellena el
 * constructor de precios (B2). El constructor de escritura (B1) ya lo invoca desde
 * ActualizarBBDD::set_precios_finales() con este contrato:
 *
 *   PreciosEnLote::activar($user, $auth_user_id);
 *   try { ... ArticleHelper::setFinalPrice() por artículo ... } finally { PreciosEnLote::volcar(); }
 *
 * Mientras es un esqueleto, activar()/volcar() no hacen nada y el comportamiento es exactamente el
 * de hoy: los pivots de listas y los cambios de precio se escriben uno por uno adentro de
 * setFinalPrice(). Cuando B2 lo complete, en modo lote esas escrituras se recolectan y volcar()
 * las hace en bloque, con el mismo resultado en la base.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class PreciosEnLote
{
    /**
     * Relaciones que ArticleHelper::setFinalPrice() y ArticlePricesHelper cargan por artículo.
     * ActualizarBBDD relee los artículos del lote con `with(self::RELACIONES_A_PRECARGAR)` para que
     * el cálculo no dispare una consulta por artículo. B2 la completa si encuentra más.
     *
     * @var array
     */
    const RELACIONES_A_PRECARGAR = [
        'article_discounts',
        'article_surchages',
        'provider',
        'provider_price_list',
        'category',
        'iva',
        'price_types',
    ];

    /** @var bool */
    protected static $activo = false;

    /**
     * Enciende el modo lote para el proceso actual.
     *
     * @param  \App\Models\User|null $user
     * @param  int|null              $auth_user_id
     * @return void
     */
    public static function activar($user = null, $auth_user_id = null)
    {
        self::$activo = true;
    }

    /**
     * ¿Está encendido el modo lote?
     *
     * @return bool
     */
    public static function esta_activo()
    {
        return self::$activo;
    }

    /**
     * Escribe en bloque lo recolectado y apaga el modo lote. Idempotente: llamarlo sin nada
     * recolectado no hace nada.
     *
     * @return void
     */
    public static function volcar()
    {
        self::$activo = false;
    }
}
