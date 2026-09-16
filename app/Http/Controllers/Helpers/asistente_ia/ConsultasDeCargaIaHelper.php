<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\agenda\AgendaHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Services\Mostrador\RecolectorBase;
use App\Models\Provider;
use Carbon\Carbon;

/**
 * Lecturas que el asistente de IA necesita para armar cargas y que las herramientas de consulta de
 * siempre no traían: proveedores con sus cuentas, las cuentas corrientes de un cliente o proveedor y
 * las tareas de la agenda (misión asistente-ia-acciones, §3.3 del plan).
 *
 * Todo filtra por el dueño resuelto desde la conversación (ContextoDeCargaIa), nunca desde Auth:
 * estas lecturas corren adentro del job sin sesión.
 */
class ConsultasDeCargaIaHelper {

    /**
     * Tope de filas por lista: acota el JSON del prompt, no el negocio.
     */
    const TOPE = 20;

    /**
     * Ventanas de días que acepta consultar_tareas (default 30).
     *
     * @var array<int,int>
     */
    const DIAS_PERMITIDOS = [7, 30, 90];

    /**
     * Proveedores del dueño que coinciden con la búsqueda, con sus cuentas corrientes.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $busqueda  Nombre o parte; vacío trae los primeros por nombre.
     * @return array  [{id, proveedor, telefono, cuentas: [{moneda, saldo, lectura}]}]
     */
    static function proveedores(ContextoDeCargaIa $contexto, $busqueda) {

        $busqueda = trim((string) $busqueda);

        $query = Provider::where('user_id', $contexto->owner_id);

        if ($busqueda !== '') {

            // Los comodines del LIKE se escapan: un nombre con "%" o "_" tiene que buscarse literal.
            $query->where('name', 'LIKE', '%'.addcslashes($busqueda, '%_\\').'%');
        }

        $resultado = [];

        foreach ($query->orderBy('name')->limit(self::TOPE)->get(['id', 'name', 'phone']) as $proveedor) {

            $cuentas = [];

            foreach (self::cuentas_del_modelo($contexto, 'provider', $proveedor->id) as $cuenta) {

                $cuentas[] = self::cuenta_para_la_ia('provider', $cuenta);
            }

            $resultado[] = [
                'id'        => (int) $proveedor->id,
                'proveedor' => (string) $proveedor->name,
                'telefono'  => (string) ($proveedor->phone ?? ''),
                'cuentas'   => $cuentas,
            ];
        }

        return $resultado;
    }

    /**
     * Cuentas corrientes de UN cliente o proveedor del dueño.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $tipo  cliente | proveedor
     * @param  int  $id
     * @return array  [{moneda, saldo, lectura}] o la respuesta negativa si no es del dueño.
     */
    static function cuentas_corrientes(ContextoDeCargaIa $contexto, $tipo, $id) {

        $model_name = self::model_name_de_tipo($tipo);

        if (is_null($model_name)) {

            return RespuestaDeCargaIa::faltan(['si es un cliente o un proveedor']);
        }

        $modelo = self::modelo_del_dueno($contexto, $model_name, $id);

        if (is_null($modelo)) {

            return RespuestaDeCargaIa::error($model_name === 'client' ? 'No encontré ese cliente entre los tuyos.' : 'No encontré ese proveedor entre los tuyos.');
        }

        $cuentas = [];

        foreach (self::cuentas_del_modelo($contexto, $model_name, $modelo->id) as $cuenta) {

            $cuentas[] = self::cuenta_para_la_ia($model_name, $cuenta);
        }

        return $cuentas;
    }

