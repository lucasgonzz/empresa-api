<?php

namespace Tests\Feature\Extenciones;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión 54 — la ruta propia del configurador y la red de seguridad del guardado.
 *
 * El modo de falla que estos tests protegen es de plata: `update()` hace `sync()`, así que un
 * envío que no trae ninguna extensión se las saca TODAS al comercio. Con 91 checkboxes en
 * pantalla eso está a un click de distancia, y el cliente se entera cuando deja de funcionarle
 * media aplicación. La confirmación del navegador no alcanza como prueba —se puede postear sin
 * pasar por el formulario—, así que la guarda vive en el controlador y es lo que se mide acá.
 */
class Configurador_de_extenciones_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * El comercio sobre el que se prueba: el mismo que resuelve la ruta sin parámetro.
     *
     * @var \App\Models\User
     */
    protected $user;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(config('app.USER_ID'));

        $this->assertNotNull($this->user, 'No existe el usuario de config(app.USER_ID) en la base de testing.');

        /* Arranca sin extensiones asignadas: cada test arma el estado que necesita. */
        $this->user->extencions()->sync([]);
    }

    /**
     * Crea una extensión del catálogo para el test.
     *
     * @param  string  $slug
     * @param  string  $descripcion
     * @param  string  $modulo
     * @param  bool  $en_desuso
     * @return \App\Models\ExtencionEmpresa
     */
    protected function crear_extencion($slug, $descripcion = 'Descripcion de prueba', $modulo = 'VENDER', $en_desuso = false)
    {
        return ExtencionEmpresa::forceCreate([
            'name'        => 'Extension ' . $slug,
            'slug'        => $slug,
            'description' => $descripcion,
            'modulo'      => $modulo,
            'en_desuso'   => $en_desuso,
        ]);
    }

    /**
     * `/extensiones` entra al configurador del usuario de la config, sin escribir el id a mano.
     *
     * @test
     * @return void
     */
    public function la_ruta_propia_abre_el_configurador_del_usuario_de_la_config()
    {
        $this->crear_extencion('zz_test_ruta_propia');

        $respuesta = $this->get('/extensiones');

        $respuesta->assertStatus(200);
        $respuesta->assertSee('Extension zz_test_ruta_propia');
        $respuesta->assertSee($this->user->name, false);
    }

    /**
     * La ruta vieja se mantiene: acepta el id y se sigue necesitando.
     *
     * @test
     * @return void
     */
    public function la_ruta_con_user_id_sigue_funcionando()
    {
        $this->crear_extencion('zz_test_ruta_vieja');

        $respuesta = $this->get('/user/extencions/edit/' . $this->user->id);

        $respuesta->assertStatus(200);
        $respuesta->assertSee('Extension zz_test_ruta_vieja');
    }

    /**
     * La descripción se ve en la pantalla, que es todo el punto de la misión: dos extensiones
     * pueden compartir el nombre visible y hacer lo contrario.
     *
     * @test
     * @return void
     */
    public function la_vista_muestra_la_descripcion_y_el_slug_de_cada_extension()
    {
        $this->crear_extencion('zz_test_con_descripcion', 'Encendida IMPIDE agregar el articulo sin stock.');

        $respuesta = $this->get('/extensiones');

        $respuesta->assertSee('Encendida IMPIDE agregar el articulo sin stock.');
        $respuesta->assertSee('zz_test_con_descripcion');
    }

    /**
     * Las en desuso están, pero separadas en su propia sección al final.
     *
     * @test
     * @return void
     */
    public function las_en_desuso_van_a_su_seccion_y_no_al_listado_principal()
    {
        $this->crear_extencion('zz_test_muerta', 'No la lee nadie.', 'General', true);

        $respuesta = $this->get('/extensiones');

        $respuesta->assertSee('En desuso');
        $respuesta->assertSee('Extension zz_test_muerta');

        $html = $respuesta->getContent();

        $this->assertTrue(
            strpos($html, 'id="detalle-desuso"') < strpos($html, 'Extension zz_test_muerta'),
            'La extensión en desuso aparece antes de la sección de en desuso: quedó en el listado principal.'
        );
    }

    /**
     * 🔴 La red de seguridad. Un envío que le saca extensiones al comercio y llega sin confirmar
     * no guarda NADA: no es que guarde a medias, es que no toca la base.
     *
     * @test
     * @return void
     */
    public function un_envio_que_quita_extensiones_sin_confirmar_no_guarda_nada()
    {
        $una  = $this->crear_extencion('zz_test_quita_1');
        $otra = $this->crear_extencion('zz_test_quita_2');

        $this->user->extencions()->sync([$una->id, $otra->id]);

        /* El caso más caro: el envío vacío, que con sync() a secas las borra todas. */
        $respuesta = $this->post(route('users.extencions.update', $this->user->id), []);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('warning');

        $this->assertEqualsCanonicalizing(
            [$una->id, $otra->id],
            $this->user->extencions()->pluck('extencion_empresa_user.extencion_empresa_id')->toArray(),
            'El envío sin confirmar le quitó extensiones al comercio.'
        );
    }

    /**
     * El aviso dice cuántas se iban a quitar: sin el número, quien lo lee no sabe si fue un
     * accidente chico o le estaba borrando todo.
     *
     * @test
     * @return void
     */
    public function el_aviso_dice_cuantas_extensiones_se_iban_a_quitar()
    {
        $una  = $this->crear_extencion('zz_test_aviso_1');
        $otra = $this->crear_extencion('zz_test_aviso_2');

        $this->user->extencions()->sync([$una->id, $otra->id]);

        $respuesta = $this->post(route('users.extencions.update', $this->user->id), [
            'extencions' => [(string) $una->id],
        ]);

        $respuesta->assertSessionHas('warning');
        $this->assertStringContainsString('1 de las 2', session('warning'));
    }

    /**
     * Confirmado sí guarda: la red de seguridad no puede volver imposible quitar una extensión.
     *
     * @test
     * @return void
     */
    public function un_envio_confirmado_si_quita_las_extensiones()
    {
        $una  = $this->crear_extencion('zz_test_confirmado_1');
        $otra = $this->crear_extencion('zz_test_confirmado_2');

        $this->user->extencions()->sync([$una->id, $otra->id]);

        $respuesta = $this->post(route('users.extencions.update', $this->user->id), [
            'extencions'       => [(string) $una->id],
            'confirmar_quitar' => '1',
        ]);

        $respuesta->assertSessionHas('success');

        $this->assertEquals(
            [$una->id],
            $this->user->extencions()->pluck('extencion_empresa_user.extencion_empresa_id')->toArray()
        );
    }

    /**
     * Agregar no pide confirmación: la guarda es para lo que se pierde, no para lo que se suma.
     *
     * @test
     * @return void
     */
    public function un_envio_que_solo_agrega_guarda_sin_confirmacion()
    {
        $una  = $this->crear_extencion('zz_test_agrega_1');
        $otra = $this->crear_extencion('zz_test_agrega_2');

        $this->user->extencions()->sync([$una->id]);

        $respuesta = $this->post(route('users.extencions.update', $this->user->id), [
            'extencions' => [(string) $una->id, (string) $otra->id],
        ]);

        $respuesta->assertSessionHas('success');

        $this->assertEqualsCanonicalizing(
            [$una->id, $otra->id],
            $this->user->extencions()->pluck('extencion_empresa_user.extencion_empresa_id')->toArray()
        );
    }

    /**
     * Guardar desde la ruta propia vuelve a la ruta propia, no a la que lleva el id adentro.
     *
     * @test
     * @return void
     */
    public function guardar_desde_la_ruta_propia_vuelve_a_la_ruta_propia()
    {
        $una = $this->crear_extencion('zz_test_volver');

        $respuesta = $this->post(route('users.extencions.update', $this->user->id), [
            'extencions'           => [(string) $una->id],
            'volver_a_ruta_propia' => '1',
        ]);

        $respuesta->assertRedirect(route('extensiones'));
    }
}
