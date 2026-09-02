<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 🔴 DEFECTO CONOCIDO, fijado a propósito (exploración 1/9/2026). Reportado, espera
 * decisión de Lucas — toca precios en producción y no se arregla solo.
 *
 * El alta rápida (POST api/article/new-article — el camino del buscador de Vender) calcula
 * el precio final sobre el modelo EN MEMORIA recién guardado, que no tiene los DEFAULTS
 * que la base le acaba de poner a la fila: `articles.iva_id` (default 2 → IVA 21%) y
 * `articles.aplicar_iva` (default 1). ArticlePricesHelper::aplicar_iva() exige la relación
 * `iva` cargada (hasIva), así que en esa primera pasada el IVA NO se aplica.
 *
 * Consecuencia: el artículo nace con un precio final SIN IVA, y el primer recálculo
 * posterior — cualquier guardado del formulario, una masiva, un recálculo por dólar —
 * se lo sube un 21% sin que nadie haya tocado nada. Medido en esta exploración:
 * el mismo registro pasó de 1546.39 (alta) a 1871.13 (recálculo sobre el modelo fresco).
 *
 * Este test fija el comportamiento REAL de hoy: la primera pasada y la segunda difieren
 * exactamente en el factor del IVA. El día que el alta rápida calcule sobre el modelo
 * fresco (o setee los defaults antes de calcular), la aserción de diferencia se pone roja
 * — esa es la señal buscada: actualizar el test para exigir igualdad y cerrar el hallazgo.
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
    public function el_alta_rapida_deja_un_final_que_el_primer_recalculo_sube_un_21_por_ciento()
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
         * 🔴 Comportamiento REAL (defecto): los dos finales difieren exactamente en el
         * factor del IVA del artículo (21%). Cuando el alta rápida se corrija, este ratio
         * va a dar 1.0: cambiar la aserción a assertEqualsWithDelta($final_del_alta,
         * $final_recalculado, 0.01) y cerrar el hallazgo en la bitácora.
         */
        $this->assertEqualsWithDelta(
            1.21,
            $final_recalculado / $final_del_alta,
            0.001,
            'El comportamiento conocido (defectuoso) cambió: si el ratio ya no es 1.21, '
                . 'el alta rápida dejó de calcular sin IVA — actualizar este test para exigir '
                . 'igualdad entre el final del alta y el recalculado.'
        );
    }
}