    /**
     * Tareas de la agenda del dueño: las vencidas y las próximas (sin las hechas), filtradas por la
     * búsqueda en el detalle. Salen de AgendaHelper, que es lo que muestra la pantalla de la Agenda.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $busqueda  Vacío trae todas.
     * @param  int  $dias  Ventana hacia adelante: 7, 30 o 90 (otro valor cae a 30).
     * @return array  {vencidas: [...], proximas: [...]}
     */
    static function tareas(ContextoDeCargaIa $contexto, $busqueda, $dias) {

        $dias = (int) $dias;

        if (!in_array($dias, self::DIAS_PERMITIDOS, true)) {

            $dias = 30;
        }

        $hoy = $contexto->hoy->copy()->startOfDay();
        $busqueda = ConsultasSistemaIaHelper::normalize_text((string) $busqueda);

        // De las vencidas se quedan las más recientes: son las que la persona tiene en la cabeza.
        $vencidas = array_slice(self::filtrar_por_busqueda(AgendaHelper::vencidas($contexto->owner_id, $hoy), $busqueda), -self::TOPE);

        $pendientes = [];

        foreach (AgendaHelper::ocurrencias_entre($contexto->owner_id, $hoy, $hoy->copy()->addDays($dias)) as $ocurrencia) {

            if (!$ocurrencia['completado']) {

                $pendientes[] = $ocurrencia;
            }
        }

        $proximas = array_slice(self::filtrar_por_busqueda($pendientes, $busqueda), 0, self::TOPE);

        return [
            'vencidas' => self::tareas_para_la_ia($vencidas),
            'proximas' => self::tareas_para_la_ia($proximas),
        ];
    }

    /**
     * Cuentas corrientes visibles de un cliente o proveedor del dueño: la de pesos y, solo con la
     * extensión ventas_en_dolares, la de dólares.
     *
     * 🔴 Es el espejo de BtnCurrentAcounts.vue::show() (`credit_account.moneda_id == 1 ||
     * hasExtencion('ventas_en_dolares')`), que es el botón desde el que la pantalla abre la cuenta.
     * CreditAccountHelper::crear_credit_accounts() crea SIEMPRE las dos cuentas (moneda 1 y 2) por
     * cliente y por proveedor, aunque el comercio nunca venda en dólares: sin este filtro el asistente
     * le preguntaría "¿pesos o dólares?" a todo el mundo y le mostraría una cuenta que la pantalla
     * esconde. Sin la extensión, entonces, la única cuenta que existe para el asistente es la de
     * moneda 1.
     *
     * La de pesos es la de moneda_id 1; solo si no hay ninguna, una con moneda_id null, que se trata
     * como pesos con el criterio de RecolectorBase::consulta_deudas_en_pesos() (hoy la columna es NOT
     * NULL, así que es una red para bases viejas).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $model_name  client | provider
     * @param  int  $model_id
     * @return array<int,\App\Models\CreditAccount>
     */
    static function cuentas_del_modelo(ContextoDeCargaIa $contexto, $model_name, $model_id) {

        $cuentas = CreditAccount::where('user_id', $contexto->owner_id)
                                ->where('model_name', $model_name)
                                ->where('model_id', $model_id)
                                ->orderBy('id')
                                ->get();

        $pesos = null;
        $pesos_sin_moneda = null;
        $dolares = null;

        foreach ($cuentas as $cuenta) {

            if (is_null($cuenta->moneda_id)) {

                if (is_null($pesos_sin_moneda)) {

                    $pesos_sin_moneda = $cuenta;
                }

            } elseif (in_array((int) $cuenta->moneda_id, RecolectorBase::MONEDAS_PESOS, true)) {

                /*
                 * 🔴 Pesos es [0, 1], el mismo criterio que RecolectorBase::MONEDAS_PESOS del
                 * mostrador (develop, 15/9, commit 8ddbac31): hay cuentas con moneda_id = 0 en
                 * produccion y ahi puede vivir la deuda. Con solo el 1, un cliente asi quedaba sin
                 * cuenta en pesos y, si tenia una en dolares, el pago se iba SIN PREGUNTAR a la de
                 * dolares.
                 *
                 * Divergencia declarada con BtnCurrentAcounts.vue::show(), que compara == 1 y por
                 * eso ESCONDE una cuenta en 0: la pantalla no la muestra, el mostrador cuenta su
                 * deuda. Se eligio el criterio del mostrador porque es donde vive la deuda; que la
                 * pantalla la esconda esta reportado como hallazgo aparte.
                 */

                if (is_null($pesos)) {

                    $pesos = $cuenta;
                }

            } elseif ((int) $cuenta->moneda_id === FormatoIaHelper::MONEDA_DOLARES) {

                if (is_null($dolares)) {

                    $dolares = $cuenta;
                }
            }
        }

        $visibles = [];

        if (!is_null($pesos)) {

            $visibles[] = $pesos;

        } elseif (!is_null($pesos_sin_moneda)) {

            $visibles[] = $pesos_sin_moneda;
        }

        if ($contexto->usa_dolares && !is_null($dolares)) {

            $visibles[] = $dolares;
        }

        return $visibles;
    }

