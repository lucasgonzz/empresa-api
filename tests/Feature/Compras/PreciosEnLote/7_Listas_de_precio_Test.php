<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Models\PriceType;
use Illuminate\Support\Facades\DB;

/**
 * Una cuenta con listas de precio (misión compras-precios-en-lote, 29/9/2026): el dueño del fixture
 * con `listas_de_precio = 1` y sus cuatro listas sembradas, todas con margen (Distribuidor 5%,
 * Mayorista 10%, Minorista 15%, Tienda Nube 50%).
 *
 * Lo que se mira además de la compra común: los pivots de cada lista (article_price_type) y las
 * filas de lista de cada price_change (price_change_price_type).
 *
 * 🔴 El matiz de D1 en las listas (plan §3, leído en ArticlePricesHelper): cada cálculo pisa
 * article_price_type.previus_final_price con el final_price que la lista tiene EN ESE MOMENTO,
 * cambie o no. Hoy la llamada #3 corre siempre después de la #1, así que el "anterior" de la lista
 * queda con lo que dejó la #1; con un solo cálculo queda con el precio de la lista de ANTES de la
 * compra. Los tres casos de la regla están en la compra:
 *  - listas que ya tenían precio (los dos artículos del catálogo, calculados antes con las listas);
 *  - una lista con el precio puesto a mano (setear_precio_final): el "anterior" queda en null en
 *    los dos caminos;
 *  - un artículo sin ninguna lista atada (creado con las listas apagadas): el primer cálculo las
 *    ata, y con el motor su "anterior" es null.
 *
 * Con las bonificaciones de Buenos Aires del fixture, así la #3 mueve el precio (caso D1).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Listas_de_precio_Test extends ComprasPreciosEnLoteTestCase
{
    /**
     * @group compras
     * @test
     */
    public function cuenta_con_listas_de_precio()
    {
        $this->set_condicion_iva('RRII');

        $dueno = $this->dueno();

        $listas = PriceType::where('user_id', $dueno->id)->orderBy('position')->get();

        $this->assertGreaterThanOrEqual(2, $listas->filter(function ($lista) {
            return (float) $lista->percentage > 0;
        })->count(), 'Guarda: el fixture tenía que traer al menos dos listas con margen.');

        /* Un artículo sin listas atadas: se crea (y se calcula) ANTES de prender las listas. */
        $sin_listas = $this->crear_articulo(['cost' => 900]);

        $this->assertSame(0, DB::table('article_price_type')->where('article_id', $sin_listas->id)->count(), 'Guarda: el artículo nuevo no tenía que tener listas atadas.');

        DB::table('users')->where('id', $dueno->id)->update(['listas_de_precio' => 1]);

        $this->assertTrue($this->la_cuenta_calcula_listas(), 'Guarda: la cuenta tenía que quedar calculando listas.');

        $pinza   = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');

        /* Una lista con el precio puesto a mano en la Pinza. */
        $lista_a_mano = $listas[1];

        DB::table('article_price_type')
            ->where('article_id', $pinza->id)
            ->where('price_type_id', $lista_a_mano->id)
            ->update(['setear_precio_final' => 1, 'final_price' => 7777]);

        /* Las listas de los artículos del catálogo, calculadas antes de la compra. */
        $this->calcular_precio($pinza);
        $this->calcular_precio($alicate);

        $this->assertSame(0, DB::table('article_price_type')->where('article_id', $alicate->id)->whereNull('final_price')->count(), 'Guarda: las listas del catálogo tenían que tener precio antes de la compra.');

        $r = $this->dos_caminos(function () use ($pinza, $alicate, $sin_listas) {

            $compra_id = $this->alta($this->payload_compra([
                'articles' => [
                    $this->renglon($pinza, 1130, 10),
                    $this->renglon($alicate, 335, 6),
                    $this->renglon($sin_listas, 950, 2),
                ],
            ]));

            return [
                'articulos' => [$pinza->id, $alicate->id, $sin_listas->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('cuenta con listas de precio', $r);

        $this->assert_hubo_caso_d1($r, 2);

        $cantidad_de_listas = count($listas);

        $this->assertCount($cantidad_de_listas * 3, $r['motor']['pivots'], 'Guarda: los tres artículos tenían que quedar con una fila por lista (el nuevo, recién atado).');

        foreach ($r['motor']['cambios'] as $article_id => $cambios) {
            $this->assertCount($cantidad_de_listas, $cambios[0]['listas'], 'Guarda: el cambio de precio del artículo ' . $article_id . ' tenía que llevar sus listas.');
        }

        $pivot_a_mano = $this->pivot($r['motor'], $pinza->id, $lista_a_mano->id);

        $this->assertSame('7777.00', $pivot_a_mano['final_price'], 'Guarda: la lista con precio a mano conserva su precio.');
        $this->assertNull($pivot_a_mano['previus_final_price'], 'La lista con precio a mano no registra "anterior" (en ningún camino).');

        /* El matiz de D1 en las listas tiene que haber aparecido: si no, la regla no se probó. */
        $otra_lista = $listas[0];

        $this->assertNotSame(
            $this->pivot($r['hoy'], $alicate->id, $otra_lista->id)['previus_final_price'],
            $this->pivot($r['motor'], $alicate->id, $otra_lista->id)['previus_final_price'],
            'Guarda: el "anterior" de la lista tenía que diferir entre los dos caminos (hoy, el de la #1; con el motor, el de antes de la compra).'
        );

        $this->assertNull($this->pivot($r['motor'], $sin_listas->id, $otra_lista->id)['previus_final_price'], 'Con el motor, la lista recién atada no tiene "anterior".');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * La fila de article_price_type de un par (artículo, lista) en una foto.
     *
     * @param  array $foto
     * @param  int   $article_id
     * @param  int   $price_type_id
     * @return array
     */
    protected function pivot(array $foto, $article_id, $price_type_id)
    {
        foreach ($foto['pivots'] as $fila) {
            if ($fila['article_id'] === (string) $article_id && $fila['price_type_id'] === (string) $price_type_id) {
                return $fila;
            }
        }

        $this->fail('No hay fila de lista ' . $price_type_id . ' para el artículo ' . $article_id . '.');
    }
}
