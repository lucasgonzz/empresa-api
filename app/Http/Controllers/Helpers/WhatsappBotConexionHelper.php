<?php

namespace App\Http\Controllers\Helpers;

use App\Models\WhatsappBotConfig;
use Illuminate\Support\Facades\Log;

/**
 * Lo que comparten las dos puertas que escriben la conexión de WhatsApp (Kapso) del comercio — misión
 * kapso-secretos-fuera-del-navegador (17/11/2026):
 *
 *   - `WhatsappBotController::update_config()`: la pantalla del dueño. NO puede cambiar credenciales.
 *   - `AdminSync\WhatsappBotConexionController`: la puerta de provisión del admin (`admin.api.key:siempre`).
 *     Es el único camino que escribe `phone_number_id`, `kapso_api_key` y `webhook_secret`.
 *
 * Acá viven el guard de coherencia (no se activa el bot sin las tres credenciales finales), el relleno de
 * las columnas NOT NULL en el alta y el armado del estado que se le muestra al que pregunta. Ese estado
 * NUNCA incluye los valores de `kapso_api_key` ni de `webhook_secret`: solo si están cargados.
 */
class WhatsappBotConexionHelper
{
    /** Lo que se le contesta al dueño que intenta cargar o cambiar credenciales. */
    const MENSAJE_SOLO_COMERCIOCITY = 'Las credenciales de WhatsApp las configura el equipo de ComercioCity.';

    /** El rechazo del guard de coherencia. */
    const MENSAJE_SIN_CREDENCIALES = 'No se puede activar el bot sin phone_number_id, kapso_api_key y webhook_secret.';

    /** Las tres columnas técnicas (NOT NULL, sin default). */
    const CAMPOS_TECNICOS = ['phone_number_id', 'kapso_api_key', 'webhook_secret'];

    /**
     * Guard de coherencia: el bot no se puede dejar activo sin las tres credenciales. Se mira el valor
     * FINAL de cada campo (el que viene en `$data` o, si no viene, el ya persistido).
     *
     * @param  WhatsappBotConfig|null  $config_actual  La fila ya guardada, o null si todavía no existe.
     * @param  array                   $data           Lo que se va a escribir.
     * @return string|null  El mensaje de rechazo, o null si se puede seguir.
     */
    public static function error_de_activacion(?WhatsappBotConfig $config_actual, array $data): ?string
    {
        $va_a_quedar_activo = array_key_exists('is_active', $data)
            ? (bool) $data['is_active']
            : (! is_null($config_actual) ? (bool) $config_actual->is_active : false);

        if (! $va_a_quedar_activo) {
            return null;
        }

        foreach (self::CAMPOS_TECNICOS as $campo_tecnico) {
            $valor_final = array_key_exists($campo_tecnico, $data)
                ? $data[$campo_tecnico]
                : (! is_null($config_actual) ? $config_actual->getAttribute($campo_tecnico) : null);

            if (trim((string) $valor_final) === '') {
                return self::MENSAJE_SIN_CREDENCIALES;
            }
        }

        return null;
    }