    /**
     * Lectura humana de un saldo. El signo se lee al revés según de quién es la cuenta
     * (mapa_del_sistema.md §3): en la de un cliente positivo es que te debe; en la de un proveedor,
     * que le debés vos.
     *
     * @param  string  $model_name  client | provider
     * @param  float  $saldo
     * @param  int|null  $moneda_id
     * @return string
     */
    static function lectura_de_saldo($model_name, $saldo, $moneda_id) {

        $saldo = round((float) $saldo, 2);

        if (abs($saldo) < 0.005) {

            return 'al día';
        }

        $monto = FormatoIaHelper::monto(abs($saldo), $moneda_id);

        if ($saldo > 0) {

            return $model_name === 'provider' ? 'le debés '.$monto : 'debe '.$monto;
        }

        return 'a favor '.$monto;
    }

    /**
     * 'client' | 'provider' para el tipo que dice la IA, o null.
     *
     * @param  string  $tipo
     * @return string|null
     */
    static function model_name_de_tipo($tipo) {

        $tipo = mb_strtolower(trim((string) $tipo));

        if ($tipo === 'cliente') {

            return 'client';
        }

        if ($tipo === 'proveedor') {

            return 'provider';
        }

        return null;
    }

    /**
     * El cliente o proveedor con ese id si es del dueño (los borrados quedan afuera por SoftDeletes).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $model_name
     * @param  int  $id
     * @return \App\Models\Client|\App\Models\Provider|null
     */
    static function modelo_del_dueno(ContextoDeCargaIa $contexto, $model_name, $id) {

        $clase = $model_name === 'client' ? Client::class : Provider::class;

        return $clase::where('user_id', $contexto->owner_id)->where('id', (int) $id)->first();
    }

    /**
     * @param  string  $model_name
     * @param  \App\Models\CreditAccount  $cuenta
     * @return array  {moneda, saldo, lectura}
     */
    protected static function cuenta_para_la_ia($model_name, $cuenta) {

        return [
            'moneda'  => FormatoIaHelper::nombre_de_moneda($cuenta->moneda_id),
            'saldo'   => round((float) $cuenta->saldo, 2),
            'lectura' => self::lectura_de_saldo($model_name, $cuenta->saldo, $cuenta->moneda_id),
        ];
    }

    /**
     * @param  array  $ocurrencias  Ocurrencias de AgendaHelper.
     * @param  string  $busqueda  Ya normalizada.
     * @return array
     */
    protected static function filtrar_por_busqueda(array $ocurrencias, $busqueda) {

        if ($busqueda === '') {

            return array_values($ocurrencias);
        }

        $filtradas = [];

        foreach ($ocurrencias as $ocurrencia) {

            if (mb_strpos(ConsultasSistemaIaHelper::normalize_text((string) $ocurrencia['detalle']), $busqueda) !== false) {

                $filtradas[] = $ocurrencia;
            }
        }

        return $filtradas;
    }

    /**
     * @param  array  $ocurrencias
     * @return array  [{tarea_id, detalle, fecha, dia, se_repite, gasto_asociado}]
     */
    protected static function tareas_para_la_ia(array $ocurrencias) {

        $tareas = [];

        foreach ($ocurrencias as $ocurrencia) {

            $se_repite = null;

            if ($ocurrencia['es_recurrente'] && !is_null($ocurrencia['unidad_frecuencia']) && (int) $ocurrencia['cantidad_frecuencia'] >= 1) {

                $se_repite = FormatoIaHelper::repeticion($ocurrencia['cantidad_frecuencia'], $ocurrencia['unidad_frecuencia']['slug']);
            }

            $tareas[] = [
                'tarea_id'       => (int) $ocurrencia['pending_id'],
                'detalle'        => (string) $ocurrencia['detalle'],
                // AAAA-MM-DD, que es lo que piden las herramientas de propuesta.
                'fecha'          => $ocurrencia['fecha'],
                'dia'            => FormatoIaHelper::fecha_con_dia(Carbon::createFromFormat('Y-m-d', $ocurrencia['fecha'])->startOfDay()),
                'se_repite'      => $se_repite,
                'gasto_asociado' => is_null($ocurrencia['expense_concept']) ? null : [
                    'subcategoria'   => (string) $ocurrencia['expense_concept']['name'],
                    'monto_estimado' => $ocurrencia['expense_amount'],
                ],
            ];
        }

        return $tareas;
    }
}
