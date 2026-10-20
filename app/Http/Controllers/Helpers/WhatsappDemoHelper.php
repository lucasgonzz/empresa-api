<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Models\Client;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappBotSendService;
use App\Services\DemoIndustriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deja el módulo de WhatsApp de una instancia de DEMO conectado en modo de ENVÍO SIMULADO, con
 * plantillas aprobadas y chats de ejemplo (misión whatsapp-envio-simulado-demos, 15/10/2026).
 *
 * Para qué: el módulo de WhatsApp del centro de recursos (comprobante por WhatsApp, recordatorios
 * de cobro, tablero, plantillas, configuración) no se puede filmar en las demos si cada envío sale
 * de verdad por Kapso: ni hay una cuenta de Kapso para las demos, ni se le puede mandar un
 * recordatorio de deuda a un cliente real, ni conviene arriesgar la calificación del número de
 * WhatsApp Business de ComercioCity. Con la fila de configuración en `envio_simulado = 1`,
 * `WhatsappBotSendService` no le habla a Kapso y todo se ve enviado en la conversación. Ver
 * `WhatsappBotConfig::esta_en_modo_demostracion()`.
 *
 * Lo llaman dos puertas, que hacen exactamente lo mismo:
 *   - `DemoSetupHelper::run()`, al armar una demo de cero (sin depender del admin: no recibe
 *     nada por el payload, a diferencia de las credenciales de Zipnova y Mercado Pago);
 *   - el comando `whatsapp:demo-simulado`, sobre una instancia ya armada (demo, demo2 y demo3:
 *     esta última es una copia de la base de un cliente real y NUNCA pasa por el demo setup).
 *
 * 🔴 SE NIEGA FUERA DE UNA INSTANCIA DE DEMO (`DemoSetupHelper::es_instancia_de_demo()`), con una
 * excepción. Hace tres cosas que en un cliente real serían un daño: pisa la clave de Kapso por una
 * ficticia, aprueba plantillas que Meta nunca aprobó y reescribe teléfonos de clientes. El demo
 * setup y el comando llaman acá sabiendo que la guarda corta antes de tocar nada.
 *
 * Es IDEMPOTENTE: correrlo dos veces deja lo mismo (la config y las plantillas se actualizan, un
 * chat que ya existe no se vuelve a sembrar).
 *
 * 🔴 NADA DE ESTO PASA POR EL CAMINO DE UN MENSAJE ENTRANTE REAL. Los chats y mensajes se crean
 * directo con el modelo, no con `WhatsappChatHelper::store_inbound_message()`: ese camino agenda
 * al agente de IA (Anthropic + embeddings de OpenAI, pagos) y emite broadcasts. Sembrar un chat no
 * puede gastar plata ni disparar nada.
 */
class WhatsappDemoHelper
{
    /**
     * Clave de Kapso que queda en la configuración de una demo. Es obviamente falsa a propósito:
     * si algún día el modo demostración se apagara por error, Kapso la rechazaría con un 401 y
     * el envío fallaría de forma visible, en vez de salir con una clave que nadie puso.
     *
     * @var string
     */
    const CLAVE_FALSA = 'DEMO-SIN-CLAVE-ENVIO-SIMULADO';

    /**
     * `phone_number_id` ficticio: no es un número de Kapso, y Kapso contesta 404 si alguien lo usa.
     *
     * @var string
     */
    const PHONE_NUMBER_ID_FALSO = 'DEMO-000000000000000';

    /**
     * Teléfono de Lucas, el único real que puede quedar en una demo (es el que usan las siembras).
     *
     * @var string
     */
    const TELEFONO_DE_LUCAS = '3444622139';

    /**
     * Prefijo de los teléfonos de ficción que genera este helper: `381459` + 4 dígitos. Mismo
     * estilo secuencial que los de `ClientSeeder` (`3814500001`…), en un bloque propio para no
     * chocar con ellos.
     *
     * @var string
     */
    const PREFIJO_TELEFONO_DE_FICCION = '381459';

