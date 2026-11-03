<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Cuota;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Una sola confirmación para las cuotas de Mercado Pago de la tienda (Masquito, ticket 45).
 * La tienda lee cuotas específicas del método de cuenta corriente vinculado; sus cuotas antiguas
 * de payment_method_installments NO tienen recargo. No se toca ninguna credencial ni se cobra.
 *
 * El lote se ejecuta dentro de la transacción del ejecutor. Además del candado de la tarjeta se
 * toma el del dueño: dos conversaciones pueden proponer el mismo lote y no deben duplicarlo.
 * La huella de la configuración evita pisar una edición hecha desde la pantalla tras la propuesta.
 */
class PropuestaCuotasTiendaIaHelper
{
    const AVISO = 'Solo configura las cuotas y sus recargos. No conecta tu cuenta ni realiza cobros. Para que Mercado Pago no agregue otro interés, revisá las cuotas sin interés en tu cuenta de Mercado Pago.';

    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        try {
            self::validar_contexto($contexto);
            $cuotas = self::normalizar_cuotas(isset($input['cuotas']) ? $input['cuotas'] : null);
            $metodo = isset($input['metodo']) ? $input['metodo'] : 'Mercado Pago';
            $estado = self::estado($contexto, $metodo, $cuotas);
        } catch (AccionIaException $e) {
            return RespuestaDeCargaIa::error($e->getMessage());
        }

        $renglones = [
            ['etiqueta' => 'Método de la tienda', 'valor' => $estado['tienda'] ? $estado['tienda']['name'] : 'Mercado Pago (nuevo)'],
            ['etiqueta' => 'Método de cuenta corriente', 'valor' => $estado['cuenta'] ? $estado['cuenta']['name'] : 'Mercado Pago (nuevo)'],
            ['etiqueta' => 'Vínculo de la tienda', 'valor' => $estado['tienda'] && $estado['tienda']['current_acount_payment_method_id'] ? 'Se conserva el vínculo actual' : 'Se vincula con el método de cuenta corriente indicado'],
        ];
        foreach ($cuotas as $cuota) {
            $previa = self::previa($estado, $cuota['cantidad_cuotas']);
            $antes = $previa ? self::porcentaje((float) $previa['recargo'] - (float) $previa['descuento']) : 'sin configurar';
            $renglones[] = ['etiqueta' => $cuota['cantidad_cuotas'] . ' cuotas', 'valor' => $antes . ' → ' . self::porcentaje($cuota['recargo']) . ' de recargo y 0,00% de descuento; coeficiente ' . number_format(1 + $cuota['recargo'] / 100, 4, ',', '')];
        }
        $creada = AccionesIaHelper::crear($contexto, $mensaje, AiMessageAction::TIPO_CUOTAS_TIENDA,
            'cuotas_tienda:' . ($estado['tienda'] ? $estado['tienda']['id'] : 'mercadopago'),
            ['metodo' => $metodo, 'cuotas' => $cuotas, 'huella' => self::huella($estado)],
            ['titulo' => 'Configurar cuotas de Mercado Pago', 'renglones' => $renglones, 'aviso' => self::AVISO],
            isset($input['reemplaza_a']) ? $input['reemplaza_a'] : null);

