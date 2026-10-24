<?php

namespace Tests\Feature\SucursalSiempre;

use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Models\Address;
use App\Models\ArticleVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Stock\AuditoriaStockTestCase;

/**
 * Base de los tests de la misión sucursal-siempre (23/10/2026): todo negocio trabaja con al menos una
 * sucursal, y la sucursal PRINCIPAL es la que usa toda operación que no dice cuál.
 *
 * Reusa los helpers de la auditoría de stock (artículos propios con prefijo "zz", la venta con la
 * forma exacta de Vender por `POST api/sale`, el libro de movimientos). El fixture
 * (TestingFerreteriaSeeder) le da al dueño 500 UNA sola sucursal, "Principal": los tests de "una sola
 * sucursal" la usan tal cual, y los de "dos o más" crean otra con `otra_sucursal()`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
abstract class SucursalSiempreTestCase extends AuditoriaStockTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El memo de sucursales vivas es por proceso: entre test y test, limpio.
        SucursalVigenteHelper::olvidar();
    }

    /**
     * La principal del dueño del fixture (marcada si la migración de datos ya corrió; si no, la de
     * menor id, que es la que marcaría).
     *
     * @return \App\Models\Address
     */
    protected function principal()
    {
        $principal = Address::principal_de($this->usuario()->id);

        $this->assertNotNull($principal, 'El fixture no tiene ninguna sucursal.');

        return $principal;
    }

    /**
     * Una sucursal más del dueño del fixture.
     *
     * @param  string  $nombre
     * @return \App\Models\Address
     */
    protected function otra_sucursal($nombre = 'zz Sucursal B')
    {
        return Address::create([
            'street'          => $nombre,
            'user_id'         => $this->usuario()->id,
            'default_address' => 0,
        ]);
    }

    /**
     * Le abre al artículo su fila en una sucursal (como el motor: attach, sin timestamps) y deja el
     * global como la suma, igual que lo dejaría el motor.
     *
     * @param  \App\Models\Article  $articulo
     * @param  int                  $address_id
     * @param  float                $cantidad
     * @return void
     */
    protected function con_fila($articulo, $address_id, $cantidad)
    {
        $articulo->addresses()->attach($address_id, ['amount' => $cantidad]);

        DB::table('articles')->where('id', $articulo->id)->update([
            'stock' => DB::raw('(SELECT COALESCE(SUM(amount), 0) FROM address_article WHERE article_id = '.(int) $articulo->id.')'),
        ]);
    }

    /**
     * @param  \App\Models\Article  $articulo
     * @param  float|null           $stock
     * @param  string               $descripcion
     * @return \App\Models\ArticleVariant
     */
    protected function variante($articulo, $stock = null, $descripcion = null)
    {
        return ArticleVariant::create([
            'article_id'          => $articulo->id,
            'variant_description' => is_null($descripcion) ? 'zz Variante '.uniqid() : $descripcion,
            'stock'               => $stock,
        ]);
    }

    /**
     * @param  \App\Models\ArticleVariant  $variante
     * @param  int                         $address_id
     * @return float|null  null si la variante no tiene fila en esa sucursal.
     */
    protected function stock_de_variante_en($variante, $address_id)
    {
        $fila = DB::table('address_article_variant')->where('article_variant_id', $variante->id)->where('address_id', $address_id)->first();

        return is_null($fila) ? null : (float) $fila->amount;
    }

    /**
     * @param  \App\Models\ArticleVariant  $variante
     * @return float|null
     */
    protected function stock_de_variante($variante)
    {
        $stock = DB::table('article_variants')->where('id', $variante->id)->value('stock');

        return is_null($stock) ? null : (float) $stock;
    }

    /**
     * Un dueño propio del test (otro comercio en la misma base), sin sucursales.
     *
     * @param  string  $etiqueta
     * @return \App\Models\User
     */
    protected function dueno_nuevo($etiqueta = 'dueno')
    {
        return User::create([
            'name'     => 'zz Dueño sucursal-siempre '.$etiqueta,
            'email'    => 'zz-sucursal-siempre-'.$etiqueta.'-'.uniqid().'@test.local',
            'password' => Hash::make(uniqid('', true)),
        ]);
    }
}