    /**
     * Prepara el módulo de WhatsApp de la demo del dueño recibido.
     *
     * @param  User   $owner     Dueño de la instancia (`owner_id` null).
     * @param  array  $opciones  Claves opcionales:
     *                           - `chats` (bool, default true): sembrar los chats de ejemplo.
     *                           - `telefonos_de_ficcion` (bool, default false): reemplazar los
     *                             teléfonos de los clientes que no sean de ficción (para bases que son
     *                             copia de un cliente real, como demo3).
     *                           - `solo_medir` (bool, default false): no escribir nada, solo informar.
     *                           - `renovar_chats` (bool, default false): borrar los chats de ejemplo
     *                             que sembró este helper y volver a sembrarlos. Sirve para repetir una
     *                             toma: las fechas vuelven a «hace un rato» (la ventana de 24 h de los
     *                             chats abiertos se cierra al día siguiente) y desaparecen los
     *                             recordatorios y comprobantes que se mandaron filmando.
     *
     * @return array  Resumen de lo que se hizo (o de lo que se haría con `solo_medir`).
     *
     * @throws \RuntimeException  Si la instalación no es una instancia de demo.
     */
    public static function preparar(User $owner, array $opciones = [])
    {
        if (! DemoSetupHelper::es_instancia_de_demo()) {
            throw new \RuntimeException('WhatsappDemoHelper solo se puede correr en una instancia de demo (FOR_USER=demo): pisa la clave de Kapso, aprueba plantillas y reescribe teléfonos.');
        }

        $opciones = array_merge([
            'chats'                => true,
            'telefonos_de_ficcion' => false,
            'solo_medir'           => false,
            'renovar_chats'        => false,
        ], $opciones);

        if (! is_null($owner->owner_id)) {
            throw new \RuntimeException('WhatsappDemoHelper espera al dueño de la instancia (owner_id null), no a un empleado.');
        }

        $resumen = [
            'owner_id'                => (int) $owner->id,
            'solo_medir'              => (bool) $opciones['solo_medir'],
            'config'                  => null,
            'plantillas_aprobadas'    => 0,
            'telefonos_reemplazados'  => 0,
            'telefonos_repetidos'     => 0,
            'clientes_con_telefono'   => 0,
            'telefonos_de_siembra'    => 0,
            'telefonos_reales'        => 0,
            'chats_con_telefono_real' => 0,
            'chats_eliminados'        => 0,
            'chats_sembrados'         => 0,
            'chats_omitidos'          => [],
        ];

        $medicion = self::medir_telefonos($owner->id);
        $resumen['clientes_con_telefono'] = $medicion['con_telefono'];
        $resumen['telefonos_de_siembra'] = $medicion['de_siembra'];
        $resumen['telefonos_reales'] = $medicion['reales'];
        $resumen['telefonos_repetidos'] = $medicion['repetidos'];
        $resumen['chats_con_telefono_real'] = self::chats_con_telefono_real($owner->id)->count();

        if ($opciones['solo_medir']) {
            return $resumen;
        }

        $resumen['config'] = self::preparar_config($owner->id);

        $resumen['plantillas_aprobadas'] = (int) WhatsappTemplateStandardHelper::aprobar_estandar_para($owner->id);

        // Los teléfonos van ANTES de los chats: el chat se arma con el teléfono del cliente, y el
        // índice único (user_id, phone) de `whatsapp_chats` no admite dos chats con el mismo.
        $reemplazos = self::ordenar_telefonos($owner->id, (bool) $opciones['telefonos_de_ficcion']);
        $resumen['telefonos_reemplazados'] = $reemplazos;

        // 🔴 Una base copiada de un cliente real trae SUS conversaciones de WhatsApp, con los
        // teléfonos reales de sus clientes grabados en `whatsapp_chats.phone` (y los envíos salen
        // a ese teléfono, no al del cliente). Con la opción de ficción se borran, con sus mensajes.
        if ($opciones['telefonos_de_ficcion']) {
            $resumen['chats_eliminados'] += self::eliminar_chats(self::chats_con_telefono_real($owner->id));
        }

        if ($opciones['renovar_chats']) {
            $resumen['chats_eliminados'] += self::eliminar_chats(self::chats_sembrados($owner->id));
        }

        if ($opciones['chats']) {
            $sembrado = self::sembrar_chats($owner->id);
            $resumen['chats_sembrados'] = $sembrado['sembrados'];
            $resumen['chats_omitidos'] = $sembrado['omitidos'];
        }

        Log::info('WhatsappDemoHelper: WhatsApp de la demo en modo envío simulado.', $resumen);

        return $resumen;
    }

