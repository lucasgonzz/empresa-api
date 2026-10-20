<?php

namespace Tests\Feature\Broadcast;

use App\Broadcasting\EspacioDeCanales;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El espacio de nombres de los canales de Pusher (misión pusher-canales-por-sistema, 19/10/2026):
 * el cálculo, el override y las dos traducciones (emitir y autorizar).
 *
 * 🔴 Los tres vectores de `los_vectores_del_contrato_dan_el_mismo_literal_que_tienda_api` son el
 * contrato con tienda-api: su copia de `EspacioDeCanales` afirma EXACTAMENTE estos literales. Si este
 * test se pone rojo por un cambio de algoritmo, el ERP y la tienda de un mismo cliente dejan de
 * oírse; no se corrige cambiando los literales.
 *
 * PHP 7.4.
 */
class EspacioDeCanalesTest extends TestCase
{
    /**
     * Arranca cada test sin override y en transición, con un secret conocido.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.espacio'                   => '',
            'broadcasting.canales_viejos_de_tienda'  => true,
            'broadcasting.connections.pusher.secret' => 'secreto-de-prueba',
        ]);
    }

    /**
     * El espacio de la base de testing con el secret del setUp, calculado a mano.
     *
     * @return string
     */
    protected function espacio_esperado()
    {
        return EspacioDeCanales::calcular(
            DB::connection()->getDatabaseName(),
            config('app.env'),
            'secreto-de-prueba'
        );
    }

    /**
     * @test
     */
    public function los_vectores_del_contrato_dan_el_mismo_literal_que_tienda_api()
    {
        $this->assertSame('26d5013a6846', EspacioDeCanales::calcular('u767360347_ferretotal', 'production', 'secreto-de-prueba'));
        $this->assertSame('b4b66b323190', EspacioDeCanales::calcular('demo3', 'production', 'secreto-de-prueba'));

        // Clave vacía → la constante 'comerciocity-canales'. Null también.
        $this->assertSame('4734efc431ff', EspacioDeCanales::calcular('demo3', 'production', ''));
        $this->assertSame('4734efc431ff', EspacioDeCanales::calcular('demo3', 'production', null));
    }

    /**
     * El caso que motivó la misión: demo3 y Ferretotal, con el mismo dueño 2600, quedan en espacios
     * distintos. Y la misma base da siempre el mismo (dos frentes del mismo cliente, o empresa-api y
     * tienda-api, que leen la misma base).
     *
     * @test
     */
    public function dos_bases_distintas_dan_espacios_distintos_y_la_misma_base_el_mismo()
    {
        $ferretotal = EspacioDeCanales::calcular('u767360347_ferretotal', 'production', 'x');
        $demo3 = EspacioDeCanales::calcular('demo3', 'production', 'x');

        $this->assertNotSame($ferretotal, $demo3);
        $this->assertSame($ferretotal, EspacioDeCanales::calcular('u767360347_ferretotal', 'production', 'x'));

        // El entorno separa una copia local de la base de producción homónima.
        $this->assertNotSame(
            EspacioDeCanales::calcular('ferretotal', 'production', 'x'),
            EspacioDeCanales::calcular('ferretotal', 'local', 'x')
        );

        // Formato: 12 hex en minúscula.
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{12}\z/', $demo3);
    }

    /**
     * `espacio()` sale de las entradas reales (base por defecto, app.env, secret), y el cache por
     * combinación recalcula si cambia una.
     *
     * @test
     */
    public function el_espacio_sale_de_la_base_el_entorno_y_el_secret()
    {
        $this->assertSame($this->espacio_esperado(), EspacioDeCanales::espacio());

        config(['broadcasting.connections.pusher.secret' => 'otro-secreto']);

        $this->assertSame(
            EspacioDeCanales::calcular(DB::connection()->getDatabaseName(), config('app.env'), 'otro-secreto'),
            EspacioDeCanales::espacio(),
            'El cache devolvió el espacio del secret anterior.'
        );
        $this->assertNotSame($this->espacio_esperado(), EspacioDeCanales::espacio());
    }

