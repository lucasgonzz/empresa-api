<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\Helpers\ExportHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\ArticleSurchage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las cuatro columnas de descuentos y recargos del Excel de artículos.
 *
 * El Excel exportado se vuelve a importar: lo que sale de acá tiene que poder
 * entrar de nuevo sin cambiar nada. Dos defectos medidos el 6/9/2026 en el cliente
 * Innovate rompían justamente eso:
 *
 *   - La celda mezclaba los dos tipos: un artículo con 10% y $500 exportaba
 *     "10.00_" en Recargos y "_500" en Recargos montos, porque el bucle
 *     concatenaba ->percentage de TODOS los recargos, incluido el de monto
 *     (que lo tiene en null).
 *   - No escribía la 'F' de luego_del_precio_final, así que reimportar movía
 *     todos los recargos al costo y cambiaba los precios.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Export_De_Descuentos_Y_Recargos_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::orderBy('id', 'ASC')->first();

        $this->assertNotNull($this->user, 'La base de tests no tiene ningún usuario.');
    }

    /**
     * Crea un artículo del tenant de prueba.
     *
     * @param  string $nombre
     * @return \App\Models\Article
     */
    private function crear_articulo($nombre)
    {
        return Article::create([
            'user_id' => $this->user->id,
            'name'    => $nombre,
            'cost'    => 100,
            'status'  => 'active',
        ]);
    }

    /**
     * Pasa el artículo por el formateo del export y lo devuelve con las columnas puestas.
     *
     * @param  \App\Models\Article $article
     * @return \App\Models\Article
     */
    private function formatear($article)
    {
        $article->load(
            'article_discounts',
            'article_surchages',
            'article_discounts_blanco',
            'article_surchages_blanco'
        );

        return ExportHelper::set_descuentos_y_recargos(collect([$article]))->first();
    }

    /**
     * Un recargo porcentual y uno de monto no se pisan entre sí: cada uno va a su
     * columna, y ninguno deja el guión bajo del otro colgando.
     *
     * @test
     */
    public function el_porcentaje_y_el_monto_van_cada_uno_a_su_columna()
    {
        $article = $this->crear_articulo('Art export recargos separados');

        ArticleSurchage::create(['article_id' => $article->id, 'percentage' => 10, 'amount' => null, 'luego_del_precio_final' => 0]);
        ArticleSurchage::create(['article_id' => $article->id, 'percentage' => null, 'amount' => 500, 'luego_del_precio_final' => 0]);

        $exportado = $this->formatear($article);

        $this->assertSame('10.00', $exportado->surchages_percentage_formated);
        $this->assertSame('500', $exportado->surchages_amount_formated);
    }

    /**
     * El recargo que se aplica después del precio final sale con 'F' al final del
     * número, que es lo que el importador vuelve a leer.
     *
     * @test
     */
    public function el_recargo_despues_del_precio_final_sale_con_f()
    {
        $article = $this->crear_articulo('Art export recargos con F');

        ArticleSurchage::create(['article_id' => $article->id, 'percentage' => 30, 'amount' => null, 'luego_del_precio_final' => 1]);
        ArticleSurchage::create(['article_id' => $article->id, 'percentage' => null, 'amount' => 1500, 'luego_del_precio_final' => 1]);

        $exportado = $this->formatear($article);

        $this->assertSame('30.00F', $exportado->surchages_percentage_formated);
        $this->assertSame('1500F', $exportado->surchages_amount_formated);
    }

    /**
     * Varios recargos del mismo tipo se separan con guión bajo y cada uno lleva su
     * propia marca de "después del precio final".
     *
     * @test
     */
    public function varios_recargos_se_separan_con_guion_bajo()
    {
        $article = $this->crear_articulo('Art export varios recargos');

        ArticleSurchage::create(['article_id' => $article->id, 'percentage' => 5, 'amount' => null, 'luego_del_precio_final' => 0]);
        ArticleSurchage::create(['article_id' => $article->id, 'percentage' => 12, 'amount' => null, 'luego_del_precio_final' => 1]);

        $exportado = $this->formatear($article);

        $this->assertSame('5.00_12.00F', $exportado->surchages_percentage_formated);
        $this->assertSame('', $exportado->surchages_amount_formated);
    }

    /**
     * Los descuentos tienen el mismo problema de mezcla, aunque no lleven 'F'.
     *
     * @test
     */
    public function los_descuentos_tambien_separan_porcentaje_de_monto()
    {
        $article = $this->crear_articulo('Art export descuentos separados');

        ArticleDiscount::create(['article_id' => $article->id, 'percentage' => 15, 'amount' => null]);
        ArticleDiscount::create(['article_id' => $article->id, 'percentage' => null, 'amount' => 200]);

        $exportado = $this->formatear($article);

        $this->assertSame('15.00', $exportado->discounts_percentage_formated);
        $this->assertSame('200', $exportado->discounts_amount_formated);
    }

    /**
     * Un artículo sin descuentos ni recargos deja las cuatro columnas vacías, no en null:
     * una celda null y una celda vacía se leen distinto al reimportar.
     *
     * @test
     */
    public function un_articulo_sin_nada_deja_las_columnas_vacias()
    {
        $article = $this->crear_articulo('Art export sin recargos');

        $exportado = $this->formatear($article);

        $this->assertSame('', $exportado->discounts_percentage_formated);
        $this->assertSame('', $exportado->discounts_amount_formated);
        $this->assertSame('', $exportado->surchages_percentage_formated);
        $this->assertSame('', $exportado->surchages_amount_formated);
    }

    /**
     * Las columnas de precios en blanco las consume mapPreciosBlanco() con los nombres
     * discounts_blanco_formated / surchages_blanco_formated. Si no se setean, esas dos
     * columnas del Excel salen vacías para todos los clientes con la extensión.
     *
     * @test
     */
    public function las_columnas_en_blanco_quedan_seteadas()
    {
        $article = $this->crear_articulo('Art export en blanco');

        $exportado = $this->formatear($article);

        $this->assertSame('', $exportado->discounts_blanco_formated);
        $this->assertSame('', $exportado->surchages_blanco_formated);
    }
}
