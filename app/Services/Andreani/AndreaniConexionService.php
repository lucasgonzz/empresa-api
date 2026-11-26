<?php

namespace App\Services\Andreani;

use App\Http\Controllers\Helpers\AndreaniCredentialsHelper;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Services\Zipnova\ZipnovaPaquetesHelper;
use Carbon\Carbon;

/**
 * La conexión del comercio con Andreani y sus preferencias, tal como las maneja la tarjeta «Envíos (Andreani)» de
 * ABM -> Integraciones -> Tienda online (misión envios-andreani, 25/11/2026).
 *
 * Todo vive en el conector `andreani` de `platform_connectors` del comercio (contrato con `tienda-api`, que lo lee
 * con `AndreaniCredentialsHelper`):
 *
 *  - `platform_user_id` = número de cliente de Andreani (ej. `CL0003750`).
 *  - `access_token`     = JSON `{"usuario":null,"password":null}` cifrado por el cast `encrypted` del modelo. En la
 *                         etapa 1 no hay usuario ni contraseña (la cotización es pública); se guarda no vacío para
 *                         que `PlatformConnector::is_connected()` funcione sin tocar el modelo.
 *  - `extra_config`     = contratos, sucursal de origen, bulto por defecto, declarar valor, envío gratis desde,
 *                         «ofrecer en la tienda» y fecha de conexión. Nada secreto.
 *
 * Conectar es la única operación que habla con Andreani con datos que todavía no están guardados: se prueban con una
 * tarifa de prueba y recién si Andreani responde se persiste el conector. Un número de cliente o contrato mal
 * tipeado nunca llega a la base.
 *
 * Errores: `AndreaniException` (Andreani rechazó los datos o no respondió) y `\DomainException` (el comercio no
 * tiene Andreani conectado, o falta la plataforma en el catálogo), con el mensaje listo para el operador.
 *
 * Solo `empresa-api`: la tienda lee el conector (`AndreaniCredentialsHelper`), no lo escribe.
 */
class AndreaniConexionService
{
    /** Código postal «seguro» (CABA) con el que se prueba que el cliente y el contrato son válidos. */
    const CP_DE_PRUEBA_AL_CONECTAR = '1425';

    /** Valor declarado fijo de la cotización de prueba de la tarjeta («Probá cómo lo ve tu cliente»). */
    const VALOR_DE_PRUEBA = 1000;

    /**
     * Conecta Andreani para el comercio: valida cliente y contratos contra Andreani y guarda el conector.
     *
     * Reconectar reemplaza las preferencias (pueden ser de otro contrato). El contrato de sucursal, si viene, se
     * valida igual que el de domicilio.
     *
     * @param int $user_id Comercio (owner).
     * @param string $cliente Número de cliente de Andreani.
     * @param string $contrato_domicilio Contrato para envíos a domicilio.
     * @param string|null $contrato_sucursal Contrato para retiro en sucursal (opcional).
     * @param int|null $sucursal_origen Sucursal desde la que despacha el comercio (opcional).
     * @return PlatformConnector El conector ya conectado.
     * @throws AndreaniException Si Andreani rechazó los datos (400) o no respondió.
     * @throws \DomainException Si falta la plataforma en el catálogo de esta instancia.
     */
    public function conectar($user_id, $cliente, $contrato_domicilio, $contrato_sucursal = null, $sucursal_origen = null)
    {
        $cliente = trim((string) $cliente);
        $contrato_domicilio = trim((string) $contrato_domicilio);
        $contrato_sucursal = is_null($contrato_sucursal) || trim((string) $contrato_sucursal) === '' ? null : trim((string) $contrato_sucursal);
        $sucursal_origen = is_numeric($sucursal_origen) ? (int) $sucursal_origen : null;

        // Un bulto mínimo (1 kg, 1000 cm3, sin valor) a un CP que existe: solo prueba cliente y contrato.
        $bulto_de_prueba = [['valorDeclarado' => 0, 'volumen' => 1000, 'kilos' => 1]];

        $client = new AndreaniClient();

        $client->tarifa($cliente, $contrato_domicilio, self::CP_DE_PRUEBA_AL_CONECTAR, $bulto_de_prueba, $sucursal_origen);

        if (!is_null($contrato_sucursal)) {
            $client->tarifa($cliente, $contrato_sucursal, self::CP_DE_PRUEBA_AL_CONECTAR, $bulto_de_prueba, $sucursal_origen);
        }

        $connector = PlatformConnector::find_or_create_for_user_and_slug((int) $user_id, Platform::SLUG_ANDREANI);
        if (is_null($connector)) {
            throw new \DomainException('Falta la plataforma Andreani en el catálogo de esta instancia. Hay que correr las migraciones pendientes.');
        }

        $config = AndreaniCredentialsHelper::config_defaults();
        $config['contrato_domicilio'] = $contrato_domicilio;
        $config['contrato_sucursal'] = $contrato_sucursal;
        $config['sucursal_origen'] = $sucursal_origen;
        $config['conectado_en'] = Carbon::now()->toIso8601String();

        $connector->access_token = json_encode(['usuario' => null, 'password' => null]);
        $connector->refresh_token = null;
        $connector->auth_code = null;
        $connector->public_key = null;
        $connector->expires_at = null;
        $connector->platform_user_id = $cliente;
        $connector->status = PlatformConnector::STATUS_CONECTADO;
        $connector->error_message = null;
        $connector->extra_config = $config;
        $connector->save();

        return $connector;
    }