    /**
     * @test
     */
    public function el_override_manda_y_se_sanea()
    {
        config(['broadcasting.espacio' => 'manual123']);
        $this->assertSame('manual123', EspacioDeCanales::espacio());
        $this->assertSame('global_notification.2600@manual123', EspacioDeCanales::con_espacio('global_notification.2600'));

        // Minúsculas antes de filtrar: 'Demo-3!' da 'demo3', no 'emo3'.
        config(['broadcasting.espacio' => 'Demo-3!']);
        $this->assertSame('demo3', EspacioDeCanales::espacio());

        // Hasta 32 caracteres.
        config(['broadcasting.espacio' => str_repeat('a', 40)]);
        $this->assertSame(str_repeat('a', 32), EspacioDeCanales::espacio());

        // Si del override no queda nada usable, se calcula.
        config(['broadcasting.espacio' => '@@--!!']);
        $this->assertSame($this->espacio_esperado(), EspacioDeCanales::espacio());

        config(['broadcasting.espacio' => null]);
        $this->assertSame($this->espacio_esperado(), EspacioDeCanales::espacio());
    }

    /**
     * @test
     */
    public function con_espacio_no_pone_dos_veces_el_sufijo()
    {
        $esp = $this->espacio_esperado();

        $this->assertSame('private-chat.user.5@' . $esp, EspacioDeCanales::con_espacio('private-chat.user.5'));
        $this->assertSame('private-chat.user.5@' . $esp, EspacioDeCanales::con_espacio('private-chat.user.5@' . $esp));
    }

    /**
     * @test
     */
    public function los_canales_de_tienda_son_los_que_cruzan_con_la_tienda()
    {
        $this->assertTrue(EspacioDeCanales::es_canal_de_tienda('tienda-respuestas.400.7'));
        $this->assertTrue(EspacioDeCanales::es_canal_de_tienda('message.from_commerce.7'));
        $this->assertTrue(EspacioDeCanales::es_canal_de_tienda('private-tienda-mensajes.400'));
        $this->assertTrue(EspacioDeCanales::es_canal_de_tienda('tienda-mensajes.400'));

        $this->assertFalse(EspacioDeCanales::es_canal_de_tienda('global_notification.400'));
        $this->assertFalse(EspacioDeCanales::es_canal_de_tienda('private-chat.user.400'));
        $this->assertFalse(EspacioDeCanales::es_canal_de_tienda('private-App.Models.User.400'));
        $this->assertFalse(EspacioDeCanales::es_canal_de_tienda('added_model.400'));
    }

    /**
     * @test
     */
    public function emitir_en_transicion_duplica_solo_los_de_tienda()
    {
        $esp = $this->espacio_esperado();

        $this->assertSame(
            ['global_notification.2600@' . $esp],
            EspacioDeCanales::nombres_para_emitir('global_notification.2600')
        );
        $this->assertSame(
            ['tienda-respuestas.400.7@' . $esp, 'tienda-respuestas.400.7'],
            EspacioDeCanales::nombres_para_emitir('tienda-respuestas.400.7')
        );
        $this->assertSame(
            ['private-tienda-mensajes.400@' . $esp, 'private-tienda-mensajes.400'],
            EspacioDeCanales::nombres_para_emitir('private-tienda-mensajes.400')
        );
    }

    /**
     * @test
     */
    public function emitir_en_estricto_va_solo_con_espacio()
    {
        config(['broadcasting.canales_viejos_de_tienda' => false]);

        $esp = $this->espacio_esperado();

        $this->assertSame(['tienda-respuestas.400.7@' . $esp], EspacioDeCanales::nombres_para_emitir('tienda-respuestas.400.7'));
        $this->assertSame(['message.from_commerce.7@' . $esp], EspacioDeCanales::nombres_para_emitir('message.from_commerce.7'));
        $this->assertSame(['private-tienda-mensajes.400@' . $esp], EspacioDeCanales::nombres_para_emitir('private-tienda-mensajes.400'));
    }

