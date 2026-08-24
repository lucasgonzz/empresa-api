<?php

namespace Tests\Feature\Puntos;

/**
 * Archivo 17 — 🔴 EL DEFAULT DE `vencimiento_meses` EN EL ABM DEL PROGRAMA.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL AGUJERO QUE ESTE ARCHIVO CIERRA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  De los seis campos del programa, cinco tienen default en el controller (1000 / 1 / 10 /
 *  500 / 20) y `vencimiento_meses` era el único que no: un POST que no mandara la clave
 *  guardaba `null`, o sea "los puntos no vencen NUNCA", que es exactamente el pasivo que el
 *  vencimiento existe para cerrar. Por la pantalla no se veía porque la SPA prellena 12; se
 *  veía desde cualquier otro cliente del endpoint, o el día que alguien saque el prellenado.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 Y "SIN VENCIMIENTO" SIGUE SIENDO UNA CONFIGURACIÓN LEGÍTIMA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Por eso no alcanzaba con ponerle un default a secas. Son TRES intenciones y las tres se
 *  tienen que poder expresar:
 *
 *    | el request NO trae la clave      | el dueño no dijo nada | 12 meses |
 *    | la clave llega vacía             | "que no venzan"       | null     |
 *    | la clave llega con un número     | lo que pidió          | ese      |
 *
 *  Un test por fila. Si mañana alguien "simplifica" el `has()` a un `input() ?: 12`, la
 *  segunda se pone roja y dice qué se perdió.
 */
class Default_de_vencimiento_Test extends PuntosTestCase
{
    /**
     * El cuerpo del ABM sin el campo de vencimiento. Los otros cinco van explícitos para que
     * ninguno de ellos pueda ser el motivo de un 422 y ensuciar lo que este archivo mide.
     *
     * @param  array  $extra
     * @return array
     */
    protected function cuerpo($extra = [])
    {
        return array_merge([
            'nombre'           => 'Programa del test de vencimiento',
            'activo'           => 1,
            'puntos_cada'      => 1000,
            'puntos_por_tramo' => 1,
            'valor_punto'      => 10,
            'minimo_canje'     => 500,
            'tope_porcentaje'  => 20,
            'price_types'      => [],
        ], $extra);
    }

    /**
     * @param  array  $cuerpo
     * @return array  El `model` de la respuesta.
     */
    protected function crear_por_el_abm($cuerpo)
    {
        $response = $this->postJson('api/sistema-de-puntos', $cuerpo);

        $response->assertStatus(201);

        $body = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('model', $body, 'El ABM tiene que responder con la clave `model`.');

        return $body['model'];
    }

    /**
     * 🔴 Sin la clave en el request, se aplica el default de 12 meses.
     *
     * @group puntos
     * @test
     */
    public function un_programa_creado_sin_el_campo_de_vencimiento_vence_a_los_doce_meses()
    {
        $this->dar_extencion();

        $model = $this->crear_por_el_abm($this->cuerpo());

        $this->assertEquals(
            12,
            (int) $model['vencimiento_meses'],
            'El que no dice nada se lleva el default del negocio (12 meses), no puntos que no vencen nunca.'
        );
    }

    /**
     * Y la clave vacía SÍ significa "que no venzan": es una decisión del dueño, no un silencio.
     *
     * @group puntos
     * @test
     */
    public function un_programa_creado_con_el_campo_vacio_no_vence_los_puntos()
    {
        $this->dar_extencion();

        $model = $this->crear_por_el_abm($this->cuerpo(['vencimiento_meses' => '']));

        $this->assertNull(
            $model['vencimiento_meses'],
            'Mandar el campo vacío es pedir explícitamente que los puntos no venzan, y tiene que seguir siendo posible.'
        );

        /*
         * `null` explícito es la otra forma en que un cliente del endpoint puede decir lo
         * mismo, y tiene que dar el mismo resultado que la cadena vacía.
         */
        $model_null = $this->crear_por_el_abm($this->cuerpo(['vencimiento_meses' => null]));

        $this->assertNull($model_null['vencimiento_meses'], 'Un null explícito es la misma intención que la cadena vacía.');
    }

    /**
     * Con un número, se guarda ese número. La fila obvia, que igual se mide: es la que confirma
     * que el default no está pisando lo que el usuario pidió.
     *
     * @group puntos
     * @test
     */
    public function un_programa_creado_con_un_numero_guarda_ese_numero()
    {
        $this->dar_extencion();

        $model = $this->crear_por_el_abm($this->cuerpo(['vencimiento_meses' => 24]));

        $this->assertEquals(24, (int) $model['vencimiento_meses']);
    }

    /**
     * Lo mismo en el UPDATE: el ABM comparte los dos caminos, así que si el default viviera en
     * uno solo, editar un programa sin mandar el campo lo dejaría sin vencimiento.
     *
     * @group puntos
     * @test
     */
    public function editar_un_programa_sin_el_campo_tampoco_lo_deja_sin_vencimiento()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa(['vencimiento_meses' => 6]);

        $response = $this->putJson('api/sistema-de-puntos/'.$sistema->id, $this->cuerpo());

        $response->assertStatus(200);

        $body = json_decode($response->getContent(), true);

        $this->assertEquals(
            12,
            (int) $body['model']['vencimiento_meses'],
            'Un PUT que no menciona el vencimiento no puede convertir el programa en uno que no vence nunca.'
        );
    }
}
