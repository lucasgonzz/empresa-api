<?php

namespace Tests\Feature\Listado;

use App\Models\Article;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exploración listado/precios (1/9/2026) — invariante I1.
 *
 * Lo que fija, predicho a mano ANTES de correr (ver el informe de la exploración):
 *
 *  - Con margen de ganancia, el precio final SIGUE al costo por el endpoint real de update:
 *    duplicar el costo duplica el precio final (linealidad exacta, inmune a la alícuota de
 *    IIBB vigente y al IVA — los dos son multiplicadores).
 *  - Con precio manual (margen vacío), cambiar el costo NO mueve el precio final. Es la
 *    promesa del manual: "Si el costo cambia → el precio final no se actualiza".
 *
 * El fixture es PROPIO (proveedor y artículos creados acá): los artículos del seeder tienen
 * expectativas absolutas en otros specs (Pinza = 855) y contaminarlos rompe la suite e2e.
 * El proveedor propio no tiene bonificaciones ni margen, así que no ensucia la aritmética.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Precio_final_sigue_al_costo_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $user = User::find(500);

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Alta por el camino del seeder (helper estándar + setFinalPrice), update por el endpoint
     * real: lo que se explora acá es el UPDATE, no el alta.
     *
     * @param \App\Models\User $user
     * @param string $nombre
     * @param array $atributos
     * @return \App\Models\Article
     */
    protected function crear_articulo($user, $nombre, $atributos)
    {
        $provider = Provider::firstOrCreate([
            'name'    => 'zz Proveedor Exploracion',
            'user_id' => $user->id,
        ]);

        $article = Article::create(array_merge([
            'name'        => $nombre,
            'user_id'     => $user->id,
            'provider_id' => $provider->id,
        ], $atributos));

        /*
         * 🔴 El recálculo va sobre el modelo FRESCO, no sobre el recién creado en memoria.
         * `articles.iva_id` y `aplicar_iva` tienen DEFAULT en la base (2 → IVA 21, y 1):
         * el objeto que devuelve create() no los tiene, y setFinalPrice sobre ese objeto
         * calcula un final SIN IVA que el primer recálculo posterior sube 21%. Medido en
         * esta exploración (1546.39 vs 1871.13 para el mismo registro). El mismo patrón
         * vive en ArticleController::newArticle y quedó reportado.
         */
        $article = Article::find($article->id);

        \App\Http\Controllers\Helpers\ArticleHelper::setFinalPrice($article, $user->id);

        return $article->fresh();
    }

    /**
     * El payload mínimo que el update del ArticleController espera: manda TODO el modelo
     * (el formulario real hace eso), así que se parte del artículo actual y se pisa lo que
     * el caso cambia. cost_incluye_iva en 0: el costo viaja neto, sin descomponer.
     *
     * @param \App\Models\Article $article
     * @param array $cambios
     * @return array
     */
    protected function payload_update($article, $cambios)
    {
        /*
         * El formulario real manda el MODELO ENTERO: el update del controller asigna cada
         * campo del request tal cual, y un campo ausente viaja como null — con columnas
         * NOT NULL (online) eso es un 500. getAttributes() da el estado crudo del modelo,
         * que es exactamente lo que la SPA le devuelve al servidor.
         */
        $base = $article->fresh()->getAttributes();

        $base['cost_incluye_iva'] = 0;
        $base['apply_provider_percentage_gain'] = 0;

        /* La SPA siempre manda estos arrays (vacíos si no aplican); los helpers de attach
           hacen foreach sin chequear null. */
        $base['price_types'] = array();
        $base['price_type_monedas'] = array();
        $base['tags'] = array();
        $base['addresses'] = array();

        return array_merge($base, $cambios);
    }

    /**
     * I1a: duplicar el costo por el endpoint real duplica el precio final, exacto.
     *
     * La aserción es la PROPORCIÓN y no un absoluto: el precio final depende de la alícuota
     * de IIBB que tenga la base en ese momento (3.5 sembrada, 3 si un spec la tocó) y del
     * redondeo; la linealidad no depende de ninguno de los dos.
     *
     * @group exploracion-listado
     * @test
     */
    public function duplicar_el_costo_duplica_el_precio_final_con_margen()
    {
        $user = $this->autenticar();

        $article = $this->crear_articulo($user, 'zz Exploracion margen', [
            'cost'            => 1000,
            'percentage_gain' => 50,
        ]);

        $final_previo = (float) $article->final_price;

        $this->assertGreaterThan(
            0,
            $final_previo,
            'El alta no dejó precio final: sin línea de base no se puede medir el update.'
        );

        $response = $this->putJson(
            'api/article/' . $article->id,
            $this->payload_update($article, ['cost' => 2000])
        );

        $response->assertStatus(200);

        $article = $article->fresh();

        $this->assertEquals(2000.0, (float) $article->cost, 'El update no persistió el costo nuevo.');

        $this->assertEqualsWithDelta(
            2.0,
            (float) $article->final_price / $final_previo,
            0.001,
            'Duplicar el costo tenía que duplicar el precio final (margen configurado): '
                . 'quedó ' . $article->final_price . ' contra un previo de ' . $final_previo . '.'
        );
    }

    /**
     * I1b: con precio manual (margen vacío), el costo puede duplicarse y el precio final
     * NO se mueve. Es la promesa del manual, y es la que protege al comerciante que fijó
     * un precio a mano de que una compra le pise la góndola.
     *
     * @group exploracion-listado
     * @test
     */
    public function con_precio_manual_cambiar_el_costo_no_mueve_el_precio_final()
    {
        $user = $this->autenticar();

        $article = $this->crear_articulo($user, 'zz Exploracion manual', [
            'cost'            => 600,
            'percentage_gain' => null,
            'price'           => 999,
        ]);

        $final_previo = (float) $article->final_price;

        $this->assertGreaterThan(
            0,
            $final_previo,
            'El alta con precio manual no dejó precio final.'
        );

        $response = $this->putJson(
            'api/article/' . $article->id,
            $this->payload_update($article, ['cost' => 1200])
        );

        $response->assertStatus(200);

        $article = $article->fresh();

        $this->assertEquals(1200.0, (float) $article->cost, 'El update no persistió el costo nuevo.');

        $this->assertEqualsWithDelta(
            $final_previo,
            (float) $article->final_price,
            0.01,
            'Con precio manual, cambiar el costo NO tiene que tocar el precio final: '
                . 'quedó ' . $article->final_price . ' y tenía que seguir en ' . $final_previo . '.'
        );
    }
}