    /**
     * Desconecta: limpia credenciales, cliente y preferencias del conector. La fila queda en `sin_conectar` para que
     * el próximo conectar la reutilice. No hay webhook que dar de baja: nunca sale nada a la red.
     *
     * @param int $user_id Comercio (owner).
     * @return PlatformConnector|null null si el comercio nunca tuvo conector.
     */
    public function desconectar($user_id)
    {
        $connector = PlatformConnector::find_for_user_and_slug((int) $user_id, Platform::SLUG_ANDREANI);
        if (is_null($connector)) {
            return null;
        }

        $connector->access_token = null;
        $connector->refresh_token = null;
        $connector->auth_code = null;
        $connector->public_key = null;
        $connector->expires_at = null;
        $connector->platform_user_id = null;
        $connector->status = PlatformConnector::STATUS_SIN_CONECTAR;
        $connector->error_message = null;
        $connector->extra_config = null;
        $connector->save();

        return $connector;
    }

    /**
     * Guarda las preferencias que la tarjeta edita. Solo se tocan las claves que vienen: omitir preserva y `null`
     * limpia (envío gratis, contrato de sucursal, sucursal de origen).
     *
     * @param int $user_id Comercio (owner).
     * @param array $cambios `{bulto_default?{peso,alto,ancho,profundidad}, declarar_valor?, envio_gratis_desde?, ofrecer_en_tienda?, contrato_sucursal?, sucursal_origen?}`, ya validados por el controller.
     * @return PlatformConnector
     * @throws \DomainException Si Andreani no está conectado.
     */
    public function guardar_config($user_id, array $cambios)
    {
        $connector = $this->conector_conectado($user_id);

        $config = AndreaniCredentialsHelper::config_desde_conector($connector);

        if (isset($cambios['bulto_default']) && is_array($cambios['bulto_default'])) {
            // Campo por campo sobre lo que ya había: el comercio puede mandar solo el peso.
            $bulto = is_array($config['bulto_default']) ? $config['bulto_default'] : [];
            foreach (['peso', 'alto', 'ancho', 'profundidad'] as $campo) {
                if (isset($cambios['bulto_default'][$campo]) && is_numeric($cambios['bulto_default'][$campo])) {
                    $bulto[$campo] = (float) $cambios['bulto_default'][$campo];
                }
            }
            $config['bulto_default'] = ZipnovaPaquetesHelper::bulto_default_completo($bulto);
        }

        foreach (['declarar_valor', 'ofrecer_en_tienda'] as $clave) {
            if (array_key_exists($clave, $cambios) && !is_null($cambios[$clave])) {
                $config[$clave] = filter_var($cambios[$clave], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('envio_gratis_desde', $cambios)) {
            $desde = $cambios['envio_gratis_desde'];
            // Cero o vacío es "sin envío gratis por monto": se guarda null (regla apagada).
            $config['envio_gratis_desde'] = is_numeric($desde) && (float) $desde > 0 ? round((float) $desde, 2) : null;
        }

        if (array_key_exists('contrato_sucursal', $cambios)) {
            $contrato = $cambios['contrato_sucursal'];
            $config['contrato_sucursal'] = is_null($contrato) || trim((string) $contrato) === '' ? null : trim((string) $contrato);
        }

        if (array_key_exists('sucursal_origen', $cambios)) {
            $config['sucursal_origen'] = is_numeric($cambios['sucursal_origen']) ? (int) $cambios['sucursal_origen'] : null;
        }

        $connector->extra_config = $config;
        $connector->save();

        return $connector;
    }

    /**
     * Cotización de prueba para la tarjeta («Probá cómo lo ve tu cliente»): un solo bulto con el bulto por defecto
     * del comercio y un valor declarado fijo de $1000 (0 si no declara valor), al código postal que escribió el
     * operador. Aplica los mismos topes de paquetería que la tienda.
     *
     * @param int $user_id Comercio (owner).
     * @param string $zipcode Código postal de destino.
     * @return array `{zipcode, motivo: null|'excede_medidas'|'sin_tarifa', envio_gratis: false, opciones: [...]}`.
     * @throws \DomainException Si Andreani no está conectado.
     * @throws AndreaniException Si Andreani no respondió (caído, timeout): el controller responde 502.
     */
    public function cotizar_prueba($user_id, $zipcode)
    {
        $credentials = AndreaniCredentialsHelper::credentials((int) $user_id);

        if (is_null($credentials['cliente'])) {
            throw new \DomainException('Andreani no está conectado. Cargá tu número de cliente y tu contrato y tocá "Conectar".');
        }

        $config = $credentials['config'];
        $zipcode = trim((string) $zipcode);

        $respuesta = ['zipcode' => $zipcode, 'motivo' => null, 'envio_gratis' => false, 'opciones' => []];

        // Un objeto vacío: `item_desde_articulo` cae al bulto por defecto campo por campo.
        $item = ZipnovaPaquetesHelper::item_desde_articulo((object) [], $config['bulto_default']);
        $valor_declarado = $config['declarar_valor'] ? self::VALOR_DE_PRUEBA : 0;

        $armado = AndreaniTarifaNormalizer::bultos_desde_items([$item], $valor_declarado);
        $bultos = $armado['bultos'];

        foreach ($bultos as $bulto) {
            if (AndreaniTarifaNormalizer::excede_limites($bulto)) {
                $respuesta['motivo'] = 'excede_medidas';

                return $respuesta;
            }
        }

        $consulta = AndreaniTarifaNormalizer::bultos_para_consulta($bultos, $config['declarar_valor']);
        $client = new AndreaniClient();

        try {
            $tarifa = $client->tarifa($credentials['cliente'], $config['contrato_domicilio'], $zipcode, $consulta, $config['sucursal_origen']);
        } catch (AndreaniException $e) {
            if ($e->esDeValidacion()) {
                // Andreani no tiene tarifa para ese CP (o el contrato dejó de valer): no es una caída.
                $respuesta['motivo'] = 'sin_tarifa';

                return $respuesta;
            }

            throw $e;
        }

        $opcion = AndreaniTarifaNormalizer::opcion(AndreaniTarifaNormalizer::TIPO_DOMICILIO, $tarifa, false, [], $bultos, $valor_declarado);
        if (!is_null($opcion)) {
            $respuesta['opciones'][] = $opcion;
        }

        // Retiro en sucursal: solo con contrato de sucursal y al menos una sucursal que atienda. Es opcional: si
        // algo falla acá, la prueba sigue con el domicilio.
        if (!is_null($config['contrato_sucursal'])) {
            $opcion_sucursal = $this->opcion_de_sucursal($client, $credentials['cliente'], $config, $zipcode, $consulta, $bultos, $valor_declarado);
            if (!is_null($opcion_sucursal)) {
                $respuesta['opciones'][] = $opcion_sucursal;
            }
        }

        usort($respuesta['opciones'], function ($a, $b) {
            if ($a['precio'] != $b['precio']) {
                return $a['precio'] < $b['precio'] ? -1 : 1;
            }

            return strcmp($a['key'], $b['key']);
        });

        return $respuesta;
    }

    /**
     * La opción de retiro en sucursal para la prueba, o null si no hay sucursales que sirvan o Andreani no dio
     * tarifa para ese contrato.
     *
     * @param AndreaniClient $client
     * @param string $cliente
     * @param array $config Config del conector (con defaults).
     * @param string $zipcode
     * @param array $consulta Bultos con las claves de la API de tarifas.
     * @param array $bultos Bultos del normalizador.
     * @param float|int $valor_declarado
     * @return array|null
     */
    protected function opcion_de_sucursal(AndreaniClient $client, $cliente, array $config, $zipcode, array $consulta, array $bultos, $valor_declarado)
    {
        try {
            $puntos = AndreaniTarifaNormalizer::puntos_de_retiro($client->sucursales($zipcode));
            if (count($puntos) === 0) {
                return null;
            }

            $tarifa = $client->tarifa($cliente, $config['contrato_sucursal'], $zipcode, $consulta, $config['sucursal_origen']);
        } catch (AndreaniException $e) {
            return null;
        }

        return AndreaniTarifaNormalizer::opcion(AndreaniTarifaNormalizer::TIPO_SUCURSAL, $tarifa, false, $puntos, $bultos, $valor_declarado);
    }

    /**
     * Conector de Andreani del comercio, conectado, o excepción con el mensaje para el operador.
     *
     * @param int $user_id
     * @return PlatformConnector
     * @throws \DomainException
     */
    protected function conector_conectado($user_id)
    {
        $connector = AndreaniCredentialsHelper::connector((int) $user_id);

        if (is_null($connector)) {
            throw new \DomainException('Andreani no está conectado. Cargá tu número de cliente y tu contrato y tocá "Conectar".');
        }

        return $connector;
    }
}
