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
        $extencion = $this->crear_extencion('zz_test_con_descripcion', 'Encendida IMPIDE agregar el articulo sin stock.');

        $html = $this->get('/extensiones')->getContent();

        /*
         * Con `assertSee` pelado esta aserción no mediría nada: cualquier atributo que repita el
         * texto —un data-, un title— la deja pasar con el bloque de la descripción borrado, y
         * mostrar la descripción EN PANTALLA es todo el punto de la misión. Por eso se exige el
         * texto adentro del div que la muestra.
         */
        $this->assertMatchesRegularExpression(
            '/<div class="ext-desc[^"]*" id="desc-' . $extencion->id . '">\s*Encendida IMPIDE agregar el articulo sin stock\.\s*<\/div>/',
            $html,
            'La descripción no está adentro del div que la muestra en pantalla.'
        );

        $this->assertMatchesRegularExpression(
            '/<div class="ext-slug" id="slug-' . $extencion->id . '">\s*zz_test_con_descripcion\s*<\/div>/',
            $html,
            'El slug no se muestra debajo del nombre.'
        );

        /* Y la descripción tiene que estar asociada al checkbox, no solo cerca en la pantalla. */
        $this->assertMatchesRegularExpression(
            '/id="ext-' . $extencion->id . '"[^>]*aria-describedby="slug-' . $extencion->id . ' desc-' . $extencion->id . '"/',
            $html,
            'El checkbox no declara su descripción con aria-describedby: para un lector de pantalla las parecidas siguen siendo indistinguibles.'
        );
    }

    /**
     * Los dos campos ocultos que el controlador necesita tienen que existir en la pantalla.
     *
     * Sin esto, los tests que postean `confirmar_quitar` y `volver_a_ruta_propia` a mano se
     * verifican a sí mismos: si el campo desaparece del formulario la suite queda verde igual, y
     * desde la pantalla quitar una extensión pasa a ser imposible para siempre —el controlador
     * rechaza el envío y el aviso pide una confirmación que ya nadie puede dar—.
     *
     * @test
     * @return void
     */
    public function el_formulario_trae_los_campos_que_el_controlador_espera()
    {
        $this->crear_extencion('zz_test_campos');

        $desde_la_ruta_propia = $this->get('/extensiones')->getContent();

        $this->assertMatchesRegularExpression(
            '/<input type="hidden" name="confirmar_quitar" id="confirmar_quitar" value="">/',
            $desde_la_ruta_propia,
            'Falta el campo confirmar_quitar: sin él, el controlador rechaza todo envío que quite extensiones y no hay forma de confirmarlo.'
        );

        $this->assertMatchesRegularExpression(
            '/<input type="hidden" name="volver_a_ruta_propia" value="1">/',
            $desde_la_ruta_propia,
            'Falta el campo volver_a_ruta_propia: guardar desde /extensiones va a redirigir a la URL con el id adentro.'
        );

        /* Y el caso negativo: entrando con el id, ese campo NO tiene que estar. */
        $desde_la_ruta_vieja = $this->get('/user/extencions/edit/' . $this->user->id)->getContent();

        $this->assertStringNotContainsString(
            'name="volver_a_ruta_propia"',
            $desde_la_ruta_vieja,
            'La ruta con id manda volver_a_ruta_propia: el redirect va a perder el usuario que se estaba editando.'
        );
    }

    /**
     * El formulario agrupa por módulo, en el orden declarado, y no se come las que no tienen
     * módulo cargado.
     *
     * `agrupar_por_modulo()` es la única cosa entre una extensión de la base y el formulario: si
     * su rama de "módulo desconocido" se rompiera, esas extensiones desaparecerían de la pantalla
     * sin ningún error — y una extensión que no está en el formulario es una que el comercio no
     * puede encender.
     *
     * @test
     * @return void
     */
    public function agrupa_por_modulo_y_las_que_no_tienen_modulo_no_desaparecen()
    {
        $this->crear_extencion('zz_test_en_vender', 'Descripcion.', 'VENDER');
        $this->crear_extencion('zz_test_en_precios', 'Descripcion.', 'Precios');

        /* Sin módulo: es lo que le pasa a una extensión que el padrón no describe. */
        $huerfana = ExtencionEmpresa::forceCreate([
            'name'      => 'Extension zz_test_sin_modulo',
            'slug'      => 'zz_test_sin_modulo',
            'en_desuso' => false,
        ]);

        $html = $this->get('/extensiones')->getContent();

        $this->assertStringContainsString('data-modulo="VENDER"', $html);
        $this->assertStringContainsString('data-modulo="Precios"', $html);
        $this->assertStringContainsString('data-modulo="Sin clasificar"', $html);

        /* La huérfana está en el formulario, tildable, y no se perdió en el camino. */
        $this->assertMatchesRegularExpression('/name="extencions\[\]"[^>]*value="' . $huerfana->id . '"/', $html);

        $posicion_vender  = strpos($html, 'data-modulo="VENDER"');
        $posicion_precios = strpos($html, 'data-modulo="Precios"');
        $posicion_sin     = strpos($html, 'data-modulo="Sin clasificar"');

        $this->assertNotFalse($posicion_vender);
        $this->assertNotFalse($posicion_precios);
        $this->assertNotFalse($posicion_sin);

        $this->assertLessThan($posicion_precios, $posicion_vender, 'VENDER tiene que ir antes que Precios, como declara ORDEN_MODULOS.');
        $this->assertLessThan($posicion_sin, $posicion_precios, 'Las sin clasificar van al final del listado.');
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

        $posicion_seccion = strpos($html, 'id="detalle-desuso"');
        $posicion_muerta  = strpos($html, 'Extension zz_test_muerta');

        /*
         * Los assertNotFalse no sobran: `strpos` devuelve false cuando no encuentra, y PHP
         * compara `false < 5` como booleanos y da verdadero. Sin ellos, renombrar el id de la
         * sección convierte esta aserción en un no-op permanente que nadie nota.
         */
        $this->assertNotFalse($posicion_seccion, 'No está la sección de en desuso en el HTML.');
        $this->assertNotFalse($posicion_muerta, 'No está la extensión en desuso en el HTML.');

        $this->assertLessThan(
            $posicion_muerta,
            $posicion_seccion,
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
