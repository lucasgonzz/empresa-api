<?php

use Illuminate\Database\Migrations\Migration;

/**
 * NO HACE NADA, Y ESO ES LA DECISIÓN, NO UN OLVIDO.
 *
 * 🔴 SI VINISTE A "COMPLETAR" ESTA MIGRACIÓN, LEÉ ESTO PRIMERO.
 *
 * Esta migración nació copiando a `platform_connectors` las credenciales de Mercado Pago que
 * cada comercio ya tuviera en `online_configurations.mp_*` o en `payment_methods`. Lucas la
 * vació el 3/9/2026, con estas palabras entre tres opciones sobre la tabla: **"Que no migre a
 * nadie"**.
 *
 * El argumento, que es lo que importa conservar:
 *
 * El OAuth de Mercado Pago que se construyó el 21/7/2026 (grupo 170, prompt 598) **nunca cobró
 * nada**. Guardaba tokens que ningún código leía para procesar un pago: `tienda-api` cobraba —y
 * al momento de escribir esto sigue cobrando— con `payment_methods.access_token`. O sea que un
 * comercio pudo entrar, apretar "Conectar", autorizar con la primera cuenta de Mercado Pago que
 * tuviera a mano, ver que no pasaba nada, y olvidarse.
 *
 * Tomar ese click como declaración de "esta es mi cuenta de cobro" —retroactivamente, en
 * silencio, y con la plata de verdad— es cambiarle a alguien la cuenta bancaria donde le entra
 * el dinero basándose en un gesto que en su momento no significaba nada. Por eso no se migra a
 * nadie: **cada comercio sigue cobrando por donde cobraba, hasta que entre a
 * ABM -> Integraciones y conecte a mano, ahora sí sabiendo que eso decide dónde le entra la
 * plata.**
 *
 * Consecuencia práctica, para que no sorprenda: el día del despliegue NINGÚN comercio tiene
 * conector de Mercado Pago, y la tarjeta de Tienda online les dice "Desconectado" aunque estén
 * cobrando perfectamente por `payment_methods`. Eso es correcto y es lo que se quiere: la
 * tarjeta habla de la conexión OAuth, y no hay ninguna.
 *
 * El archivo NO se borra: ya tiene su timestamp y pudo haber corrido en alguna base de
 * desarrollo, así que sacarlo dejaría esas bases con una fila en `migrations` que no
 * corresponde a ningún archivo.
 *
 * La fila `mercado_pago` de `platforms` —que esta migración también creaba— se mudó a
 * `2026_09_03_100400_asegurar_plataforma_mercado_pago.php`. Es catálogo, no credencial de
 * nadie, y sin ella no se puede conectar Mercado Pago ni a mano.
 */
class CopiarCredencialesMpAConectores extends Migration
{
    /**
     * No copia nada, a propósito. Ver el comentario de la clase.
     *
     * @return void
     */
    public function up()
    {
        //
    }

    /**
     * Nada que revertir: `up()` no escribe nada.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