    /**
     * Resuelve la configuración que le corresponde a un webhook de Kapso SIN mirar `is_active`.
     *
     * 🔴 Misión whatsapp-canal-independiente (27/11/2026): `is_active` es la llave del AGENTE (genera y manda
     * respuestas solo), no la del canal. Antes `receive()` elegía `where('is_active', true)->first()` y, con el
     * agente apagado, contestaba 200 y descartaba entrantes y estados de entrega sin dejar ni una línea de log.
     * No volver a filtrar por `is_active` acá: apagar el agente no puede apagar la bandeja.
     *
     * Regla (se prefiere no resolver antes que adivinar un comercio ajeno):
     *   1. Si el payload trae `phone_number_id` (raíz o `conversation`, mismo orden que Coexistencia) y UNA sola
     *      fila lo tiene → esa fila, activa o no. Dos filas con el mismo número → null (no se adivina).
     *   2. Si trae uno que no coincide con ninguna fila → sólo se usa la fila si la tabla tiene exactamente una
     *      (instancia de un solo comercio con el número desactualizado). Con varias → null.
     *   3. Si no trae ninguno (payloads viejos) → la única fila de la tabla; con varias, la única activa; si no
     *      queda una sola candidata → null.
     *
     * @param  array  $payload  Payload ya decodificado del webhook.
     * @return WhatsappBotConfig|null
     */
    public static function config_del_webhook(array $payload): ?WhatsappBotConfig
    {
        $pnid = '';
        if (isset($payload['phone_number_id']) && is_scalar($payload['phone_number_id']) && trim((string) $payload['phone_number_id']) !== '') {
            $pnid = trim((string) $payload['phone_number_id']);
        } elseif (isset($payload['conversation']['phone_number_id']) && is_scalar($payload['conversation']['phone_number_id'])) {
            $pnid = trim((string) $payload['conversation']['phone_number_id']);
        }

        if ($pnid !== '') {
            $coincidentes = WhatsappBotConfig::where('phone_number_id', $pnid)->limit(2)->get();
            if ($coincidentes->count() === 1) {
                return $coincidentes->first();
            }
            if ($coincidentes->count() > 1) {
                Log::channel('daily')->warning('WhatsappBotConexionHelper: el phone_number_id del webhook está en más de una configuración, se ignora.');

                return null;
            }

            Log::channel('daily')->warning('WhatsappBotConexionHelper: el phone_number_id del webhook no coincide con ninguna configuración.');

            $todas = WhatsappBotConfig::limit(2)->get();

            return $todas->count() === 1 ? $todas->first() : null;
        }

        $todas = WhatsappBotConfig::limit(2)->get();
        if ($todas->count() === 1) {
            return $todas->first();
        }

        $activas = WhatsappBotConfig::where('is_active', true)->limit(2)->get();

        return $activas->count() === 1 ? $activas->first() : null;
    }

    /**
     * Crea o actualiza la fila del comercio. Las tres columnas técnicas son NOT NULL sin default: en el alta
     * se rellenan con '' las que no vinieron — solo en el alta, para no pisar nunca lo ya guardado.
     *
     * @param  int                     $user_id
     * @param  WhatsappBotConfig|null  $config_actual
     * @param  array                   $data
     * @return WhatsappBotConfig
     */
    public static function guardar(int $user_id, ?WhatsappBotConfig $config_actual, array $data): WhatsappBotConfig
    {
        if (is_null($config_actual)) {
            foreach (self::CAMPOS_TECNICOS as $campo_tecnico) {
                if (! array_key_exists($campo_tecnico, $data)) {
                    $data[$campo_tecnico] = '';
                }
            }
        }

        return WhatsappBotConfig::updateOrCreate(['user_id' => $user_id], $data);
    }

    /**
     * El estado de la conexión para la puerta de provisión (forma del contrato A). Sin secretos.
     *
     * @param  WhatsappBotConfig|null  $config
     * @return array
     */
    public static function estado(?WhatsappBotConfig $config): array
    {
        if (is_null($config)) {
            return [
                'existe'                     => false,
                'phone_number_id'            => null,
                'kapso_api_key_configurada'  => false,
                'webhook_secret_configurado' => false,
                'conexion_configurada'       => false,
                'is_active'                  => false,
            ];
        }

        $phone_number_id = trim((string) $config->phone_number_id);

        return [
            'existe'                     => true,
            'phone_number_id'            => $phone_number_id === '' ? null : $phone_number_id,
            'kapso_api_key_configurada'  => (bool) $config->kapso_api_key_configurada,
            'webhook_secret_configurado' => (bool) $config->webhook_secret_configurado,
            'conexion_configurada'       => (bool) $config->conexion_configurada,
            'is_active'                  => (bool) $config->is_active,
        ];
    }
}
