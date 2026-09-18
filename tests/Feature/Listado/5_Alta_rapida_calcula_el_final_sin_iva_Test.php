<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 🔴 HALLAZGO CERRADO el 10/9/2026 por la misión `precio-manual-no-suma-iva` — este test
 * mismo lo predijo: "el día que [...] la aserción de diferencia se pone roja [...]
 * actualizar el test para exigir igualdad". Eso pasó, y esto es esa actualización.
 *
 * Historia (exploración 1/9/2026, degradado a latente el 2/9/2026): el payload de este
 * test (`price: 999`, sin costo) crea un artículo con PRECIO MANUAL. El alta rápida
 * (POST api/article/new-article) calcula el precio final sobre el modelo EN MEMORIA recién
 * guardado, sin la relación `iva` cargada todavía, así que en esa primera pasada
 * ArticlePricesHelper::aplicar_iva() no encontraba `hasIva()` y NO sumaba el 21%. El primer
 * recálculo posterior (sobre el modelo fresco, con la relación cargada) SÍ se lo sumaba
 * sobre ese mismo precio manual — y ahí es donde entra la misión `precio-manual-no-suma-iva`:
 * un precio manual ya no recibe IVA en NINGÚN recálculo, así que la segunda pasada deja de
 * diferir de la primera. El ratio pasó de 1.21 a 1.0 (confirmado corriendo este test).
 *
 * 🔴 NO CONFUNDIR con el mecanismo de fondo que este test documentaba ("calcular sobre el
 * modelo en memoria sin los defaults que la base acaba de escribir"): ese mecanismo sigue
 * ahí y no se tocó. Lo que cambió es que, para un PRECIO MANUAL, ya no importa si el IVA se
 * aplicó o no en la primera pasada, porque ninguna pasada posterior se lo suma. Un alta
 * rápida con MARGEN (percentage_gain) en vez de precio manual podría seguir reproduciendo el
 * mecanismo original — no se verificó, porque ese camino queda fuera de esta misión y sigue
 * siendo el mismo defecto latente e inalcanzable por la interfaz que ya estaba documentado.
 *
 * El resto de este comentario, histórico, sigue siendo cierto sobre POR QUÉ el endpoint está
 * vivo del lado del servidor pero inalcanzable desde la SPA:
 *
 * El único caller de `article/new-article` en empresa-spa es el modal
 * `vender/modals/NewArticle.vue`, y ese modal está en un CICLO CERRADO sin entrada:
 *
 *   setNewArticle() (abre el modal)  ← sólo lo llama checkRegister() (mixins/vender.js:724,727)
 *   checkRegister()                  ← sólo lo llama setVenderArticle() (vender.js:569)
 *   setVenderArticle()               ← sólo lo llama NewArticle.vue:58, o sea el propio modal
 *
 * Nadie más en `src/` llama a ninguno de los tres (verificado con grep sobre todo src/),
 * así que el modal no se abre nunca y el endpoint no lo ejercita ninguna pantalla.
 *
 * El camino VIVO de alta rápida en Vender es otro: el doble Enter del buscador por nombre,
 * que va a `POST search/save-if-not-exist/article/name/{query}`
 * (common-vue/components/search/Modal.vue:1334) y crea el artículo **sin precio y sin
 * costo** — por eso queda con el precio vacío en el listado, y por eso hay que ponerle
 * precio personalizado en cada venta. Ese camino NO dispara este defecto: sin cost ni
 * price, setFinalPrice corta en su guardia inicial y no calcula nada.
 *
 * Este test se conserva porque el endpoint sigue vivo del lado del servidor (una app
 * móvil, una integración o un caller futuro lo pueden usar).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Alta_rapida_calcula_el_final_sin_iva_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @group exploracion-listado
     * @test
     */
    public function el_alta_rapida_con_precio_manual_deja_el_mismo_final_que_cualquier_recalculo()
    {
        $user = User::find(500);

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        $response = $this->postJson('api/article/new-article', [
            'name'     => 'zz Exploracion alta rapida',
            'price'    => 999,
            'bar_code' => '',
        ]);

        $this->assertTrue(
            $response->status() >= 200 && $response->status() < 300,
            'El alta rápida respondió ' . $response->status() . '.'
        );

        $article = Article::where('name', 'zz Exploracion alta rapida')
            ->where('user_id', $user->id)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($article, 'El alta rápida no dejó el artículo.');

        /* La fila SÍ quedó con los defaults de la base: IVA cargado y aplicar_iva prendido. */
        $this->assertNotNull($article->iva_id, 'La fila tenía que nacer con el iva_id default de la base.');
        $this->assertEquals(1, (int) $article->aplicar_iva, 'La fila tenía que nacer con aplicar_iva = 1.');

        $final_del_alta = (float) $article->final_price;

        /* El recálculo sobre el modelo FRESCO — lo que hace cualquier guardado posterior. */
        $fresco = Article::find($article->id);
        ArticleHelper::setFinalPrice($fresco, $user->id);

        $final_recalculado = (float) Article::find($article->id)->final_price;

        /*
         * Cerrado por la misión `precio-manual-no-suma-iva` (10/9/2026): el precio de este
         * artículo es MANUAL (price: 999, sin costo), así que ningún recálculo posterior le
         * suma IVA por encima, sin importar si la primera pasada lo aplicó o no. Antes de esta
         * misión este ratio daba 1.21 (ver el docblock de la clase).
         */
        $this->assertEqualsWithDelta(
            $final_del_alta,
            $final_recalculado,
            0.01,
            'un precio manual tiene que quedar igual entre el alta y cualquier recálculo posterior'
        );
    }
}