    /**
     * Crea o actualiza la fila de configuración del bot: activa, en envío simulado y sin ninguna
     * credencial real.
     *
     * 🔴 LA CLAVE Y EL `phone_number_id` SE PISAN SIEMPRE por los ficticios. Una base que es copia
     * de la de un cliente real (demo3) puede traer SU clave de Kapso; dejarla viva significaría
     * que, si el modo demostración se apagara, la demo le escribiría a los clientes de ese cliente
     * con su número. El `webhook_secret` queda aleatorio y desconocido: ningún webhook de Kapso
     * lo puede firmar, así que nada entra desde afuera a una demo.
     *
     * @param  int  $owner_id
     * @return string  'creada' | 'actualizada'
     */
    private static function preparar_config($owner_id)
    {
        $config = WhatsappBotConfig::where('user_id', $owner_id)->first();
        $es_nueva = is_null($config);
        $ya_era_de_demo = ! $es_nueva && $config->kapso_api_key === self::CLAVE_FALSA && trim((string) $config->webhook_secret) !== '';

        if ($es_nueva) {
            $config = new WhatsappBotConfig();
            $config->user_id = $owner_id;
            // Sin personalidad ni habilidades propias: el agente usa los valores de fábrica, y la
            // IA de los chats nuevos nace prendida como en cualquier negocio nuevo.
            $config->ai_enabled_default = true;
        }

        // El botón «Simular mensaje del cliente» es parte de lo que se filma del módulo. Se prende
        // cuando la fila es nueva o cuando esta es la PRIMERA vez que se convierte en la de una demo
        // (una base copiada de un cliente real trae el valor de ese cliente, apagado de fábrica, que
        // no es una decisión de la demo). Si la fila ya era de este helper se respeta lo que se
        // haya decidido después.
        if (! $ya_era_de_demo) {
            $config->chat_simulation_enabled = true;
            $config->agent_personality_template = 'amigable';
            $owner = User::find($owner_id);
            $nombre = $owner ? ($owner->company_name ?: 'nuestro negocio demo') : 'nuestro negocio demo';
            $config->agent_personality = 'Sos el asistente de ' . $nombre . '. Hablás de vos, con calidez y de forma breve y práctica. Primero entendés la consulta y respondés con datos reales del catálogo. Hacés una sola pregunta a la vez. No inventás precios, stock ni condiciones comerciales.';
            $perfil = DemoIndustriaService::resolver(config('demo_industria_actual'));
            $rubro = $perfil ? $perfil['label'] : 'ferretería';
            $config->agent_skills = 'Conocés el rubro ' . $rubro . '. Ayudás a elegir el producto y su presentación, medida o compatibilidad. Si falta un dato, preguntás lo mínimo necesario. Recomendás solo productos presentes en el catálogo del negocio.';
            $config->agent_objective = WhatsappBotConfig::OBJETIVO_COMPRAR_ONLINE;
            $config->agent_objective_detail = 'Ayudá a resolver la consulta y, cuando el cliente quiera comprar, pasale el enlace real del producto para que compre en nuestra tienda online.';
            $config->ai_reply_delay_seconds = 10;
            if ($owner) {
                $owner->agente_proveedor = 'deepseek';
                $owner->agente_pensamiento = 'agil';
                $owner->ia_modelo_whatsapp = 'deepseek_flash';
                $owner->save();
            }
        }

        // Si la fila ya era de este helper se conserva su secreto (así dos corridas dejan lo
        // mismo); si no, se genera uno nuevo.

        $config->kapso_api_key = self::CLAVE_FALSA;
        $config->phone_number_id = self::PHONE_NUMBER_ID_FALSO;
        if (! $ya_era_de_demo) {
            $config->webhook_secret = Str::random(48);
        }
        $config->is_active = true;
        $config->envio_simulado = true;
        $config->save();

        return $es_nueva ? 'creada' : 'actualizada';
    }