        return AccionesIaHelper::respuesta_de_propuesta($creada, 'Configurar ' . count($cuotas) . ' opciones de cuotas de Mercado Pago para la tienda.',
            ['cuotas' => $cuotas, 'aviso' => self::AVISO]);
    }

    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        self::validar_contexto($contexto);
        User::where('id', $contexto->owner_id)->lockForUpdate()->first();
        // El tipo es global: también serializa dos dueños que comparten catálogo de CC.
        PaymentMethodType::where('name', 'MercadoPago')->orderBy('id')->lockForUpdate()->get(['id']);
        $datos = is_array($accion->datos) ? $accion->datos : [];
        $cuotas = self::normalizar_cuotas(isset($datos['cuotas']) ? $datos['cuotas'] : null);
        $estado = self::estado($contexto, isset($datos['metodo']) ? $datos['metodo'] : '', $cuotas);
        if (!isset($datos['huella']) || !hash_equals((string) $datos['huella'], self::huella($estado))) {
            throw new AccionIaException(422, 'La configuración de Mercado Pago cambió desde la propuesta. Pedímelo de nuevo para revisar los valores actuales.');
        }

        $cuenta = $estado['cuenta'] ? CurrentAcountPaymentMethod::find($estado['cuenta']['id'])
            : CurrentAcountPaymentMethod::create(['name' => 'Mercado Pago', 'c_a_payment_method_type_id' => null]);
        if ($estado['tienda']) {
            $tienda = PaymentMethod::where('user_id', $contexto->owner_id)->find($estado['tienda']['id']);
        } else {
            // El nombre del tipo es el contrato con tienda-api: no se fija un id sembrado.
            $tipo = PaymentMethodType::where('name', 'MercadoPago')->get()->first(function ($fila) {
                return (string) $fila->name === 'MercadoPago';
            });
            if (!$tipo) {
                // firstOrCreate podría reusar 'mercadopago' por collation CI: la tienda exige el nombre exacto.
                $tipo = PaymentMethodType::create(['name' => 'MercadoPago']);
            }
            $tienda = PaymentMethod::create(['name' => 'Mercado Pago', 'description' => 'Pago online con Mercado Pago',
                'discount' => null, 'user_id' => $contexto->owner_id, 'payment_method_type_id' => $tipo->id]);
        }
        foreach ($cuotas as $opcion) {
            $previa = self::previa($estado, $opcion['cantidad_cuotas']);
            $cuota = $previa ? Cuota::where('user_id', $contexto->owner_id)->find($previa['id']) : new Cuota();
            $cuota->user_id = $contexto->owner_id;
            $cuota->payment_method_id = $cuenta->id;
            $cuota->cantidad_cuotas = $opcion['cantidad_cuotas'];
            $cuota->recargo = $opcion['recargo'];
            $cuota->descuento = 0;
            $cuota->save();
        }
        $tienda->current_acount_payment_method_id = $cuenta->id;
        $tienda->save();

        return ['texto' => 'Quedaron configuradas ' . count($cuotas) . ' opciones de cuotas con sus recargos para Mercado Pago de la tienda. ' . self::AVISO,
            'ruta' => ['name' => 'abm', 'params' => ['view' => 'cuenta-corriente', 'sub_view' => 'cuotas'], 'texto' => 'Ver las cuotas']];
    }

    private static function validar_contexto(ContextoDeCargaIa $contexto)
    {
        if (!$contexto->owner || !$contexto->persona || !PermisosIaHelper::puede($contexto->persona, 'abm')) {
            throw new AccionIaException(422, 'No tenés permiso para configurar las cuotas de la tienda desde tu usuario.');
        }
        if ((int) ($contexto->persona->owner_id ?: $contexto->persona->id) !== $contexto->owner_id) {
            throw new AccionIaException(422, 'La persona no pertenece al negocio de esta conversación.');
        }
        foreach ([
            'payment_methods' => ['id', 'name', 'description', 'discount', 'user_id', 'payment_method_type_id', 'current_acount_payment_method_id'],
            'payment_method_types' => ['id', 'name'],
            'current_acount_payment_methods' => ['id', 'name', 'c_a_payment_method_type_id'],
            'cuotas' => ['id', 'cantidad_cuotas', 'descuento', 'recargo', 'payment_method_id', 'user_id'],
        ] as $tabla => $columnas) {
            if (!Schema::hasColumns($tabla, $columnas)) {
                throw new AccionIaException(422, 'Esta versión del sistema todavía no permite configurar las cuotas de la tienda. Pedile a soporte que revise la actualización.');
            }
        }
    }

    private static function normalizar_cuotas($filas)
    {
        if (!is_array($filas) || !count($filas) || count($filas) > 36) {
            throw new AccionIaException(422, 'Indicá entre 1 y 36 opciones con cantidad de cuotas y coeficiente o recargo.');
        }
        $resultado = [];
        foreach ($filas as $fila) {
            if (!is_array($fila) || !isset($fila['cantidad_cuotas']) || !is_scalar($fila['cantidad_cuotas']) || !preg_match('/^[1-9][0-9]?$/', (string) $fila['cantidad_cuotas'])
                || (int) $fila['cantidad_cuotas'] > 36 || is_bool($fila['cantidad_cuotas'])) {
                throw new AccionIaException(422, 'Cada cantidad de cuotas tiene que ser un entero entre 1 y 36.');
            }
            $cantidad = (int) $fila['cantidad_cuotas'];
            $coeficiente = array_key_exists('coeficiente', $fila) ? self::numero($fila['coeficiente']) : null;
            $recargo = array_key_exists('recargo', $fila) ? self::numero($fila['recargo']) : null;
            if ($coeficiente === null && $recargo === null) {
                throw new AccionIaException(422, 'Falta el coeficiente o recargo de ' . $cantidad . ' cuotas.');
            }
            if ($coeficiente !== null && ($coeficiente < 1 || $coeficiente > 11)) {
                throw new AccionIaException(422, 'El coeficiente tiene que estar entre 1 y 11; por ejemplo 1,0256 para un recargo de 2,56%.');
            }
            $desde_coeficiente = $coeficiente === null ? null : round(($coeficiente - 1) * 100, 2);
            if ($recargo !== null && ($recargo < 0 || $recargo > 1000)) {
                throw new AccionIaException(422, 'El recargo porcentual tiene que estar entre 0 y 1000.');
            }
            if ($desde_coeficiente !== null && $recargo !== null && abs($desde_coeficiente - round($recargo, 2)) > 0.001) {
                throw new AccionIaException(422, 'El coeficiente y el recargo de ' . $cantidad . ' cuotas no coinciden.');
            }
            if (isset($resultado[$cantidad])) {
                throw new AccionIaException(422, 'La cantidad de ' . $cantidad . ' cuotas está repetida.');
            }
            $resultado[$cantidad] = ['cantidad_cuotas' => $cantidad, 'recargo' => $desde_coeficiente === null ? round($recargo, 2) : $desde_coeficiente];
        }
        ksort($resultado);
        return array_values($resultado);
    }

    private static function numero($valor)
    {
        if (is_bool($valor) || !is_scalar($valor) || !preg_match('/^[0-9]+(?:[.,][0-9]+)?$/', (string) $valor)) {
            throw new AccionIaException(422, 'Usá un número sin separadores de miles para cada coeficiente o recargo.');
        }
        $numero = (float) str_replace(',', '.', (string) $valor);
        if (!is_finite($numero)) {
            throw new AccionIaException(422, 'El coeficiente o recargo no es un número válido.');
        }
        return $numero;
    }

    /** Solo columnas de configuración: nunca se lee ni se copia access_token ni public_key. */
    private static function estado(ContextoDeCargaIa $contexto, $seleccion, array $cuotas)
    {
        if (!is_string($seleccion) && !is_int($seleccion)) {
            throw new AccionIaException(422, 'Indicá el nombre del método Mercado Pago de la tienda.');
        }
        $candidatos_tipo = PaymentMethodType::where('name', 'MercadoPago')->get(['id', 'name']);
        $tipos = $candidatos_tipo->filter(function ($tipo) {
            return (string) $tipo->name === 'MercadoPago';
        });
        if (!$tipos->count() && $candidatos_tipo->count()) {
            throw new AccionIaException(422, 'El tipo de Mercado Pago tiene un nombre antiguo que la tienda no reconoce. Pedile a soporte que revise el tipo antes de cargar las cuotas.');
        }
        $metodos = PaymentMethod::where('user_id', $contexto->owner_id)->whereIn('payment_method_type_id', $tipos->pluck('id'))
            ->orderBy('id')->get(['id', 'name', 'payment_method_type_id', 'current_acount_payment_method_id']);
        $buscados = $metodos->filter(function ($m) use ($seleccion) {
            return (is_int($seleccion) && (int) $m->id === $seleccion) || self::clave($m->name) === self::clave($seleccion);
        });
        if (!$buscados->count() && self::clave($seleccion) === 'mercadopago' && $metodos->count() === 1) {
            $buscados = $metodos;
        }
        if ($buscados->count() > 1 || (!$buscados->count() && $metodos->count())) {
            throw new AccionIaException(422, 'Hay varios métodos o el nombre no coincide. Indicá el nombre exacto del método Mercado Pago de la tienda: ' . $metodos->map(function ($m) { return $m->name . ' (id ' . $m->id . ')'; })->implode(', ') . '.');
        }
        if (!$buscados->count() && self::clave($seleccion) !== 'mercadopago') {
            throw new AccionIaException(422, 'No existe ese método Mercado Pago en tu tienda. Para crear uno nuevo pedí Mercado Pago.');
        }
        $tienda = $buscados->first();
        $cuenta = null;
        if ($tienda && $tienda->current_acount_payment_method_id) {
            $cuenta = CurrentAcountPaymentMethod::find($tienda->current_acount_payment_method_id, ['id', 'name']);
            if (!$cuenta) {
                throw new AccionIaException(422, 'El método vinculado de cuenta corriente ya no existe. Revisá el vínculo antes de cargar las cuotas.');
            }
        } else {
            // Este catálogo no tiene user_id: es compartido por los usuarios de este sistema.
            $cuentas = CurrentAcountPaymentMethod::orderBy('id')->get(['id', 'name'])->filter(function ($m) {
                return self::clave($m->name) === 'mercadopago';
            });
            if ($cuentas->count() > 1) {
                throw new AccionIaException(422, 'Hay más de un Mercado Pago en cuenta corriente. Vinculá primero el método correcto desde ABM, Tienda online, Métodos de pago.');
            }
            $cuenta = $cuentas->first();
        }
        $filas = $cuenta ? Cuota::where('user_id', $contexto->owner_id)->where('payment_method_id', $cuenta->id)
            ->whereIn('cantidad_cuotas', array_column($cuotas, 'cantidad_cuotas'))->orderBy('id')
            ->get(['id', 'cantidad_cuotas', 'recargo', 'descuento', 'payment_method_id', 'user_id'])->toArray() : [];
        $vistos = [];
        foreach ($filas as $fila) {
            $cantidad = (int) $fila['cantidad_cuotas'];
            if (isset($vistos[$cantidad])) {
                throw new AccionIaException(422, 'Hay reglas duplicadas para ' . $cantidad . ' cuotas de este método. Revisalas antes de cargar la planilla.');
            }
            $vistos[$cantidad] = true;
        }
        return ['tienda' => $tienda ? $tienda->toArray() : null, 'cuenta' => $cuenta ? $cuenta->toArray() : null, 'filas' => $filas];
    }

    private static function previa(array $estado, $cantidad)
    {
        foreach ($estado['filas'] as $fila) {
            if ((int) $fila['cantidad_cuotas'] === $cantidad) {
                return $fila;
            }
        }
        return null;
    }

    private static function clave($nombre)
    {
        return preg_replace('/\s+/', '', mb_strtolower(Str::ascii(trim((string) $nombre))));
    }

    private static function huella(array $estado)
    {
        return hash('sha256', json_encode($estado));
    }

    private static function porcentaje($numero)
    {
        return number_format($numero, 2, ',', '') . '%';
    }
}