    /**
     * La matriz completa de `canal_para_autorizar()`. La parte que se prueba por HTTP, contra los
     * callbacks de verdad, está en `AutorizacionConEspacioTest`.
     *
     * @test
     */
    public function autorizar_traduce_el_sufijo_propio_y_corta_todo_lo_demas()
    {
        $esp = $this->espacio_esperado();
        $otro = EspacioDeCanales::calcular('otra_base', 'testing', 'secreto-de-prueba');

        // Sufijo propio → el nombre lógico con su prefijo de tipo.
        $this->assertSame('private-chat.user.5', EspacioDeCanales::canal_para_autorizar('private-chat.user.5@' . $esp));
        $this->assertSame('private-tienda-mensajes.400', EspacioDeCanales::canal_para_autorizar('private-tienda-mensajes.400@' . $esp));

        // Sufijo ajeno, sin sufijo fuera de tienda, un '@' de más, vacío → null.
        $this->assertNull(EspacioDeCanales::canal_para_autorizar('private-chat.user.5@' . $otro));
        $this->assertNull(EspacioDeCanales::canal_para_autorizar('private-chat.user.5'));
        $this->assertNull(EspacioDeCanales::canal_para_autorizar('private-chat.user.5@' . $otro . '@' . $esp));
        $this->assertNull(EspacioDeCanales::canal_para_autorizar('private-@' . $esp));
        $this->assertNull(EspacioDeCanales::canal_para_autorizar('@' . $esp));
        $this->assertNull(EspacioDeCanales::canal_para_autorizar(''));
        $this->assertNull(EspacioDeCanales::canal_para_autorizar(null));

        // Sin sufijo y de tienda: en transición pasa tal cual; en estricto, no.
        $this->assertSame('private-tienda-mensajes.400', EspacioDeCanales::canal_para_autorizar('private-tienda-mensajes.400'));

        config(['broadcasting.canales_viejos_de_tienda' => false]);

        $this->assertNull(EspacioDeCanales::canal_para_autorizar('private-tienda-mensajes.400'));
        $this->assertSame('private-tienda-mensajes.400', EspacioDeCanales::canal_para_autorizar('private-tienda-mensajes.400@' . $esp));
    }

    /**
     * @test
     */
    public function lo_que_viaja_al_spa_tiene_las_dos_claves_del_contrato()
    {
        $this->assertSame(
            ['espacio' => $this->espacio_esperado(), 'canales_viejos_de_tienda' => true],
            EspacioDeCanales::para_el_spa()
        );

        config(['broadcasting.canales_viejos_de_tienda' => false]);
        $this->assertFalse(EspacioDeCanales::para_el_spa()['canales_viejos_de_tienda']);

        // Clave ausente (un config:cache de antes del release) → transición.
        config(['broadcasting.canales_viejos_de_tienda' => null]);
        $this->assertTrue(EspacioDeCanales::canales_viejos_de_tienda());
    }

    /**
     * 🔴 La trampa del `.env` a medio editar: `BROADCAST_CANALES_VIEJOS_DE_TIENDA=` (vacío) o mal
     * tipeado NO puede pasar a estricto en silencio; solo un falso explícito lo hace. Se lee el
     * archivo de config de verdad con cada valor en `$_SERVER`, que es de donde lo toma `env()`.
     *
     * @test
     */
    public function el_modo_de_la_config_solo_pasa_a_estricto_con_un_falso_explicito()
    {
        /** Valor del .env => lo que tiene que quedar en la config. */
        $casos = [
            ''       => true,
            'null'   => true,
            '(null)' => true,
            'true'   => true,
            '1'      => true,
            'flase'  => true,
            'false' => false,
            '0'     => false,
            'off'   => false,
            'no'    => false,
        ];

        /** Lo que había antes, para dejarlo como estaba. */
        $habia = array_key_exists('BROADCAST_CANALES_VIEJOS_DE_TIENDA', $_SERVER)
            ? $_SERVER['BROADCAST_CANALES_VIEJOS_DE_TIENDA']
            : null;

        try {
            foreach ($casos as $valor => $esperado) {
                $_SERVER['BROADCAST_CANALES_VIEJOS_DE_TIENDA'] = (string) $valor;

                $config = require base_path('config/broadcasting.php');

                $this->assertSame($esperado, $config['canales_viejos_de_tienda'], 'Con BROADCAST_CANALES_VIEJOS_DE_TIENDA=' . $valor);
            }

            unset($_SERVER['BROADCAST_CANALES_VIEJOS_DE_TIENDA']);

            $config = require base_path('config/broadcasting.php');

            $this->assertTrue($config['canales_viejos_de_tienda'], 'Sin la variable, el default es la transición.');
            $this->assertSame('', $config['espacio'], 'Sin BROADCAST_ESPACIO el override queda vacío.');
        } finally {
            if ($habia === null) {
                unset($_SERVER['BROADCAST_CANALES_VIEJOS_DE_TIENDA']);
            } else {
                $_SERVER['BROADCAST_CANALES_VIEJOS_DE_TIENDA'] = $habia;
            }
        }
    }
}
