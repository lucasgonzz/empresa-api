<?php

namespace Tests\Feature\DemandaSinStock;

use App\Models\Address;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\Combo;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión demanda-sin-stock-vender, ronda 2 — archivo 6: pertenencia de los ids opcionales.
 *
 * `address_id`, `article_variant_id` y `combo_id` del POST api/unmet-demand se guardaban sin
 * comprobar que fueran del dueño: el asistente resuelve sucursal -> calle, así que un request
 * armado a mano podía mostrar la calle de una sucursal ajena. Un id que no es del dueño se
 * guarda como null (no 422: no se rompe el contrato ni el mostrador).
 */
class Pertenencia_de_ids_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Crea un usuario dueño.
     *
     * @param string $nombre
     * @return User
     */
    protected function duenio_nuevo($nombre)
    {
        return User::create([
            'name'     => $nombre,
            'email'    => 'demanda-d6-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Hace el POST como $usuario y devuelve la fila guardada.
     *
     * @param User $usuario
     * @param array $body
     * @return object
     */
    protected function registrar_y_leer(User $usuario, array $body)
    {
        $this->actingAs($usuario, 'web');

        $this->postJson('api/unmet-demand', array_merge(['motivo' => 'sin_stock', 'stock_disponible' => 0], $body))
            ->assertStatus(201);

        return DB::table('unmet_demands')->where('user_id', $usuario->id)->orderBy('id', 'DESC')->first();
    }

    /**
     * @group demanda-sin-stock
     * @test
     */
    public function una_sucursal_ajena_se_guarda_como_null_y_una_propia_se_conserva()
    {
        $duenio = $this->duenio_nuevo('Dueño D6');
        $ajeno = $this->duenio_nuevo('Ajeno D6');
        $articulo = Article::create(['name' => 'Mecha D6', 'user_id' => $duenio->id]);
        $propia = Address::create(['street' => 'Propia D6', 'user_id' => $duenio->id]);
        $ajena = Address::create(['street' => 'Ajena D6', 'user_id' => $ajeno->id]);

        $fila = $this->registrar_y_leer($duenio, ['article_id' => $articulo->id, 'address_id' => $ajena->id]);
        $this->assertNull($fila->address_id, 'La sucursal de otro dueño no se guarda.');

        DB::table('unmet_demands')->where('user_id', $duenio->id)->delete();

        $fila = $this->registrar_y_leer($duenio, ['article_id' => $articulo->id, 'address_id' => $propia->id]);
        $this->assertEquals($propia->id, $fila->address_id);
    }

    /**
     * @group demanda-sin-stock
     * @test
     */
    public function una_variante_de_otro_articulo_se_guarda_como_null()
    {
        $duenio = $this->duenio_nuevo('Dueño D6');
        $ajeno = $this->duenio_nuevo('Ajeno D6');
        $articulo = Article::create(['name' => 'Mecha D6', 'user_id' => $duenio->id]);
        $otro_articulo = Article::create(['name' => 'Otro D6', 'user_id' => $duenio->id]);
        $articulo_ajeno = Article::create(['name' => 'Ajeno D6', 'user_id' => $ajeno->id]);

        $variante_propia = ArticleVariant::create(['article_id' => $articulo->id]);
        $variante_de_otro_articulo = ArticleVariant::create(['article_id' => $otro_articulo->id]);
        $variante_ajena = ArticleVariant::create(['article_id' => $articulo_ajeno->id]);

        $fila = $this->registrar_y_leer($duenio, ['article_id' => $articulo->id, 'article_variant_id' => $variante_ajena->id]);
        $this->assertNull($fila->article_variant_id, 'La variante de un artículo ajeno no se guarda.');

        DB::table('unmet_demands')->where('user_id', $duenio->id)->delete();

        $fila = $this->registrar_y_leer($duenio, ['article_id' => $articulo->id, 'article_variant_id' => $variante_de_otro_articulo->id]);
        $this->assertNull($fila->article_variant_id, 'La variante tiene que ser de ESE artículo.');

        DB::table('unmet_demands')->where('user_id', $duenio->id)->delete();

        $fila = $this->registrar_y_leer($duenio, ['article_id' => $articulo->id, 'article_variant_id' => $variante_propia->id]);
        $this->assertEquals($variante_propia->id, $fila->article_variant_id);
    }

    /**
     * @group demanda-sin-stock
     * @test
     */
    public function un_combo_ajeno_se_guarda_como_null_y_uno_propio_se_conserva()
    {
        $duenio = $this->duenio_nuevo('Dueño D6');
        $ajeno = $this->duenio_nuevo('Ajeno D6');
        $articulo = Article::create(['name' => 'Mecha D6', 'user_id' => $duenio->id]);
        $propio = Combo::create(['num' => 1, 'name' => 'Combo propio D6', 'user_id' => $duenio->id]);
        $ajeno_combo = Combo::create(['num' => 1, 'name' => 'Combo ajeno D6', 'user_id' => $ajeno->id]);

        $fila = $this->registrar_y_leer($duenio, ['article_id' => $articulo->id, 'combo_id' => $ajeno_combo->id]);
        $this->assertNull($fila->combo_id, 'El combo de otro dueño no se guarda.');

        DB::table('unmet_demands')->where('user_id', $duenio->id)->delete();

        $fila = $this->registrar_y_leer($duenio, ['article_id' => $articulo->id, 'combo_id' => $propio->id]);
        $this->assertEquals($propio->id, $fila->combo_id);
    }
}
