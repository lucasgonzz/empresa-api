<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AdjuntosIaHelper;
use App\Models\Article;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-omnisciente — bloque C1: `AdjuntosIaHelper::imagenes_de_articulos()`, el
 * tool_result de `mostrar_imagenes_de_articulos` (contrato §3).
 *
 * Lo que protege: que la foto que viaja sea la del artículo DEL DUEÑO (un id ajeno no aparece en
 * ninguna lista, ni para decir que no tiene foto), que un artículo sin foto quede en `sin_imagen`,
 * que el tope de seis recorte y lo diga en `como_sigo`, que los pausados entren y los borrados no,
 * y que el orden sea el que pidió el modelo.
 *
 * 🔴 Las fotos del fixture se siembran con `imageable_type = 'article'`, el alias del morph map
 * (`Relation::enforceMorphMap` en AppServiceProvider): con la clase, la fila se inserta igual y la
 * relación devuelve vacío en silencio (hallazgo del 16/9, informe agente-ia-mano-derecha).
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 *
 * @group chat-ia
 */
class Imagenes_de_articulos_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio fotos C1',
            'company_name' => 'Ferreteria fotos C1',
            'email'        => 'fotos-c1-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'         => 'Otro comercio fotos C1',
            'company_name' => 'Ajeno fotos C1',
            'email'        => 'fotos-c1-ajeno-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Un artículo, con o sin foto.
     *
     * @param  string  $nombre
     * @param  string|null  $url
     * @param  User|null  $dueno
     * @param  string  $status
     * @return Article
     */
    protected function articulo($nombre, $url = null, $dueno = null, $status = 'active')
    {
        $dueno = is_null($dueno) ? $this->comercio : $dueno;

        $article = Article::create(['name' => $nombre, 'user_id' => $dueno->id, 'status' => $status]);

        if (!is_null($url)) {
            Image::create(['hosting_url' => $url, 'imageable_type' => 'article', 'imageable_id' => $article->id]);
        }

        return $article;
    }

    /**
     * El caso normal: un artículo con foto sale como adjunto, con la URL de `getFirstImage` y el
     * nombre de epígrafe; uno sin foto queda en `sin_imagen` y no genera adjunto.
     *
     * @test
     */
    public function con_imagen_sale_como_adjunto_y_sin_imagen_queda_en_su_lista()
    {
        $con = $this->articulo('Martillo con foto', 'https://fotos.test/martillo.jpg');
        $sin = $this->articulo('Tarugo sin foto');

        $resultado = AdjuntosIaHelper::imagenes_de_articulos($this->comercio->id, [$con->id, $sin->id]);

        $this->assertSame(['articulos', 'sin_imagen', 'adjuntos_de_la_respuesta', 'como_sigo'], array_keys($resultado));

        $this->assertSame([
            ['articulo_id' => $con->id, 'nombre' => 'Martillo con foto', 'tiene_imagen' => true],
            ['articulo_id' => $sin->id, 'nombre' => 'Tarugo sin foto', 'tiene_imagen' => false],
        ], $resultado['articulos']);

        $this->assertSame([['articulo_id' => $sin->id, 'nombre' => 'Tarugo sin foto']], $resultado['sin_imagen']);

        $this->assertSame([
            ['tipo' => 'imagen', 'url' => 'https://fotos.test/martillo.jpg', 'texto' => 'Martillo con foto', 'articulo_id' => $con->id],
        ], $resultado['adjuntos_de_la_respuesta']);

        $this->assertStringContainsString('no pongas la URL en el texto', $resultado['como_sigo']);
        $this->assertStringContainsString('sin_imagen', $resultado['como_sigo']);
    }

    /**
     * 🔴 Un id de otro comercio no aparece en NINGUNA de las tres listas: ni como adjunto ni como
     * "sin imagen". Mismo criterio que la ficha de hover.
     *
     * @test
     */
    public function un_id_ajeno_no_aparece_en_ninguna_lista()
    {
        $propio = $this->articulo('Propio con foto', 'https://fotos.test/propio.jpg');
        $ajeno = $this->articulo('Ajeno con foto', 'https://fotos.test/ajeno.jpg', $this->otro_comercio);

        $resultado = AdjuntosIaHelper::imagenes_de_articulos($this->comercio->id, [$ajeno->id, $propio->id, 999999999]);

        $this->assertCount(1, $resultado['articulos']);
        $this->assertSame($propio->id, $resultado['articulos'][0]['articulo_id']);
        $this->assertSame([], $resultado['sin_imagen']);
        $this->assertCount(1, $resultado['adjuntos_de_la_respuesta']);
        $this->assertSame('https://fotos.test/propio.jpg', $resultado['adjuntos_de_la_respuesta'][0]['url']);
    }

    /**
     * Más de seis: se toman los primeros seis en el orden pedido, y `como_sigo` lo dice.
     *
     * @test
     */
    public function mas_de_seis_ids_se_recortan_a_los_primeros_seis_y_se_avisa()
    {
        $ids = [];

        for ($i = 1; $i <= 8; $i++) {
            $ids[] = $this->articulo('Artículo con foto ' . $i, 'https://fotos.test/' . $i . '.jpg')->id;
        }

        $resultado = AdjuntosIaHelper::imagenes_de_articulos($this->comercio->id, $ids);

        $this->assertCount(6, $resultado['articulos']);
        $this->assertCount(6, $resultado['adjuntos_de_la_respuesta']);
        $this->assertSame(array_slice($ids, 0, 6), array_column($resultado['adjuntos_de_la_respuesta'], 'articulo_id'));
        $this->assertStringContainsString('los primeros 6', $resultado['como_sigo']);
    }

    /**
     * Un artículo pausado tiene foto y es del dueño: entra. Uno borrado no existe más para nadie.
     * Y un id repetido cuenta una sola vez.
     *
     * @test
     */
    public function los_pausados_entran_los_borrados_no_y_los_repetidos_cuentan_una_vez()
    {
        $pausado = $this->articulo('Pausado con foto', 'https://fotos.test/pausado.jpg', null, 'inactive');
        $borrado = $this->articulo('Borrado con foto', 'https://fotos.test/borrado.jpg');
        $borrado->delete();

        $resultado = AdjuntosIaHelper::imagenes_de_articulos($this->comercio->id, [$pausado->id, $borrado->id, $pausado->id]);

        $this->assertSame([$pausado->id], array_column($resultado['articulos'], 'articulo_id'));
        $this->assertCount(1, $resultado['adjuntos_de_la_respuesta']);
        $this->assertSame('Pausado con foto', $resultado['adjuntos_de_la_respuesta'][0]['texto']);
    }

    /**
     * Sin ids válidos no hay nada que mostrar, pero la forma se conserva.
     *
     * @test
     */
    public function sin_ids_devuelve_las_listas_vacias()
    {
        $resultado = AdjuntosIaHelper::imagenes_de_articulos($this->comercio->id, ['x', 0, -3]);

        $this->assertSame([], $resultado['articulos']);
        $this->assertSame([], $resultado['sin_imagen']);
        $this->assertSame([], $resultado['adjuntos_de_la_respuesta']);
    }
}
