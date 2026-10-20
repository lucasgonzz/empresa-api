<?php

namespace Tests\Feature\Envios;

use App\Models\OnlineConfiguration;
use Tests\Concerns\EscondeColumnas;
use Tests\EmpresaTestCase;

/**
 * Misión formas-envio-direcciones-maps (19/10/2026): el comercio elige si la tienda ofrece envío
 * por correo con Zipnova (`online_configurations.ofrecer_envio_zipnova`) o solo sus formas de
 * envío propias, sin tener que desconectar Zipnova.
 *
 * Lo que fija este archivo (`PUT online-configuration/{id}`):
 *
 * 1. La columna arranca en 1: un comercio que ya tenía Zipnova conectado sigue igual.
 * 2. Destildar guarda 0 y volver a tildar guarda 1 (también con los valores de form-data).
 * 3. Un PUT SIN la clave (una pantalla vieja de empresa-spa) o con la clave en null no la toca:
 *    ni la apaga ni la prende.
 * 4. Sin la columna (la ventana del deploy antes de migrar) la configuración se guarda igual.
 *
 * El PUT manda el modelo entero, como el formulario ABM de la SPA: el update reasigna casi todas
 * las columnas con lo que venga, así que un payload parcial pisaría el resto con null.
 *
 * @group envios
 * @group configuracion-online
 */
class OfrecerEnvioZipnovaTest extends EmpresaTestCase
{
    use EscondeColumnas;

    /** Tabla y columna de la bandera. */
    const TABLA = 'online_configurations';
    const COLUMNA = 'ofrecer_envio_zipnova';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_columna_si_quedo_escondida(self::TABLA, self::COLUMNA);
    }

    /**
     * Id del usuario autenticado por `EmpresaTestCase` (el dueño del fixture).
     *
     * @return int
     */
    protected function user_id()
    {
        return $this->app['auth']->user()->id;
    }

    /**
     * Una configuración online nueva del comercio, con lo mínimo (el resto cae a los defaults de
     * las migraciones, como en `HelperController`/`UserSeeder`).
     *
     * @param  array<string, mixed>  $atributos
     * @return OnlineConfiguration
     */
    protected function configuracion(array $atributos = [])
    {
        return OnlineConfiguration::create(array_merge(['user_id' => $this->user_id()], $atributos))->fresh();
    }

    /**
     * El modelo entero, tal cual lo manda el formulario, con cambios encima y claves sacadas.
     *
     * @param  OnlineConfiguration  $config
     * @param  array<string, mixed>  $cambios
     * @param  array<int, string>  $sin  Claves que el payload no trae.
     * @return array<string, mixed>
     */
    protected function payload($config, array $cambios = [], array $sin = [])
    {
        $payload = array_merge($config->fresh()->toArray(), $cambios);

        foreach ($sin as $clave) {
            unset($payload[$clave]);
        }

        return $payload;
    }

    /**
     * Valor guardado de la bandera, como entero.
     *
     * @param  OnlineConfiguration  $config
     * @return int|null
     */
    protected function guardado($config)
    {
        $valor = $config->fresh()->ofrecer_envio_zipnova;

        return is_null($valor) ? null : (int) $valor;
    }

    /**
     * 1. Arranca ofreciendo Zipnova.
     *
     * @return void
     */
    public function test_arranca_ofreciendo_zipnova()
    {
        $this->assertSame(1, $this->guardado($this->configuracion()));
    }

    /**
     * 2. Destildar guarda 0, tildar guarda 1, y los valores de form-data ("false", "1") se leen
     *    como booleanos. La respuesta trae el valor guardado.
     *
     * @return void
     */
    public function test_destildar_y_volver_a_tildar()
    {
        $config = $this->configuracion();

        $respuesta = $this->putJson('api/online-configuration/' . $config->id, $this->payload($config, ['ofrecer_envio_zipnova' => 0]));

        $respuesta->assertStatus(200);
        $this->assertSame(0, $this->guardado($config));
        $this->assertSame(0, (int) $respuesta->json('model.ofrecer_envio_zipnova'));

        $this->putJson('api/online-configuration/' . $config->id, $this->payload($config, ['ofrecer_envio_zipnova' => true]))->assertStatus(200);
        $this->assertSame(1, $this->guardado($config));

        $this->putJson('api/online-configuration/' . $config->id, $this->payload($config, ['ofrecer_envio_zipnova' => 'false']))->assertStatus(200);
        $this->assertSame(0, $this->guardado($config));

        $this->putJson('api/online-configuration/' . $config->id, $this->payload($config, ['ofrecer_envio_zipnova' => '1']))->assertStatus(200);
        $this->assertSame(1, $this->guardado($config));
    }

    /**
     * 3. Un PUT sin la clave (pantalla vieja) o con la clave en null deja lo que estaba, sea 0 o 1.
     *
     * @return void
     */
    public function test_un_put_sin_la_clave_o_en_null_no_la_toca()
    {
        $apagada = $this->configuracion(['ofrecer_envio_zipnova' => 0]);

        $this->putJson('api/online-configuration/' . $apagada->id, $this->payload($apagada, [], ['ofrecer_envio_zipnova']))->assertStatus(200);
        $this->assertSame(0, $this->guardado($apagada), 'Un PUT sin la clave volvió a prender Zipnova.');

        $this->putJson('api/online-configuration/' . $apagada->id, $this->payload($apagada, ['ofrecer_envio_zipnova' => null]))->assertStatus(200);
        $this->assertSame(0, $this->guardado($apagada), 'Un PUT con la clave en null cambió la bandera.');

        $prendida = $this->configuracion(['ofrecer_envio_zipnova' => 1]);

        $this->putJson('api/online-configuration/' . $prendida->id, $this->payload($prendida, [], ['ofrecer_envio_zipnova']))->assertStatus(200);
        $this->assertSame(1, $this->guardado($prendida), 'Un PUT sin la clave apagó Zipnova.');

        $this->putJson('api/online-configuration/' . $prendida->id, $this->payload($prendida, ['ofrecer_envio_zipnova' => null]))->assertStatus(200);
        $this->assertSame(1, $this->guardado($prendida), 'Un PUT con la clave en null apagó Zipnova (boolean() de null es false).');
    }

    /**
     * 4. Sin la columna (código nuevo, migración todavía sin correr) la configuración se guarda
     *    igual aunque la SPA nueva mande la clave: lo demás del PUT no se pierde.
     *
     * @return void
     */
    public function test_sin_la_columna_la_configuracion_se_guarda_igual()
    {
        $self = $this;

        $this->sin_la_columna(self::TABLA, self::COLUMNA, function () use ($self) {

            $config = $self->configuracion(['has_delivery' => 0]);

            $respuesta = $self->putJson(
                'api/online-configuration/' . $config->id,
                $self->payload($config, ['has_delivery' => 1, 'ofrecer_envio_zipnova' => 0])
            );

            $respuesta->assertStatus(200);
            $self->assertSame(1, (int) $config->fresh()->has_delivery, 'Sin la columna se perdió el resto del PUT.');
            $self->assertArrayNotHasKey('ofrecer_envio_zipnova', $respuesta->json('model'));
        });
    }
}