    /**
     * Mide los teléfonos de los clientes del dueño: cuántos tienen, cuántos son los que dejan las
     * siembras (`ClientSeeder`: `3814500001`…, ver `es_telefono_de_siembra()`), cuántos NO son de
     * ninguna clase de ficción (ni de Lucas, ni del bloque de este helper, ni de la siembra) y
     * cuántos están repetidos entre clientes.
     *
     * 🔴 `reales` es una MEDICIÓN y no la regla de reemplazo: el patrón de la siembra es flojo
     * (10 dígitos con `500` en el medio) y en una base copiada de un cliente real puede coincidir
     * con un número verdadero, así que `ordenar_telefonos()` con la opción NO lo toma como ficción.
     *
     * @param  int  $owner_id
     * @return array  ['con_telefono' => int, 'de_siembra' => int, 'reales' => int, 'repetidos' => int]
     */
    private static function medir_telefonos($owner_id)
    {
        $clientes = Client::where('user_id', $owner_id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->get(['id', 'phone']);

        $con_telefono = 0;
        $de_siembra = 0;
        $reales = 0;
        $vistos = [];
        $repetidos = 0;

        foreach ($clientes as $cliente) {
            $digitos = WhatsappPhoneHelper::normalize($cliente->phone);

            if ($digitos === '') {
                continue;
            }

            $con_telefono++;

            if (self::es_telefono_de_siembra($digitos)) {
                $de_siembra++;
            } elseif (! self::es_telefono_de_ficcion($digitos)) {
                $reales++;
            }

            $clave = substr($digitos, -10);

            if (isset($vistos[$clave])) {
                $repetidos++;
            } else {
                $vistos[$clave] = true;
            }
        }

        return ['con_telefono' => $con_telefono, 'de_siembra' => $de_siembra, 'reales' => $reales, 'repetidos' => $repetidos];
    }

    /**
     * ¿Es un teléfono de los que dejan las siembras (`ClientSeeder`)? Diez dígitos con `500` en las
     * posiciones 5 a 7: `3814500001`, `3425500002`, `1145500003`… Solo sirve para MEDIR (ver
     * `medir_telefonos()`), nunca para decidir qué se reemplaza.
     *
     * @param  string  $digitos
     * @return bool
     */
    private static function es_telefono_de_siembra($digitos)
    {
        return preg_match('/^\d{4}500\d{3}$/', substr($digitos, -10)) === 1;
    }

    /**
     * ¿Este teléfono (solo dígitos) es uno que puede estar en una demo?: el de Lucas o uno del
     * bloque de ficción de este helper. Es una lista cerrada y estricta a propósito: un patrón
     * más amplio dejaría pasar números reales que casualmente lo cumplan.
     *
     * @param  string  $digitos
     * @return bool
     */
    private static function es_telefono_de_ficcion($digitos)
    {
        $ultimos = substr($digitos, -10);

        return $ultimos === self::TELEFONO_DE_LUCAS
            || (strlen($ultimos) === 10 && strpos($ultimos, self::PREFIJO_TELEFONO_DE_FICCION) === 0);
    }

    /**
     * Deja los teléfonos de los clientes aptos para una demo.
     *
     * - Siempre: ningún teléfono repetido entre clientes. Las siembras le dan el mismo número de
     *   Lucas a los primeros clientes, y el índice único (user_id, phone) de `whatsapp_chats` hace
     *   que un recordatorio de cobro al segundo cliente con el mismo teléfono reviente al crear
     *   su chat. Se queda con el número el primero (menor id); a los demás se les da uno de ficción.
     * - Con `$todos_de_ficcion`: además se reemplaza todo teléfono que no sea de Lucas ni del
     *   bloque de ficción (bases que son copia de un cliente real).
     *
     * Escribe con el query builder: no es una edición del cliente, no tiene que disparar
     * observers ni mover `updated_at`.
     *
     * @param  int   $owner_id
     * @param  bool  $todos_de_ficcion
     * @return int   Cuántos teléfonos se reemplazaron.
     */
    private static function ordenar_telefonos($owner_id, $todos_de_ficcion)
    {
        $clientes = Client::where('user_id', $owner_id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->get(['id', 'phone']);

        // El siguiente número libre del bloque de ficción: arranca después del más alto que ya haya.
        $siguiente = 1;
        foreach ($clientes as $cliente) {
            $ultimos = substr(WhatsappPhoneHelper::normalize($cliente->phone), -10);
            if (strlen($ultimos) === 10 && strpos($ultimos, self::PREFIJO_TELEFONO_DE_FICCION) === 0) {
                $siguiente = max($siguiente, ((int) substr($ultimos, 6)) + 1);
            }
        }

        $usados = [];
        $reemplazados = 0;

        foreach ($clientes as $cliente) {
            $digitos = WhatsappPhoneHelper::normalize($cliente->phone);

            if ($digitos === '') {
                continue;
            }

            $clave = substr($digitos, -10);
            $hay_que_reemplazar = isset($usados[$clave])
                || ($todos_de_ficcion && ! self::es_telefono_de_ficcion($digitos));

            if (! $hay_que_reemplazar) {
                $usados[$clave] = true;
                continue;
            }

            $nuevo = self::PREFIJO_TELEFONO_DE_FICCION . str_pad((string) $siguiente, 4, '0', STR_PAD_LEFT);
            $siguiente++;

            DB::table('clients')->where('id', $cliente->id)->update(['phone' => $nuevo]);

            $usados[$nuevo] = true;
            $reemplazados++;
        }

        return $reemplazados;
    }

    /**
     * Siembra los chats de ejemplo del módulo. Cada uno cuenta una situación distinta del tablero
     * y de los envíos que se filman:
     *
     *  1. `deudor_abierto`: cliente con deuda y la ventana de 24 h ABIERTA (escribió hace un rato)
     *     → el recordatorio de cobro sale por texto libre. Quedó sin responder (tarjeta roja).
     *  2. `deudor_cerrado`: cliente con deuda y la ventana CERRADA (escribió hace 3 días) → el
     *     recordatorio sale por plantilla con el PDF de la cuenta corriente.
     *  3. `comprobante`: cliente que pide su comprobante, ventana abierta → el comprobante de
     *     la venta sale como documento.
     *  4. `ia_a_confirmar`: una respuesta del agente de IA esperando confirmación (tarjeta
     *     amarilla), SIN fecha de auto-envío: no sale sola.
     *  5. `atendido`: conversación completa, ya contestada, con tildes de entregado y leído.
     *
     * Los clientes se eligen de los del dueño: los deudores salen de las ventas sin cobrar (la
     * misma definición que la solapa Cobros) y el resto, de los demás con teléfono. Un cliente
     * sin teléfono propio y único no sirve (un chat es por teléfono). Si no alcanzan clientes, se
     * siembran los que se pueda y se informa cuáles se omitieron: nunca se inventa un cliente.
     *
     * @param  int  $owner_id
     * @return array  ['sembrados' => int, 'omitidos' => string[]]
     */
    private static function sembrar_chats($owner_id)
    {
        $candidatos = self::clientes_con_telefono_unico($owner_id);

        // Ids de clientes con deuda viva, con la MISMA query que la solapa Cobros.
        $ids_deudores = VentasSinCobrarHelper::query_de_ventas($owner_id, null, 0)
            ->whereNotNull('client_id')
            ->distinct()
            ->pluck('client_id')
            ->all();

        $deudores = [];
        $otros = [];

        foreach ($candidatos as $cliente) {
            if (in_array($cliente->id, $ids_deudores)) {
                $deudores[] = $cliente;
            } else {
                $otros[] = $cliente;
            }
        }

        $planes = [
            'deudor_abierto' => array_shift($deudores),
            'deudor_cerrado' => array_shift($deudores),
            'comprobante'    => array_shift($otros),
            'ia_a_confirmar' => array_shift($otros),
            'atendido'       => array_shift($otros),
        ];

        $sembrados = 0;
        $omitidos = [];

        foreach ($planes as $tipo => $cliente) {
            if (is_null($cliente)) {
                $omitidos[] = $tipo . ': no hay un cliente con teléfono propio' . (strpos($tipo, 'deudor') === 0 ? ' y deuda' : '');
                continue;
            }

            $phone = WhatsappPhoneHelper::normalize($cliente->phone);

            if (WhatsappChat::where('user_id', $owner_id)->where('client_id', $cliente->id)->exists()
                || WhatsappChat::where('user_id', $owner_id)->where('phone', $phone)->exists()) {
                $omitidos[] = $tipo . ': el cliente ya tenía un chat';
                continue;
            }

            self::sembrar_chat($owner_id, $cliente, $phone, $tipo);
            $sembrados++;
        }

        return ['sembrados' => $sembrados, 'omitidos' => $omitidos];
    }

    /**
     * Los chats del dueño cuyo teléfono no es de ficción (ni el de Lucas, ni el bloque de este
     * helper, ni el de la siembra): son conversaciones que vinieron en una base copiada de un
     * cliente real. Un chat sembrado por este helper usa el teléfono de su cliente, que
     * `ordenar_telefonos()` ya dejó de ficción, así que no entra acá.
     *
     * @param  int  $owner_id
     * @return \Illuminate\Support\Collection
     */
    private static function chats_con_telefono_real($owner_id)
    {
        return WhatsappChat::where('user_id', $owner_id)
            ->get(['id', 'phone'])
            ->filter(function ($chat) {
                $digitos = WhatsappPhoneHelper::normalize($chat->phone);

                return $digitos !== '' && ! self::es_telefono_de_ficcion($digitos) && ! self::es_telefono_de_siembra($digitos);
            })
            ->values();
    }

    /**
     * Los chats que sembró este helper, reconocidos por el texto del primer mensaje (el entrante
     * con el que arranca cada guion de `guion()`): no hay una columna que los marque y no se
     * agrega una migración solo para esto. Un chat que alguien armó filmando tiene otro primer
     * mensaje y no se toca.
     *
     * @param  int  $owner_id
     * @return \Illuminate\Support\Collection
     */
    private static function chats_sembrados($owner_id)
    {
        $primeros = [];
        foreach (['deudor_abierto', 'deudor_cerrado', 'comprobante', 'ia_a_confirmar', 'atendido'] as $tipo) {
            $guion = self::guion($tipo);
            $primeros[] = $guion[0]['body'];
        }

        return WhatsappChat::where('user_id', $owner_id)
            ->get(['id'])
            ->filter(function ($chat) use ($primeros) {
                $primero = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->first(['body']);

                return ! is_null($primero) && in_array($primero->body, $primeros, true);
            })
            ->values();
    }

    /**
     * Borra chats con todos sus mensajes.
     *
     * @param  \Illuminate\Support\Collection  $chats  Chats (con `id`).
     * @return int  Cuántos chats se borraron.
     */
    private static function eliminar_chats($chats)
    {
        $ids = $chats->pluck('id')->all();

        if (count($ids) === 0) {
            return 0;
        }

        WhatsappChatMessage::whereIn('whatsapp_chat_id', $ids)->delete();
        WhatsappChat::whereIn('id', $ids)->delete();

        return count($ids);
    }

    /**
     * Los clientes del dueño con teléfono cargado, uno por teléfono (los repetidos ya los resolvió
     * `ordenar_telefonos()`, pero igual se descarta defensivamente al segundo). Ordenados por id,
     * así la elección es la misma en cada corrida.
     *
     * @param  int  $owner_id
     * @return \Illuminate\Support\Collection
     */
    private static function clientes_con_telefono_unico($owner_id)
    {
        $vistos = [];

        // Solo las columnas que se usan: en una base copiada de un cliente real pueden ser decenas
        // de miles de clientes y hidratar el modelo entero se come la memoria.
        return Client::where('user_id', $owner_id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->get(['id', 'name', 'phone'])
            ->filter(function ($cliente) use (&$vistos) {
                $digitos = WhatsappPhoneHelper::normalize($cliente->phone);

                if ($digitos === '') {
                    return false;
                }

                $clave = substr($digitos, -10);

                if (isset($vistos[$clave])) {
                    return false;
                }

                $vistos[$clave] = true;

                return true;
            })
            ->values();
    }

    /**
     * Crea un chat con su conversación según el tipo. Todos los mensajes llevan fecha propia
     * (relativa a ahora, en minutos hacia atrás) para que la ventana de 24 h quede como la
     * describe cada tipo y los horarios de la bandeja se vean coherentes.
     *
     * @param  int     $owner_id
     * @param  Client  $cliente
     * @param  string  $phone   Teléfono normalizado (solo dígitos).
     * @param  string  $tipo    Una de las claves de `sembrar_chats()`.
     * @return WhatsappChat
     */
    private static function sembrar_chat($owner_id, Client $cliente, $phone, $tipo)
    {
        $guion = self::guion($tipo);

        // `in` = el cliente escribió; el resto son salientes. `minutos` es hace cuánto.
        $ultimo_entrante = null;
        $ultimo_mensaje = null;
        $sin_leer = 0;

        foreach ($guion as $mensaje) {
            $momento = now()->subMinutes($mensaje['minutos']);

            if ($mensaje['direccion'] === 'in') {
                if (is_null($ultimo_entrante) || $momento->gt($ultimo_entrante)) {
                    $ultimo_entrante = $momento;
                }
            }

            if (is_null($ultimo_mensaje) || $momento->gt($ultimo_mensaje)) {
                $ultimo_mensaje = $momento;
            }
        }

        // No leídos: los entrantes que vinieron después del último saliente real (no pendiente).
        $ultimo_saliente = null;
        foreach ($guion as $mensaje) {
            if ($mensaje['direccion'] === 'out' && (! isset($mensaje['ai_status']) || $mensaje['ai_status'] !== 'a_confirmar')) {
                $momento = now()->subMinutes($mensaje['minutos']);
                if (is_null($ultimo_saliente) || $momento->gt($ultimo_saliente)) {
                    $ultimo_saliente = $momento;
                }
            }
        }
        foreach ($guion as $mensaje) {
            if ($mensaje['direccion'] === 'in' && (is_null($ultimo_saliente) || now()->subMinutes($mensaje['minutos'])->gt($ultimo_saliente))) {
                $sin_leer++;
            }
        }

        $chat = WhatsappChat::create([
            'user_id'                => $owner_id,
            'phone'                  => $phone,
            'client_id'              => $cliente->id,
            'ai_enabled'             => true,
            'last_message_at'        => $ultimo_mensaje,
            'last_inbound_at'        => $ultimo_entrante,
            // 🔴 0, y a propósito: son entrantes que "escribió el cliente", no inyectados por
            // «Simular mensaje». Con 1 la SPA les pondría la etiqueta «Simulado» y el chat
            // quedaría marcado «SIM» en la bandeja.
            'last_inbound_simulated' => 0,
            'unread_count'           => $sin_leer,
        ]);

        foreach ($guion as $mensaje) {
            $momento = now()->subMinutes($mensaje['minutos']);
            $es_saliente = $mensaje['direccion'] === 'out';
            $pendiente = isset($mensaje['ai_status']) && $mensaje['ai_status'] === 'a_confirmar';

            WhatsappChatMessage::create([
                'whatsapp_chat_id' => $chat->id,
                'direction'        => $mensaje['direccion'],
                'source'           => $mensaje['source'],
                'sent_by_user_id'  => null,
                'body'             => $mensaje['body'],
                // Los salientes ya enviados llevan un id del modo demostración (para que se
                // distingan en la base de uno real); el pendiente de confirmar no salió.
                'wa_message_id'    => ($es_saliente && ! $pendiente)
                    ? WhatsappBotSendService::PREFIJO_ID_SIMULADO . 'sembrado.' . bin2hex(random_bytes(8))
                    : null,
                'delivery_status'  => $es_saliente ? ($pendiente ? 'pendiente' : $mensaje['entrega']) : 'pendiente',
                'ai_status'        => isset($mensaje['ai_status']) ? $mensaje['ai_status'] : null,
                // 🔴 0: el entrante lo "escribió el cliente" (ver arriba) y los salientes no son
                // respuesta a una simulación.
                'is_simulated'     => 0,
                'created_at'       => $momento,
                'updated_at'       => $momento,
            ]);
        }

        return $chat;
    }

    /**
     * La conversación de cada tipo de chat: lista de mensajes con `direccion`, `source`, `body`,
     * hace cuántos `minutos`, y para los salientes `entrega` (`delivery_status`) y, si es de la IA
     * pendiente, `ai_status`. Texto en español rioplatense y sin datos del negocio (precios, stock,
     * marcas): sirve para cualquier rubro que arme la demo.
     *
     * @param  string  $tipo
     * @return array
     */
    private static function guion($tipo)
    {
        switch ($tipo) {
            case 'deudor_abierto':
                return [
                    ['direccion' => 'in',  'source' => 'cliente', 'minutos' => 205, 'body' => 'Hola, buen día! Quería consultarles si ya tienen listo el pedido que les hice la semana pasada'],
                    ['direccion' => 'out', 'source' => 'manual',  'minutos' => 190, 'body' => 'Hola! Sí, ya lo tenemos preparado. Podés pasar a retirarlo cuando quieras', 'entrega' => 'leido'],
                    ['direccion' => 'in',  'source' => 'cliente', 'minutos' => 40,  'body' => 'Perfecto, paso esta tarde. Gracias!'],
                ];

            case 'deudor_cerrado':
                return [
                    ['direccion' => 'in',  'source' => 'cliente', 'minutos' => 4330, 'body' => 'Buenas tardes, necesito el comprobante de mi última compra por favor'],
                    ['direccion' => 'out', 'source' => 'manual',  'minutos' => 4320, 'body' => 'Hola! Te lo mando enseguida', 'entrega' => 'leido'],
                ];

            case 'comprobante':
                return [
                    ['direccion' => 'in', 'source' => 'cliente', 'minutos' => 85, 'body' => 'Hola! Acabo de hacer una compra, me podrían mandar el comprobante por acá?'],
                ];

            case 'ia_a_confirmar':
                return [
                    ['direccion' => 'in',  'source' => 'cliente', 'minutos' => 30, 'body' => 'Hola! Quería saber si tienen stock del artículo que vi en la tienda online y cuánto sale con envío'],
                    ['direccion' => 'out', 'source' => 'ia',      'minutos' => 29, 'body' => 'Hola! Gracias por escribirnos. Te confirmo en un momento si tenemos stock y cuánto sería el envío a tu zona.', 'ai_status' => 'a_confirmar'],
                ];

            case 'atendido':
            default:
                return [
                    ['direccion' => 'in',  'source' => 'cliente', 'minutos' => 300, 'body' => 'Hola, a qué hora cierran hoy?'],
                    ['direccion' => 'out', 'source' => 'ia',      'minutos' => 299, 'body' => 'Hola! Hoy atendemos hasta las 20 hs. Te esperamos!', 'entrega' => 'leido', 'ai_status' => 'enviado'],
                    ['direccion' => 'in',  'source' => 'cliente', 'minutos' => 290, 'body' => 'Genial, gracias'],
                    ['direccion' => 'out', 'source' => 'manual',  'minutos' => 280, 'body' => 'De nada! Cualquier cosa avisanos', 'entrega' => 'leido'],
                ];
        }
    }
}
