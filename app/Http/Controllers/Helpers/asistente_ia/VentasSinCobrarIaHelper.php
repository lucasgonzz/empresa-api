<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper;
use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Models\User;
use App\Services\Mostrador\RecolectorBase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "¿CUÁNTA PLATA TENGO SIN COBRAR?" — LAS VENTAS IMPAGAS DE TODO EL NEGOCIO.
 *
 * Misión asistente-ventas-y-fotos (21/9/2026). Hasta hoy el asistente solo sabía contestar esa
 * pregunta POR CLIENTE (`ConsultasSistemaIaHelper::ventas_impagas_de_un_cliente`), así que "cuánto
 * me deben en total" terminaba en una ronda de consultas cliente por cliente, o directamente sin
 * respuesta. Es el mismo hueco que ya tenía el resumen de ventas antes de
 * `ResumenDeVentasIaHelper`, y se tapa igual: una consulta que devuelve el total del negocio y el
 * ranking, no una página de filas para que el modelo sume a mano.
 *
 * 🔴 LA DEUDA ES LA DE LA PANTALLA "VENTAS SIN COBRAR", y sale de
 * `VentasSinCobrarHelper::query_de_ventas()` — la MISMA query (la misma condición de deuda, el mismo
 * scope de ventas reales) que usan el listado de la pantalla y el recordatorio de cobro por WhatsApp.
 * No se reescribe acá: una segunda definición de "venta impaga" es el camino más corto a que la
 * pantalla y el chat le den dos números distintos al mismo comerciante.
 *
 * 🔴 LO ÚNICO QUE NO SE ESPEJA, A PROPÓSITO: EL UMBRAL PROPIO DE CADA VENTA (misión
 * asistente-arreglos-segunda-prueba, 19/10/2026). La pantalla aplica
 * `COALESCE(dias_alerta_venta_no_cobrada_personalizado, dias)`, que es la ALERTA de la venta ("avisame a
 * los 30 días"), no si se debe. En el asistente la pregunta es de la persona: con días dichos cuentan
 * esos días tal cual, y sin días ("¿cuánto me deben?") es TODA la deuda viva. Con el COALESCE, en demo3
 * una venta de 23 días con umbral propio 30 quedaba afuera de "hace más de 20 días" y de "¿cuánto me
 * deben?" mientras "¿qué me debe X?" (consultar_ventas_impagas_de_un_cliente) sí la traía: el asistente
 * se contradecía en la misma conversación. Ver respetar_umbral_propio().
 *
 * 🔴 DE QUÉ UNIVERSO HABLA, PORQUE NO ES OBVIO Y EL MODELO LO IBA A INVENTAR. Acá adentro solo hay
 * ventas que generaron CUENTA CORRIENTE y todavía tienen deuda. Una venta de mostrador en efectivo
 * no tiene ningún registro de cobranza: no aparece porque no hay deuda, no porque exista un dato
 * que diga "esta se cobró". Por eso la respuesta NO trae ni puede traer "cuántas ventas están
 * cobradas": el complemento de este conjunto son "las que no deben nada", que es otra cosa. La
 * descripción de la tool lo dice con todas las letras.
 *
 * ⚠️ El `dias` es el umbral de antigüedad de la query compartida, siempre sin el umbral propio de cada
 * venta (`sales.dias_alerta_venta_no_cobrada_personalizado`, ver arriba): con `dias > 0` esos días tal
 * cual; con `dias = 0` TODAS, incluida la de hoy. Si el modelo no manda ninguno, es 0: todas (ver el
 * porqué en `umbral_de_dias()`).
 *
 * 🔴 Y ESPEJAR LA PANTALLA ES TAMBIÉN ESPEJAR A QUIÉN LE MUESTRA QUÉ. La pantalla calcula
 * `$ver_solo_las_ventas_suyas = true` por defecto y solo lo apaga para el dueño o para quien tenga
 * `ver_alertas_de_todos_los_empleados`. Sin ese recorte acá, un vendedor que en la pantalla ve
 * únicamente sus ventas le preguntaba al asistente y recibía el total del negocio, el ranking de los
 * veinte clientes que más deben con nombre y monto, y la venta más vieja de cualquier compañero. Por
 * eso el recorte y el umbral salen de la PERSONA que escribe (`$contexto->persona`), no del dueño.
 *
 * 🔴 "¿QUIÉN ME DEBE?" NO ES "¿QUÉ VENTAS TENGO SIN COBRAR?" (misión asistente-deudores-saldos-iniciales,
 * 16/11/2026, ticket 131). Un saldo inicial importado o cargado a mano es deuda y no es una venta, así
 * que el conjunto de arriba no lo ve. Desde esa misión la respuesta trae además `cuenta_corriente`: el
 * saldo de cuenta corriente de todos los clientes (`credit_accounts.saldo`), que es lo que contesta
 * "cuánto me deben" y "quién me debe más". Las claves de ventas no cambiaron. Ver
 * `bloque_de_cuenta_corriente()`.
 */
class VentasSinCobrarIaHelper
{
    /** Cuántos clientes deudores entran en el ranking. */
    const TOPE_DE_CLIENTES = 20;

    /** Techo del `dias` que puede pedir el modelo: más que eso no acota nada. */
    const TOPE_DIAS = 3650;

    /**
     * LAS VENTAS SIN COBRAR DEL NEGOCIO, CON EL ALCANCE QUE TIENE QUIEN PREGUNTA.
     *
     * @param  int  $owner_id  Dueño (`sales.user_id`). Nunca Auth: esta tool corre en un job sin sesión.
     * @param  int|null  $dias  Umbral general de antigüedad. 0 = sin umbral general; null = el de la
     *                          cascada por rol de la pantalla.
     * @param  \App\Models\User|null  $persona  Quien le escribe al asistente. null = sin recorte (ver
     *                                          `employee_id_del_recorte()`).
     * @param  float|null  $monto_minimo  Deuda TOTAL mínima de un cliente para entrar al ranking, en
     *                                    pesos. null = sin mínimo (ver `filtrar_y_ordenar_clientes()`).
     * @return array<string, mixed>  Con la clave `error` cuando el pedido no se puede atender
     */
    public static function ventas_sin_cobrar(int $owner_id, $dias = null, $persona = null, $monto_minimo = null): array
    {
        $owner = User::find($owner_id);

        if (is_null($owner)) {
            return ['error' => 'No se encontro el negocio.'];
        }

        $employee_id = self::employee_id_del_recorte($persona, $owner_id);

        $dias = self::umbral_de_dias($dias, $persona, $owner);

        // Los días que dijo la persona son su pregunta: el umbral propio de la venta no entra (N1, 19/10/2026).
        $base = VentasSinCobrarHelper::query_de_ventas($owner_id, $employee_id, $dias, true, self::respetar_umbral_propio($dias));

        $filas = self::deuda_agrupada_por_cliente($base);

        $ventas_totales = 0;
        $ventas_en_otra_moneda = 0;
        $pendiente_total = 0.0;
        $sin_cliente = 0;
        $por_cliente = [];
        $ids = [];

        foreach ($filas as $fila) {
            $cliente_id = (int) $fila->cliente_id;
            $ventas = (int) $fila->ventas;
            $en_pesos = (int) $fila->ventas_en_pesos;
            $pendiente = round((float) $fila->pendiente_en_pesos, 2);

            $ventas_totales += $ventas;
            $ventas_en_otra_moneda += ($ventas - $en_pesos);
            $pendiente_total += $pendiente;

            if ($cliente_id <= 0) {
                $sin_cliente += $ventas;
                continue;
            }

            $ids[] = $cliente_id;

            $por_cliente[] = [
                'cliente_id'                 => $cliente_id,
                'ventas_sin_cobrar'          => $ventas,
                'pendiente_en_pesos'         => $pendiente,
                'ventas_en_otra_moneda'      => $ventas - $en_pesos,
            ];
        }

        // Con borrados: el cliente pudo borrarse después de la venta, y la deuda sigue siendo real.
        $nombres = empty($ids) ? [] : DB::table('clients')->whereIn('id', $ids)->pluck('name', 'id')->map(function ($v) {
            return (string) $v;
        })->all();

        /*
         * 🔴 Para quien ve todo el negocio, el conjunto SIN umbral se lee UNA vez y lo comparten
         * `con_deuda_total()` y el bloque `cuenta_corriente` (revisión de la misión
         * asistente-deudores-saldos-iniciales, 16/11/2026): la primera versión del bloque volvía a
         * correr la consulta agrupada de ventas con un `whereIn` de todos los deudores, la tercera
         * pasada de la consulta más pesada de la tool. Con `dias = 0` ni siquiera hace falta leerlo:
         * es `$filas`. Para un empleado recortado sigue el camino de siempre (no hay bloque).
         */
        $contexto = is_null($employee_id) ? self::contexto_de_ventas_del_negocio($owner_id, $dias, $filas) : null;

        $por_cliente = self::con_deuda_total($por_cliente, $ids, $owner_id, $employee_id, $contexto);

        $clientes_sin_el_minimo = count($por_cliente);

        $monto_minimo = self::monto_minimo_valido($monto_minimo);

        // 🔴 Antes del tope de clientes: si no, uno que cumple podía quedar afuera de los veinte.
        $por_cliente = self::filtrar_y_ordenar_clientes($por_cliente, $monto_minimo);

        $clientes_encontrados = count($por_cliente);

        $deuda_total_de_estos = 0.0;

        foreach ($por_cliente as $cliente) {
            $deuda_total_de_estos += $cliente['deuda_total_en_pesos'];
        }

        $lista = [];

        foreach (array_slice($por_cliente, 0, self::TOPE_DE_CLIENTES) as $cliente) {
            $id = $cliente['cliente_id'];

            $lista[] = array_merge(
                ['cliente' => isset($nombres[$id]) ? $nombres[$id] : 'cliente borrado'],
                $cliente
            );
        }

        $respuesta = [
            'criterio' => 'Las ventas que todavia tienen deuda en la cuenta corriente del cliente: la misma deuda que la pantalla "Ventas sin cobrar" del sistema, sin el aviso de alerta propio de cada venta (con dias, esos dias tal cual; sin dias, todas). Una venta de mostrador en efectivo no esta aca porque no genero deuda, no porque exista un dato que diga que se cobro.',
            // Que el modelo sepa si esta mirando todo el negocio o solo lo de quien le escribe: sin
            // esto presentaria el numero recortado como el total, que es mentirle al que pregunta.
            'alcance'                                => is_null($employee_id)
                ? 'Todas las ventas del negocio.'
                : 'SOLO las ventas que cargo la persona que te escribe: en la pantalla "Ventas sin cobrar" tampoco ve las de sus companeros. No presentes este numero como el total del negocio.',
            'dias_de_antiguedad_pedidos'             => $dias,
            'ventas_sin_cobrar'                      => $ventas_totales,
            'total_pendiente_en_pesos'               => round($pendiente_total, 2),
            // Las que no entran en ese total por estar en otra moneda (la extensión ventas_en_dolares).
            'ventas_en_otra_moneda'                  => $ventas_en_otra_moneda,
            // Ventas impagas sin cliente cargado: no entran en el ranking pero sí en el total.
            'ventas_sin_cliente'                     => $sin_cliente,
            'venta_mas_vieja'                        => self::venta_mas_vieja($base),
            'clientes_con_deuda'                     => $clientes_encontrados,
            'clientes_en_esta_lista'                 => count($lista),
            'clientes'                               => $lista,
        ];

        /*
         * 🔴 CÓMO SE LEE CADA CLIENTE, dicho en la respuesta y no solo en la descripción: con `dias`
         * los totales de arriba son SOLO lo atrasado, pero cada cliente trae también todo lo que
         * debe EN VENTAS. Sin esta línea el modelo vuelve a comparar el monto que dijo el dueño contra
         * lo atrasado, que es el error que motivó el cambio.
         *
         * ⚠️ "En ventas" y no "todo lo que debe" (revisión de la misión
         * asistente-deudores-saldos-iniciales, 16/11/2026): `deuda_total_en_pesos` no incluye un saldo
         * inicial ni nada que no sea una venta. Decir "TODO lo que debe" ponía en la misma respuesta
         * 10.000 como todo y 210.000 en `cuenta_corriente` para el mismo cliente. Las claves no
         * cambiaron; cambió lo que se dice de ellas.
         */
        $respuesta['como_leer_cada_cliente'] = $dias > 0
            ? 'Con dias = ' . $dias . ': ventas_sin_cobrar, total_pendiente_en_pesos y venta_mas_vieja de arriba son SOLO lo que tiene ' . $dias . ' dias o mas. En cada cliente, deuda_total_en_pesos es todo lo que debe EN VENTAS sin cobrar (incluidas las recientes; no incluye saldos iniciales ni nada que no sea una venta, eso esta en cuenta_corriente), deuda_atrasada_en_pesos lo que tiene ' . $dias . ' dias o mas y dias_de_la_venta_mas_vieja la antiguedad de su venta impaga mas vieja. Un cliente esta en la lista porque tiene deuda atrasada; la lista va de mayor a menor deuda en ventas.'
            : 'En cada cliente, deuda_total_en_pesos es todo lo que debe EN VENTAS sin cobrar (no incluye saldos iniciales ni nada que no sea una venta, eso esta en cuenta_corriente) y dias_de_la_venta_mas_vieja la antiguedad de su venta impaga mas vieja. La lista va de mayor a menor deuda en ventas.';

        if (! is_null($monto_minimo)) {
            $respuesta['monto_minimo_pedido'] = $monto_minimo;
            // Cuántos tenían deuda (atrasada, si hubo dias) antes de aplicar el mínimo.
            $respuesta['clientes_con_deuda_sin_el_monto_minimo'] = $clientes_sin_el_minimo;
            $respuesta['filtro_de_monto'] = 'Solo los clientes cuya deuda en ventas sin cobrar (deuda_total_en_pesos) es de ' . $monto_minimo . ' pesos o mas. clientes_con_deuda es cuantos cumplen. Quien debe ese monto contando saldos que no son ventas esta en cuenta_corriente.';
        }

        // La deuda total sumada de los clientes que cumplen (todos, no solo los que viajan en la lista).
        $respuesta['deuda_total_en_pesos_de_estos_clientes'] = round($deuda_total_de_estos, 2);

        /*
         * 🔴 EL SALDO DE CUENTA CORRIENTE DE TODOS LOS CLIENTES (misión
         * asistente-deudores-saldos-iniciales, 16/11/2026, ticket 131). Todo lo de arriba habla de
         * VENTAS, y un cliente que debe un saldo inicial importado (o cargado a mano, o una nota de
         * débito) no tiene ninguna: no aparecía ni en el ranking ni en el total, y el asistente le
         * contestó al dueño que "Don Pichi es el único con deuda" teniendo ocho deudores más. Se
         * AGREGA un bloque sin tocar ninguna clave de antes (las leen los tests, el recordatorio de
         * cobro y la descripción de la tool). Ver agregar_cuenta_corriente().
         */
        $respuesta = static::agregar_cuenta_corriente($respuesta, $owner_id, $employee_id, $dias, $monto_minimo, $contexto);

        return $respuesta;
    }

    /**
     * SUMA A LA RESPUESTA DE `ventas_sin_cobrar()` EL BLOQUE `cuenta_corriente` Y LA GUÍA
     * `como_contestar` (misión asistente-deudores-saldos-iniciales, 16/11/2026, ticket 131).
     *
     * Tres salidas posibles, y las tres dejan intactas las claves de ventas:
     *  - quien pregunta ve todo el negocio (`$employee_id` null: dueño, quien tiene
     *    `ver_alertas_de_todos_los_empleados`, o sin persona): `cuenta_corriente` con el bloque de
     *    `bloque_de_cuenta_corriente()`;
     *  - quien pregunta está recortado a sus ventas: `cuenta_corriente` null y
     *    `cuenta_corriente_no_incluida` con el porqué. 🔴 El saldo de cuenta corriente es del cliente,
     *    no de una venta: no hay forma honesta de "recortarlo a lo suyo", y darle el de todos sería
     *    abrirle lo que la pantalla "Ventas sin cobrar" le cierra. Se usa la MISMA regla del recorte
     *    (`employee_id_del_recorte()`): el asistente no tiene un permiso propio para saldos de
     *    clientes (`consultar_clientes` y `consultar_cuentas_corrientes` no chequean ninguno), así
     *    que ésta es la más restrictiva de las dos;
     *  - la lectura falla: `cuenta_corriente` null y `cuenta_corriente_error`, con `report()`. La
     *    respuesta de ventas sale igual: nunca un 500 por el bloque nuevo.
     *
     * `como_contestar` cambia según lo que mandó el modelo (ver `como_contestar()`).
     *
     * Se llama con `static::` (y `bloque_de_cuenta_corriente()` también) para que un test pueda
     * forzar el fallo con una subclase, sin tocar la base.
     *
     * @param  array<string, mixed>  $respuesta  La respuesta de ventas ya armada.
     * @param  int  $owner_id
     * @param  int|null  $employee_id  El de `employee_id_del_recorte()`.
     * @param  int  $dias  El umbral ya resuelto: no filtra el saldo, solo marca y ordena.
     * @param  float|null  $monto_minimo  Ya limpio (`monto_minimo_valido()`).
     * @param  array|null  $contexto  El de `contexto_de_ventas_del_negocio()`, ya leído por
     *                                `ventas_sin_cobrar()`; null = el bloque lo lee.
     * @return array<string, mixed>
     */
    protected static function agregar_cuenta_corriente(array $respuesta, $owner_id, $employee_id, $dias, $monto_minimo, $contexto = null)
    {
        if (! is_null($employee_id)) {
            $respuesta['cuenta_corriente'] = null;
            $respuesta['cuenta_corriente_no_incluida'] = 'Solo ves tus ventas, igual que en la pantalla "Ventas sin cobrar": el saldo de cuenta corriente de todos los clientes (que incluye saldos iniciales o cargados a mano) no se incluye para tu usuario. Lo de arriba es solo lo de tus ventas sin cobrar: no lo presentes como todo lo que le deben al negocio.';
            $respuesta['como_contestar'] = 'Contesta con los datos de ventas y aclara que son solo tus ventas sin cobrar: el saldo de cuenta corriente de todos los clientes no esta disponible para este usuario.';

            return $respuesta;
        }

        try {
            $respuesta['cuenta_corriente'] = static::bloque_de_cuenta_corriente($owner_id, $dias, $monto_minimo, $contexto);
        } catch (\Throwable $e) {
            report($e);

            $respuesta['cuenta_corriente'] = null;
            $respuesta['cuenta_corriente_error'] = 'No se pudo leer el saldo de las cuentas corrientes: los numeros de arriba son solo de ventas sin cobrar y pueden no ser toda la deuda.';
            $respuesta['como_contestar'] = 'cuenta_corriente no se pudo leer: contesta con los datos de ventas y aclara que pueden no ser toda la deuda (no incluyen saldos iniciales ni nada que no sea una venta).';

            return $respuesta;
        }

        $respuesta['como_contestar'] = self::como_contestar($dias, $monto_minimo);

        return $respuesta;
    }

    /**
     * QUÉ DATOS CONTESTAN LA PREGUNTA, según lo que mandó el modelo (revisión de la misión
     * asistente-deudores-saldos-iniciales, 16/11/2026). Una sola regla por caso:
     *
     *  - sin nada: "cuánto me deben / quién me debe" → `cuenta_corriente`; ventas para lo de ventas.
     *  - solo `monto_minimo` ("los que me deben más de X") → `cuenta_corriente.clientes`, que ya viene
     *    filtrada por saldo. 🔴 La lista de ventas tiene su propio filtro (deuda en ventas) y puede venir
     *    vacía: eso no quiere decir que nadie deba.
     *  - solo `dias` ("lo que me deben hace más de N días") → los datos de ventas, que son los que tienen
     *    antigüedad.
     *  - los dos ("los que me deben más de X hace más de N días", el significado decidido el 16/10/2026:
     *    deben X o más EN TOTAL y tienen alguna venta impaga de N días o más) → las filas de
     *    `cuenta_corriente.clientes` con `tiene_ventas_impagas_de_esos_dias = true`. El ranking de
     *    ventas compara X contra la deuda en ventas y deja afuera a quien debe un saldo inicial.
     *
     * @param  int  $dias
     * @param  float|null  $monto_minimo
     * @return string
     */
    protected static function como_contestar($dias, $monto_minimo)
    {
        if ($dias > 0 && ! is_null($monto_minimo)) {

            return '"Los que me deben mas de X hace mas de N dias" -> las filas de cuenta_corriente.clientes con tiene_ventas_impagas_de_esos_dias = true (son clientes_que_deben_con_ventas_de_esos_dias): deben X o mas en total y tienen alguna venta impaga de ' . $dias . ' dias o mas. Contesta con saldo_en_pesos y dias_de_la_venta_impaga_mas_vieja. No uses el ranking de ventas de arriba para esta pregunta: compara X contra lo que deben solo en ventas y deja afuera a quien debe un saldo inicial.';
        }

        if (! is_null($monto_minimo)) {

            return '"Los que me deben mas de X" -> cuenta_corriente.clientes, que ya viene filtrada por saldo_en_pesos de X o mas. La lista de ventas de arriba tiene su propio filtro (lo que deben en ventas) y puede venir vacia: eso NO significa que nadie deba. "Cuanto me deben" o "quien me debe" -> cuenta_corriente.';
        }

        if ($dias > 0) {

            return '"Lo que me deben hace mas de N dias", "lo atrasado", "la venta mas vieja" -> con los datos de ventas de arriba (solo lo de ' . $dias . ' dias o mas). "Cuanto me deben" o "quien me debe" en total -> cuenta_corriente, que no tiene antiguedad; en cada fila, tiene_ventas_impagas_de_esos_dias dice si ese cliente tiene una venta impaga de ' . $dias . ' dias o mas.';
        }

        return '"cuanto me deben", "quien me debe", "quien me debe mas", "cuanta plata tengo en la calle" -> se contesta con cuenta_corriente (todo lo que deben los clientes, incluidos saldos iniciales o cargados a mano). "Ventas sin cobrar", "lo atrasado", "hace mas de N dias", "la venta mas vieja" -> con los datos de ventas. Si los dos totales difieren, deci cual estas dando.';
    }

    /**
     * EL SALDO DE CUENTA CORRIENTE DE TODOS LOS CLIENTES DEL NEGOCIO (misión
     * asistente-deudores-saldos-iniciales, 16/11/2026, ticket 131).
     *
     * 🔴 EL CASO REAL. El dueño importó por Soporte diez clientes con sus saldos (nueve deudas por
     * $417.301,90 y un saldo a favor), le vendió a cuenta corriente a uno ("Don Pichi") y le cobró
     * una parte. "¿Quién me debe más plata?" contestó que Don Pichi era el único, con $9.150, porque
     * el conjunto de arriba es de VENTAS impagas y los saldos iniciales no son ventas. "Electro
     * Vilches SRL" debía $174.200,75.
     *
     * 🔴 LA FUENTE ES `credit_accounts.saldo` (positivo = el cliente debe), con la consulta de
     * `ConsultasSistemaIaHelper::cuentas_en_pesos_de_clientes()` INVOCADA y no copiada: el mismo
     * criterio de pesos (`RecolectorBase::MONEDAS_PESOS` y `moneda_id` null) que `consultar_clientes`
     * y el informe del mostrador. Si leyeran distinto, "qué me debe Vilches" y "quién me debe" darían
     * dos números para la misma deuda.
     *
     * Reglas:
     *  - Cliente por cliente: un cliente con dos cuentas en pesos (moneda null o 0, y 1) se suma
     *    primero, y debe si ESE neto es > 0. 🔴 Nunca se resta el saldo a favor de OTRO cliente: los que
     *    tienen saldo a favor van aparte (`clientes_con_saldo_a_favor`, `total_a_favor_en_pesos`). Un
     *    cliente con ventas impagas cuyo neto es cero o a favor NO aparece (no debe).
     *  - Otras monedas, aparte (`en_otras_monedas`) y nunca sumadas a pesos. El complemento de pesos
     *    sale del mismo `MONEDAS_PESOS`.
     *  - Clientes borrados (soft delete), AFUERA, igual que `clientes_con_saldo_pendiente()` y que la
     *    lista de clientes del sistema. (El ranking de ventas sí los muestra como "cliente borrado":
     *    ahí la venta sigue siendo real; acá no hay a quién nombrar ni a quién cobrarle desde el
     *    sistema.)
     *  - Tenencia doble: `credit_accounts.user_id` y `clients.user_id` del dueño.
     *  - `monto_minimo` acota a los clientes con `saldo_en_pesos >= monto`; los totales pasan a ser
     *    los de ese filtro y `clientes_que_deben_sin_el_monto_minimo` dice cuántos eran sin él. No
     *    acota los saldos a favor ni las otras monedas (el monto está en pesos y es de deuda).
     *  - `dias` NO saca a nadie: el saldo no tiene antigüedad. Con `dias > 0` cada fila trae
     *    `tiene_ventas_impagas_de_esos_dias` (el cliente está en el conjunto de ventas de esa
     *    antigüedad), el bloque trae `clientes_que_deben_con_ventas_de_esos_dias` (sobre TODOS los que
     *    deben con el monto aplicado, no solo los que viajan) y los marcados viajan PRIMERO: si no,
     *    con más de veinte deudores, uno que cumple "más de X hace más de N días" podía quedar afuera
     *    del tope (revisión de la misión, 16/11/2026).
     *  - Cada fila trae `dias_de_la_venta_impaga_mas_vieja` (null sin ventas impagas) y
     *    `de_eso_en_ventas_sin_cobrar_en_pesos`: lo que el conjunto de ventas sin cobrar SIN umbral le
     *    atribuye al cliente. La diferencia con `saldo_en_pesos` es lo que no es venta (saldo inicial,
     *    nota de débito, pagos a cuenta sin imputar). Solo se arma para quien ve todo el negocio, así
     *    que va sin recorte por empleado.
     *
     * @param  int  $owner_id  Dueño. Nunca Auth: corre en un job sin sesión.
     * @param  int  $dias  El umbral de ventas ya resuelto: marca y ordena, no filtra.
     * @param  float|null  $monto_minimo  Ya limpio (`monto_minimo_valido()`).
     * @param  array|null  $contexto  El de `contexto_de_ventas_del_negocio()`. null = lo lee acá (lo
     *                                usa quien llama al bloque suelto; `ventas_sin_cobrar()` se lo pasa
     *                                ya leído para no repetir la consulta de ventas).
     * @return array<string, mixed>
     */
    public static function bloque_de_cuenta_corriente($owner_id, $dias = 0, $monto_minimo = null, $contexto = null)
    {
        $owner_id = (int) $owner_id;
        $dias = (int) $dias;

        if (is_null($contexto)) {
            $contexto = self::contexto_de_ventas_del_negocio($owner_id, $dias);
        }

        // Una fila por cliente vivo del dueño: la suma de sus cuentas en pesos.
        $filas = ConsultasSistemaIaHelper::cuentas_en_pesos_de_clientes($owner_id)
            ->join('clients', 'clients.id', '=', 'credit_accounts.model_id')
            ->where('clients.user_id', $owner_id)
            ->whereNull('clients.deleted_at')
            ->groupBy('clients.id', 'clients.name')
            ->get(['clients.id', 'clients.name', DB::raw('SUM(credit_accounts.saldo) as saldo')]);

        $deben = [];
        $a_favor = 0;
        $total_a_favor = 0.0;

        foreach ($filas as $fila) {
            $saldo = round((float) $fila->saldo, 2);

            if ($saldo > 0) {
                $deben[] = [
                    'cliente_id'     => (int) $fila->id,
                    'cliente'        => (string) $fila->name,
                    'saldo_en_pesos' => $saldo,
                ];
            } elseif ($saldo < 0) {
                $a_favor++;
                $total_a_favor += abs($saldo);
            }
        }

        $clientes_sin_el_minimo = count($deben);

        if (! is_null($monto_minimo)) {
            $deben = array_values(array_filter($deben, function ($cliente) use ($monto_minimo) {
                return $cliente['saldo_en_pesos'] >= $monto_minimo;
            }));
        }

        $de_ventas = $contexto['por_cliente'];
        $de_esos_dias = $contexto['de_esos_dias'];
        $hoy = now()->startOfDay();

        $total = 0.0;
        $sin_ventas = 0;
        $con_ventas_de_esos_dias = 0;

        foreach ($deben as $indice => $cliente) {
            $id = $cliente['cliente_id'];

            $total += $cliente['saldo_en_pesos'];

            $tiene_ventas = isset($de_ventas[$id]) && $de_ventas[$id]['ventas'] > 0;

            if (! $tiene_ventas) {
                $sin_ventas++;
            }

            $deben[$indice]['de_eso_en_ventas_sin_cobrar_en_pesos'] = $tiene_ventas ? $de_ventas[$id]['pendiente_en_pesos'] : 0.0;
            $deben[$indice]['tiene_ventas_sin_cobrar'] = $tiene_ventas;
            $deben[$indice]['dias_de_la_venta_impaga_mas_vieja'] = (isset($contexto['mas_viejas'][$id]) && ! is_null($contexto['mas_viejas'][$id]))
                ? (int) Carbon::parse($contexto['mas_viejas'][$id])->startOfDay()->diffInDays($hoy)
                : null;

            if ($dias > 0) {
                $marcado = isset($de_esos_dias[$id]);

                $deben[$indice]['tiene_ventas_impagas_de_esos_dias'] = $marcado;

                if ($marcado) {
                    $con_ventas_de_esos_dias++;
                }
            }
        }

        /*
         * De mayor a menor saldo; a igual saldo, el id más bajo primero (orden estable). Con `dias > 0`
         * los que tienen ventas impagas de esa antigüedad van primero: son los que contestan "más de X
         * hace más de N días" y no pueden quedar afuera del tope de veinte.
         */
        usort($deben, function ($a, $b) use ($dias) {
            if ($dias > 0 && $a['tiene_ventas_impagas_de_esos_dias'] !== $b['tiene_ventas_impagas_de_esos_dias']) {
                return $a['tiene_ventas_impagas_de_esos_dias'] ? -1 : 1;
            }

            if ($a['saldo_en_pesos'] != $b['saldo_en_pesos']) {
                return $b['saldo_en_pesos'] <=> $a['saldo_en_pesos'];
            }

            return $a['cliente_id'] <=> $b['cliente_id'];
        });

        $lista = array_slice($deben, 0, self::TOPE_DE_CLIENTES);

        $bloque = [
            'que_es'                                   => 'El saldo de cuenta corriente de TODOS los clientes del negocio, en pesos: incluye las ventas sin cobrar y tambien lo que no es una venta (saldos iniciales importados o cargados a mano, notas de debito, pagos a cuenta). Es lo que te deben de verdad.',
            'total_que_te_deben_en_pesos'              => round($total, 2),
            'clientes_que_deben'                       => count($deben),
            'clientes_en_esta_lista'                   => count($lista),
            'clientes'                                 => $lista,
            'clientes_que_deben_sin_ventas_sin_cobrar' => $sin_ventas,
            'clientes_con_saldo_a_favor'               => $a_favor,
            'total_a_favor_en_pesos'                   => round($total_a_favor, 2),
            'en_otras_monedas'                         => self::saldos_en_otras_monedas($owner_id),
        ];

        if ($dias > 0) {
            $bloque['clientes_que_deben_con_ventas_de_esos_dias'] = $con_ventas_de_esos_dias;
        }

        $como_leer = 'clientes va de mayor a menor saldo_en_pesos. clientes_que_deben es cuantos deben en total y clientes_en_esta_lista cuantos viajan: si difieren, la lista esta recortada y el total no. de_eso_en_ventas_sin_cobrar_en_pesos es la parte de ese saldo que esta en ventas sin cobrar; un cliente con tiene_ventas_sin_cobrar = false debe un saldo que no es una venta (inicial o cargado a mano) y DEBE IGUAL. Si el cliente tiene pagos a cuenta sin imputar, de_eso_en_ventas_sin_cobrar_en_pesos puede ser MAYOR que saldo_en_pesos: manda saldo_en_pesos. Un cliente con ventas impagas cuyo saldo neto es cero o a favor no aparece aca porque no debe. dias_de_la_venta_impaga_mas_vieja es la antiguedad de su venta impaga mas vieja (null si no tiene). Los saldos a favor (clientes_con_saldo_a_favor) y las otras monedas (en_otras_monedas) van aparte: no se restan ni se suman a total_que_te_deben_en_pesos.';

        if ($dias > 0) {
            $como_leer .= ' Pediste dias = ' . $dias . ': eso acota solo las ventas de arriba. El saldo de cuenta corriente no tiene antiguedad y viene completo; tiene_ventas_impagas_de_esos_dias marca a quien tiene alguna venta impaga de ' . $dias . ' dias o mas, y esos van primero en la lista (clientes_que_deben_con_ventas_de_esos_dias dice cuantos son en total).';
        }

        $bloque['como_leer'] = $como_leer;

        if (! is_null($monto_minimo)) {
            $bloque['monto_minimo_pedido'] = $monto_minimo;
            $bloque['clientes_que_deben_sin_el_monto_minimo'] = $clientes_sin_el_minimo;
            $bloque['filtro_de_monto'] = 'Solo los clientes cuyo saldo_en_pesos es de ' . $monto_minimo . ' pesos o mas: clientes_que_deben y total_que_te_deben_en_pesos son de los que cumplen. No acota los saldos a favor ni las otras monedas.';
        }

        return $bloque;
    }

    /**
     * LO QUE LAS VENTAS SIN COBRAR DE TODO EL NEGOCIO DICEN DE CADA CLIENTE, leído una sola vez
     * (revisión de la misión asistente-deudores-saldos-iniciales, 16/11/2026).
     *
     * Lo comparten `con_deuda_total()` (desde `ventas_sin_cobrar()`) y `bloque_de_cuenta_corriente()`,
     * para que la consulta agrupada de ventas —la más pesada de la tool— no corra más veces que antes
     * del bloque nuevo, y sin un `whereIn` con todos los deudores:
     *  - `por_cliente`: del conjunto SIN umbral y SIN recorte (`query_de_ventas(..., 0, false)` +
     *    `deuda_agrupada_por_cliente()`, las piezas de siempre). Con `dias = 0` ese conjunto es el
     *    mismo que el pedido (`respetar_umbral_propio()` es siempre false y sin días no hay umbral), así
     *    que se reusan `$filas_del_pedido` y no se consulta nada.
     *  - `mas_viejas`: la venta impaga más vieja de cada cliente, del mismo conjunto sin umbral (la
     *    misma consulta que hacía `con_deuda_total()`, sin el `whereIn`).
     *  - `de_esos_dias`: con `dias > 0`, los clientes que tienen alguna venta impaga de esa antigüedad
     *    (los del conjunto pedido).
     *
     * Solo sirve para quien ve todo el negocio: va sin recorte por empleado.
     *
     * @param  int  $owner_id
     * @param  int  $dias  El umbral ya resuelto.
     * @param  \Illuminate\Support\Collection|null  $filas_del_pedido  Lo que `ventas_sin_cobrar()` ya
     *         agrupó para ese `dias` (sin recorte). null = se lee acá.
     * @return array{por_cliente: array<int, array{ventas: int, pendiente_en_pesos: float}>, mas_viejas: array<int, string|null>, de_esos_dias: array<int, bool>}
     */
    protected static function contexto_de_ventas_del_negocio($owner_id, $dias, $filas_del_pedido = null)
    {
        $dias = (int) $dias;

        if ($dias > 0 && is_null($filas_del_pedido)) {
            $filas_del_pedido = self::deuda_agrupada_por_cliente(
                VentasSinCobrarHelper::query_de_ventas($owner_id, null, $dias, true, self::respetar_umbral_propio($dias))
            );
        }

        $toda = VentasSinCobrarHelper::query_de_ventas($owner_id, null, 0, false);

        $sin_umbral = ($dias === 0 && ! is_null($filas_del_pedido) && ! self::respetar_umbral_propio($dias))
            ? $filas_del_pedido
            : self::deuda_agrupada_por_cliente($toda);

        $de_esos_dias = [];

        if ($dias > 0) {
            foreach (self::por_id($filas_del_pedido) as $id => $datos) {
                if ($datos['ventas'] > 0) {
                    $de_esos_dias[$id] = true;
                }
            }
        }

        return [
            'por_cliente'  => self::por_id($sin_umbral),
            'mas_viejas'   => self::ventas_mas_viejas_por_cliente($toda),
            'de_esos_dias' => $de_esos_dias,
        ];
    }

    /**
     * Las filas de `deuda_agrupada_por_cliente()` indexadas por cliente (sin las ventas sin cliente).
     *
     * @param  \Illuminate\Support\Collection|array  $filas
     * @return array<int, array{ventas: int, pendiente_en_pesos: float}>
     */
    protected static function por_id($filas)
    {
        $por_id = [];

        foreach ($filas as $fila) {
            $id = (int) $fila->cliente_id;

            if ($id <= 0) {
                continue;
            }

            $por_id[$id] = [
                'ventas'             => (int) $fila->ventas,
                'pendiente_en_pesos' => round((float) $fila->pendiente_en_pesos, 2),
            ];
        }

        return $por_id;
    }

    /**
     * La fecha de la venta impaga más vieja de cada cliente, agrupada por `sales.client_id` (el
     * cliente de la cuenta corriente de esa venta). Es la consulta que vivía adentro de
     * `con_deuda_total()`, sacada para que la compartan los dos caminos.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $toda  Las ventas (sin umbral).
     * @param  array<int, int>|null  $ids  Si viene, acota a esos clientes.
     * @return array<int, string|null>  cliente_id => fecha
     */
    protected static function ventas_mas_viejas_por_cliente($toda, $ids = null)
    {
        $query = (clone $toda)->toBase();

        if (! is_null($ids)) {
            $query->whereIn('sales.client_id', $ids);
        }

        return $query
            ->groupBy('sales.client_id')
            ->selectRaw('sales.client_id as cliente_id, MIN(sales.created_at) as primera')
            ->pluck('primera', 'cliente_id')
            ->all();
    }

    /**
     * LO QUE DEBEN LOS CLIENTES EN OTRAS MONEDAS, una fila por moneda (misión
     * asistente-deudores-saldos-iniciales, 16/11/2026). Nunca se suma a pesos.
     *
     * "Otra moneda" es el complemento EXACTO de `cuentas_en_pesos_de_clientes()`: `moneda_id` no null
     * y fuera de `RecolectorBase::MONEDAS_PESOS`. Mismas reglas que el bloque en pesos: cliente por
     * cliente (debe si su neto en ESA moneda es > 0), clientes borrados afuera y tenencia doble.
     *
     * @param  int  $owner_id
     * @return array<int, array{moneda_id: int, moneda: string|null, clientes_que_deben: int, total_que_te_deben: float}>
     *         Lista vacía si no hay deuda en otra moneda.
     */
    protected static function saldos_en_otras_monedas($owner_id)
    {
        $filas = DB::table('credit_accounts')
            ->join('clients', 'clients.id', '=', 'credit_accounts.model_id')
            ->where('credit_accounts.user_id', $owner_id)
            ->where('credit_accounts.model_name', 'client')
            ->whereNotNull('credit_accounts.moneda_id')
            ->whereNotIn('credit_accounts.moneda_id', RecolectorBase::MONEDAS_PESOS)
            ->where('clients.user_id', $owner_id)
            ->whereNull('clients.deleted_at')
            ->groupBy('credit_accounts.moneda_id', 'clients.id')
            ->get([DB::raw('credit_accounts.moneda_id as moneda_id'), DB::raw('SUM(credit_accounts.saldo) as saldo')]);

        $por_moneda = [];

        foreach ($filas as $fila) {
            $saldo = round((float) $fila->saldo, 2);

            if ($saldo <= 0) {
                continue;
            }

            $moneda_id = (int) $fila->moneda_id;

            if (! isset($por_moneda[$moneda_id])) {
                $por_moneda[$moneda_id] = ['clientes' => 0, 'total' => 0.0];
            }

            $por_moneda[$moneda_id]['clientes']++;
            $por_moneda[$moneda_id]['total'] += $saldo;
        }

        if (empty($por_moneda)) {

            return [];
        }

        ksort($por_moneda);

        $nombres = DB::table('monedas')->whereIn('id', array_keys($por_moneda))->pluck('name', 'id')->all();

        $lista = [];

        foreach ($por_moneda as $moneda_id => $datos) {
            $lista[] = [
                'moneda_id'          => $moneda_id,
                'moneda'             => isset($nombres[$moneda_id]) ? (string) $nombres[$moneda_id] : null,
                'clientes_que_deben' => $datos['clientes'],
                'total_que_te_deben' => round($datos['total'], 2),
            ];
        }

        return $lista;
    }

    /**
     * TODOS LOS CLIENTES QUE CUMPLEN EL FILTRO, sin el tope de veinte del ranking (misión
     * asistente-envios-a-clientes, 16/10/2026).
     *
     * 🔴 ES EL MISMO FILTRO DE `ventas_sin_cobrar()`, INVOCADO Y NO COPIADO. "Mandales un recordatorio
     * a los que me deben más de X hace más de N días" tiene que alcanzar EXACTAMENTE a los clientes que
     * el asistente le listó al dueño cuando le preguntó quiénes le deben: deuda TOTAL de X o más y
     * alguna venta impaga de N días o más, con el mismo recorte por persona y el mismo umbral. Por eso
     * se arma con las mismas cuatro piezas (`query_de_ventas()`, `deuda_agrupada_por_cliente()`,
     * `con_deuda_total()` y `filtrar_y_ordenar_clientes()`) y en el mismo orden; lo único distinto es
     * que no se corta en veinte, porque el recordatorio va a todos los que cumplen.
     *
     * Las ventas sin cliente cargado no entran: no hay a quién avisarle.
     *
     * @param  int  $owner_id
     * @param  int|null  $dias  Ver ventas_sin_cobrar(): null = 0 = todas.
     * @param  \App\Models\User|null  $persona  Quien le escribe al asistente (el recorte sale de ella).
     * @param  float|null  $monto_minimo  Deuda TOTAL mínima.
     * @param  bool|null  $respetar_umbral_propio  null (el default) = lo que decide
     *                    `respetar_umbral_propio($dias)`: con días dichos, los días tal cual. Un bool lo
     *                    fuerza: lo usa la confirmación de una tarjeta de recordatorios, que manda lo que
     *                    la tarjeta guardó (una tarjeta de antes de este cambio no lo guardó y confirma
     *                    con true, el `COALESCE` con el que se armó lo que el dueño vio).
     * @return array{dias: int, clientes: array<int, array<string, mixed>>}  Los clientes, de mayor a
     *         menor deuda total, con `cliente_id`, `cliente` (nombre), `deuda_total_en_pesos`,
     *         `deuda_atrasada_en_pesos` y `dias_de_la_venta_mas_vieja`; y el `dias` que se aplicó.
     */
    public static function clientes_deudores(int $owner_id, $dias = null, $persona = null, $monto_minimo = null, $respetar_umbral_propio = null): array
    {
        $owner = User::find($owner_id);

        if (is_null($owner)) {
            return ['dias' => 0, 'clientes' => []];
        }

        $employee_id = self::employee_id_del_recorte($persona, $owner_id);

        $dias = self::umbral_de_dias($dias, $persona, $owner);

        $respetar = is_null($respetar_umbral_propio) ? self::respetar_umbral_propio($dias) : (bool) $respetar_umbral_propio;

        $base = VentasSinCobrarHelper::query_de_ventas($owner_id, $employee_id, $dias, true, $respetar);

        $por_cliente = [];
        $ids = [];

        foreach (self::deuda_agrupada_por_cliente($base) as $fila) {
            $cliente_id = (int) $fila->cliente_id;

            // Sin cliente no hay a quién mandarle nada.
            if ($cliente_id <= 0) {
                continue;
            }

            $ids[] = $cliente_id;

            $ventas = (int) $fila->ventas;

            $por_cliente[] = [
                'cliente_id'            => $cliente_id,
                'ventas_sin_cobrar'     => $ventas,
                'pendiente_en_pesos'    => round((float) $fila->pendiente_en_pesos, 2),
                'ventas_en_otra_moneda' => $ventas - (int) $fila->ventas_en_pesos,
            ];
        }

        $por_cliente = self::con_deuda_total($por_cliente, $ids, $owner_id, $employee_id);

        $por_cliente = self::filtrar_y_ordenar_clientes($por_cliente, self::monto_minimo_valido($monto_minimo));

        $nombres = empty($ids) ? [] : DB::table('clients')->whereIn('id', $ids)->pluck('name', 'id')->all();

        foreach ($por_cliente as $indice => $cliente) {
            $id = $cliente['cliente_id'];

            $por_cliente[$indice]['cliente'] = isset($nombres[$id]) ? (string) $nombres[$id] : 'cliente borrado';
        }

        return ['dias' => $dias, 'clientes' => $por_cliente];
    }

    /**
     * LO QUE CADA CLIENTE DEBE EN TOTAL, además de lo del conjunto pedido (misión
     * asistente-altas-y-conversacion, 16/10/2026).
     *
     * 🔴 EL CASO REAL. Por WhatsApp en demo3 el dueño pidió "los que me deben más de 200 mil hace más
     * de 20 días". El asistente llamó con `dias = 20` y el conjunto quedó recortado a las ventas de
     * 20 días o más, así que el monto de cada cliente era SOLO lo atrasado: filtró "lo vencido suma
     * más de 200 mil" y contestó que no había ninguno. Lo que el dueño quiere decir —y lo que entiende
     * cualquiera que cobra— es: clientes que me deben más de 200 mil EN TOTAL y que tienen deuda de
     * hace más de 20 días. En demo3 eran 3.
     *
     * Por eso cada cliente del conjunto pedido se completa con la deuda del conjunto SIN umbral
     * (`query_de_ventas(..., false)`: toda la deuda viva, con el mismo recorte por persona) y con la
     * antigüedad de su venta impaga más vieja. Las claves de siempre (`ventas_sin_cobrar`,
     * `pendiente_en_pesos`, `ventas_en_otra_moneda`) no cambian: siguen siendo las del conjunto
     * pedido, que es lo que leen los tests y el resto de la respuesta.
     *
     * @param  array<int, array<string, mixed>>  $por_cliente
     * @param  array<int, int>  $ids
     * @param  int  $owner_id
     * @param  int|null  $employee_id
     * @param  array|null  $contexto  El de `contexto_de_ventas_del_negocio()` (solo sin recorte por
     *                                empleado): trae ya leídos el total sin umbral y la venta más vieja
     *                                de cada cliente, y no se vuelven a consultar (revisión de la misión
     *                                asistente-deudores-saldos-iniciales, 16/11/2026). null = el camino
     *                                de siempre, que es el que sigue usando `clientes_deudores()`.
     * @return array<int, array<string, mixed>>
     */
    protected static function con_deuda_total(array $por_cliente, array $ids, $owner_id, $employee_id, $contexto = null)
    {
        if (empty($por_cliente)) {

            return $por_cliente;
        }

        $totales = [];

        if (! is_null($contexto)) {

            foreach ($contexto['por_cliente'] as $id => $datos) {
                $totales[$id] = $datos['pendiente_en_pesos'];
            }

            $mas_viejas = $contexto['mas_viejas'];

        } else {

            $toda = VentasSinCobrarHelper::query_de_ventas($owner_id, $employee_id, 0, false);

            foreach (self::deuda_agrupada_por_cliente($toda, $ids) as $fila) {
                $totales[(int) $fila->cliente_id] = round((float) $fila->pendiente_en_pesos, 2);
            }

            /*
             * La venta impaga más vieja de cada cliente, del conjunto entero y no del pedido: es la
             * que dice "hace cuánto me debe". Se agrupa por `sales.client_id`, que es el cliente de la
             * cuenta corriente de esa venta.
             */
            $mas_viejas = self::ventas_mas_viejas_por_cliente($toda, $ids);
        }

        $hoy = now()->startOfDay();

        foreach ($por_cliente as $indice => $cliente) {
            $id = $cliente['cliente_id'];

            // Lo del conjunto pedido está siempre adentro del total: el max solo cubre un redondeo.
            $total = isset($totales[$id]) ? max($totales[$id], $cliente['pendiente_en_pesos']) : $cliente['pendiente_en_pesos'];

            $por_cliente[$indice]['deuda_total_en_pesos'] = $total;
            $por_cliente[$indice]['deuda_atrasada_en_pesos'] = $cliente['pendiente_en_pesos'];
            $por_cliente[$indice]['dias_de_la_venta_mas_vieja'] = (isset($mas_viejas[$id]) && ! is_null($mas_viejas[$id]))
                ? (int) Carbon::parse($mas_viejas[$id])->startOfDay()->diffInDays($hoy)
                : null;
        }

        return $por_cliente;
    }

    /**
     * El `monto_minimo` que pidió el modelo, limpio: null si no mandó nada usable, o si mandó cero o
     * menos, que no acota nada.
     *
     * @param  mixed  $monto_minimo
     * @return float|null
     */
    protected static function monto_minimo_valido($monto_minimo)
    {
        if (is_null($monto_minimo) || ! is_numeric($monto_minimo)) {

            return null;
        }

        $monto_minimo = (float) $monto_minimo;

        return $monto_minimo > 0 ? $monto_minimo : null;
    }

    /**
     * QUIÉN ENTRA AL RANKING Y EN QUÉ ORDEN.
     *
     * Entra todo cliente del conjunto pedido (con `dias`, el que tiene algo de esa antigüedad) y, con
     * `monto_minimo`, solo si su deuda TOTAL llega a ese monto: "me deben más de 200 mil hace más de
     * 20 días" = deuda total de 200 mil o más Y deuda de 20 días o más. 🔴 El filtro va ANTES del
     * tope de clientes: filtrando después, un cliente que cumple podía quedar afuera por no estar
     * entre los veinte que más deben.
     *
     * Orden: deuda total de mayor a menor, que es lo que el dueño mira primero; a igual deuda total,
     * más deuda del conjunto pedido primero, y después más ventas. Sin `dias` el conjunto pedido ya es
     * toda la deuda viva (ver respetar_umbral_propio()), así que coincide con la deuda total.
     *
     * @param  array<int, array<string, mixed>>  $por_cliente
     * @param  float|null  $monto_minimo
     * @return array<int, array<string, mixed>>
     */
    protected static function filtrar_y_ordenar_clientes(array $por_cliente, $monto_minimo)
    {
        if (! is_null($monto_minimo)) {
            $por_cliente = array_values(array_filter($por_cliente, function ($cliente) use ($monto_minimo) {
                return $cliente['deuda_total_en_pesos'] >= $monto_minimo;
            }));
        }

        usort($por_cliente, function ($a, $b) {
            if ($a['deuda_total_en_pesos'] != $b['deuda_total_en_pesos']) {
                return $b['deuda_total_en_pesos'] <=> $a['deuda_total_en_pesos'];
            }

            if ($a['pendiente_en_pesos'] != $b['pendiente_en_pesos']) {
                return $b['pendiente_en_pesos'] <=> $a['pendiente_en_pesos'];
            }

            return $b['ventas_sin_cobrar'] <=> $a['ventas_sin_cobrar'];
        });

        return $por_cliente;
    }

    /**
     * LA DEUDA AGRUPADA POR CLIENTE, sobre las filas de `current_acounts` de un conjunto de ventas.
     *
     * Es el agregado que vivía adentro de `ventas_sin_cobrar()`, sacado tal cual (misión
     * asistente-altas-y-conversacion, 16/10/2026) para poder correrlo dos veces: sobre el conjunto
     * pedido (con `dias`, lo atrasado) y sobre el conjunto sin umbral (la deuda total de cada
     * cliente, `con_deuda_total()`). Una sola definición de "cuánto debe": las dos cuentas no pueden
     * divergir.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $base  Las ventas (de `query_de_ventas()`).
     * @param  array<int, int>|null  $solo_clientes  Si viene, acota a esos `current_acounts.client_id`.
     * @return \Illuminate\Support\Collection
     */
    protected static function deuda_agrupada_por_cliente($base, $solo_clientes = null)
    {
        /*
         * Los totales se calculan sobre las filas de `current_acounts` del conjunto y NO sobre las
         * ventas: la deuda viva es `debe - pagandose` de la cuenta corriente, no el total de la
         * venta (que puede tener una parte ya cobrada). La condición que elige esas filas es la
         * MISMA que puso a la venta en el conjunto, invocada y no copiada.
         */
        $condicion = VentasSinCobrarHelper::condicion_de_deuda();

        $pesos = implode(',', array_map('intval', RecolectorBase::MONEDAS_PESOS));

        /*
         * 🔴 LA MONEDA SE RESUELVE COMO LA RESUELVE EL MODELO, Y POR ESO HAY UN JOIN.
         *
         * `current_acounts.moneda_id` viene NULL en TODA fila de deuda nacida de una venta:
         * `CurrentAcountFromSaleHelper::crear_current_acount()` —que es el único camino— nunca la
         * escribe. La moneda real vive en la fila de `credit_accounts` de la que cuelga la cuenta
         * (hay una por cliente y por moneda), y por eso el modelo tiene
         * `CurrentAcount::getMonedaIdAttribute()`, que cae al `credit_account`.
         *
         * Con el `COALESCE(moneda_id, 1)` pelado que había acá, ese NULL se leía como pesos SIEMPRE:
         * toda la deuda entraba en `total_pendiente_en_pesos` y `ventas_en_otra_moneda` daba 0 fijo.
         * A un comercio con la extensión de ventas en dólares se le sumaban pesos y dólares como si
         * fueran la misma unidad, justo lo contrario de lo que promete la descripción de la tool.
         *
         * 🔴 Las DOS puntas de esta respuesta tienen que resolver la moneda por el MISMO camino:
         * `venta_mas_vieja()` ya lo hacía por el accesor, así que una venta en dólares podía venir
         * con `en_pesos = false` mientras su deuda ya estaba sumada al total "en pesos". Ese
         * desacuerdo adentro de la misma respuesta es la prueba de que era un defecto y no una
         * decisión.
         *
         * El último `1` del COALESCE es el mismo "sin moneda en ningún lado se lee como pesos" del
         * accesor (que devuelve null) y de `venta_mas_vieja()` (que lee null como pesos).
         */
        $moneda = 'COALESCE(current_acounts.moneda_id, credit_accounts.moneda_id, 1)';

        /*
         * El LEFT JOIN no puede duplicar filas: `credit_account_id` es un belongsTo, así que trae a
         * lo sumo una. Y `condicion_de_deuda()` avisa en su docblock que sus columnas van sin
         * calificar y que el caller que joinea tiene que fijarse: `debe`, `status` y `pagandose` no
         * existen en `credit_accounts`, así que no hay ambigüedad. Las que sí compartirían nombre
         * (`saldo`, `user_id`, `moneda_id`, `id`) van calificadas acá.
         */
        $query = DB::table('current_acounts')
            ->leftJoin('credit_accounts', 'credit_accounts.id', '=', 'current_acounts.credit_account_id')
            ->whereIn('current_acounts.sale_id', (clone $base)->select('sales.id'))
            ->where($condicion);

        if (! is_null($solo_clientes)) {
            $query->whereIn('current_acounts.client_id', $solo_clientes);
        }

        return $query
            ->groupByRaw('COALESCE(current_acounts.client_id, 0)')
            ->selectRaw(
                'COALESCE(current_acounts.client_id, 0) as cliente_id, '
                . 'COUNT(*) as ventas, '
                . 'SUM(CASE WHEN ' . $moneda . ' IN (' . $pesos . ') THEN 1 ELSE 0 END) as ventas_en_pesos, '
                . 'SUM(CASE WHEN ' . $moneda . ' IN (' . $pesos . ') THEN current_acounts.debe - COALESCE(current_acounts.pagandose, 0) ELSE 0 END) as pendiente_en_pesos'
            )
            ->get();
    }

    /**
     * A QUÉ EMPLEADO SE RECORTA EL CONJUNTO, con el criterio LITERAL de la pantalla
     * (`SaleController::ventas_sin_cobrar()`): el recorte está PRENDIDO por defecto y solo se apaga
     * para el dueño o para quien tenga `ver_alertas_de_todos_los_empleados`.
     *
     * 🔴 Si esto devolviera siempre null —como hacía hasta el 21/9/2026— la tool le contestaría a un
     * vendedor el total del negocio entero, el ranking de deudores con nombre y monto y la venta más
     * vieja de cualquier compañero: datos que ese mismo vendedor no puede ver en ninguna pantalla del
     * sistema. Una tool que dice espejar una pantalla tiene que espejar también su alcance.
     *
     * ⚠️ El "es el dueño" se decide comparando ids y no con `PermisosIaHelper::es_dueno()`, porque es
     * el `is_owner()` del controller al pie de la letra (`userId() == userId(false)`) y de paso hace
     * de guarda de tenencia: una persona que no es este dueño ni empleado suyo nunca ensancha el
     * conjunto, se recorta a sí misma y no ve nada.
     *
     * Es público desde la misión asistente-envios-a-clientes (16/10/2026): el recordatorio de cobro
     * del asistente arma la vista previa con las ventas del mismo recorte, y la regla es una sola.
     *
     * ⚠️ Sin persona identificada NO se recorta, y eso no abre ningún agujero: desde el chat siempre
     * llega una, porque `ai_conversations` nace con tenencia doble (`user_id` la cuenta y
     * `auth_user_id` la persona) en todos sus caminos de alta. Los llamadores que pueden quedar sin
     * persona son los que hablan por el negocio entero (tests, consola), y además no habría a qué
     * empleado recortar.
     *
     * @param  \App\Models\User|null  $persona
     * @param  int  $owner_id
     * @return int|null
     */
    public static function employee_id_del_recorte($persona, $owner_id)
    {
        if (is_null($persona)) {

            return null;
        }

        if ((int) $persona->id === (int) $owner_id) {

            return null;
        }

        if (! empty($persona->ver_alertas_de_todos_los_empleados)) {

            return null;
        }

        return (int) $persona->id;
    }

    /**
     * El umbral de antigüedad que finalmente se aplica: el que pidió el modelo si mandó uno, y si no
     * **cero, o sea todas**.
     *
     * 🔴 SIN `dias` NO SE USA LA CASCADA DE LA PANTALLA, y es a propósito (decisión de Lucas,
     * 22/9/2026). La primera versión caía a `dias_de_la_cascada()` creyendo que casi ningún comercio
     * tenía configurado el umbral de alertas, así que en la práctica daba "todas". Falso:
     * `UserSeeder` siembra `dias_alertar_*_ventas_no_cobradas = 1` para TODO usuario, en local y en
     * producción. Con eso, "¿cuánto me deben?" excluía la venta a cuenta corriente hecha esa misma
     * mañana (`DATE(created_at) <= CURDATE() - 1`), mientras "¿qué me debe Pérez?"
     * (`consultar_ventas_impagas_de_un_cliente`, con `dias = 0`) sí la incluía: dos números que no
     * cerraban en la misma conversación. El umbral de alertas es para "qué se está atrasando", no
     * para "cuánto me deben"; el modelo lo pide explícito cuando la pregunta es esa.
     *
     * `dias_de_la_cascada()` queda: es la copia fiel del criterio de la pantalla y sirve el día que
     * se quiera exponer "las vencidas según mis alertas" como pregunta propia.
     *
     * @param  int|null  $dias  Lo que pidió el modelo. null = no pidió nada = todas.
     * @param  \App\Models\User|null  $persona
     * @param  \App\Models\User  $owner
     * @return int
     */
    protected static function umbral_de_dias($dias, $persona, $owner)
    {
        if (is_null($dias)) {
            $dias = 0;
        }

        $dias = (int) $dias;

        if ($dias < 0) {
            $dias = 0;
        }

        if ($dias > self::TOPE_DIAS) {
            $dias = self::TOPE_DIAS;
        }

        return $dias;
    }

    /**
     * Si el umbral propio de cada venta (`sales.dias_alerta_venta_no_cobrada_personalizado`) entra en
     * la cuenta del asistente: NUNCA (revisión de la misión asistente-arreglos-segunda-prueba, 19/10/2026).
     *
     * 🔴 EL CASO REAL (segunda prueba por WhatsApp contra demo3). El dueño pidió "mandales un
     * recordatorio a los que me deben hace más de 20 días" y una venta de 23 días quedó afuera: todas las
     * ventas a cuenta corriente sembradas en demo3 tienen el umbral propio en 30 (SemillaHelper), y el
     * `COALESCE` de la pantalla la medía contra 30 y no contra los 20 que dijo él. El umbral propio es la
     * ALERTA de esa venta ("avisame a los 30 días"), no si se debe.
     *
     * 🔴 Y SIN DÍAS TAMPOCO. La primera versión de este arreglo dejaba el `COALESCE` cuando la persona no
     * decía días, y el asistente se contradecía: "¿qué me debe X?" (consultar_ventas_impagas_de_un_cliente,
     * toda la deuda viva) traía la venta de 23 días y "¿cuánto me deben?" (consultar_ventas_sin_cobrar)
     * daba 0. La descripción de la herramienta ya dice "Si no lo mandás son TODAS las ventas sin cobrar,
     * incluida la de hoy": ahora es verdad. Con false y `dias = 0`, query_de_ventas() no pone ningún
     * umbral; con `dias > 0`, esos días tal cual. La pantalla y la alerta automática no cambian (no pasan
     * por acá).
     *
     * Se deja como método (y no un false suelto) porque es la regla que comparten la consulta, la tarjeta
     * del recordatorio y su job: tiene que haber un solo lugar que la diga.
     *
     * @param  int  $dias  El umbral ya resuelto por umbral_de_dias() (no cambia la respuesta).
     * @return bool  Siempre false: los días tal cual, o toda la deuda viva.
     */
    public static function respetar_umbral_propio($dias)
    {
        return false;
    }

    /**
     * LA CASCADA POR ROL DE LA PANTALLA, copiada de `SaleController::ventas_sin_cobrar()`: dueño →
     * el umbral de administradores; administrador sin columna propia → el de administradores;
     * cualquiera con columna propia → la suya; el resto → el de empleados.
     *
     * 🔴 UNA DIFERENCIA A PROPÓSITO CON LA PANTALLA, Y ES LA ÚNICA: si la cascada termina en null
     * —el caso normal, porque las tres columnas son nullable y sin default y casi ningún comercio
     * las configuró— acá se devuelve 0 ("todas") y no null. Con null, el
     * `DATE(created_at) <= DATE_SUB(CURDATE(), INTERVAL NULL DAY)` de la query compartida da NULL
     * para toda fila y el conjunto queda VACÍO. En la pantalla eso se ve como un listado vacío, que
     * es raro pero mudo; en el chat el asistente le contestaría al dueño "no tenés nada sin cobrar"
     * teniendo el negocio lleno de deuda, que es la peor forma de fallar que tiene esta tool.
     *
     * ⚠️ Sin persona identificada tampoco hay cascada posible: queda 0, que es el comportamiento
     * anterior a esta corrección.
     *
     * @param  \App\Models\User|null  $persona
     * @param  \App\Models\User  $owner
     * @return int
     */
    protected static function dias_de_la_cascada($persona, $owner)
    {
        if (is_null($persona)) {

            return 0;
        }

        $dias = $owner->dias_alertar_empleados_ventas_no_cobradas;

        $es_dueno = ((int) $persona->id === (int) $owner->id);

        if ($es_dueno) {

            $dias = $owner->dias_alertar_administradores_ventas_no_cobradas;

        } elseif (PermisosIaHelper::es_admin($persona) && is_null($persona->dias_alertar_empleados_ventas_no_cobradas)) {

            $dias = $owner->dias_alertar_administradores_ventas_no_cobradas;

        } elseif (! is_null($persona->dias_alertar_empleados_ventas_no_cobradas)) {

            $dias = $persona->dias_alertar_empleados_ventas_no_cobradas;
        }

        return is_null($dias) ? 0 : (int) $dias;
    }

    /**
     * La venta impaga más vieja, calculada contra el conjunto ENTERO y no contra el ranking: "¿cuál
     * es la más vieja que tengo sin cobrar?" es una de las dos preguntas que esta tool tiene que
     * contestar en una sola vuelta, y el cliente que la tiene puede no estar entre los veinte que más
     * deben. Es el mismo criterio de `venta_impaga_mas_vieja` en la consulta por cliente.
     *
     * "El conjunto" es el que ya viene recortado por `employee_id_del_recorte()`: a un vendedor le
     * toca la más vieja DE LAS SUYAS, igual que en la pantalla.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $base
     * @return array<string, mixed>|null
     */
    protected static function venta_mas_vieja($base)
    {
        $venta = (clone $base)
            ->with('current_acount', 'client')
            ->orderBy('sales.created_at', 'ASC')
            ->orderBy('sales.id', 'ASC')
            ->first();

        if (is_null($venta)) {

            return null;
        }

        $cuenta = $venta->current_acount;

        $debe      = (is_null($cuenta) || is_null($cuenta->debe)) ? 0.0 : (float) $cuenta->debe;
        $pagandose = (is_null($cuenta) || is_null($cuenta->pagandose)) ? 0.0 : (float) $cuenta->pagandose;

        /*
         * La moneda viaja en la fila, igual que en ventas_impagas_de_un_cliente y por el mismo
         * motivo: el prompt le dice al asistente que los importes son en pesos salvo aviso, así que
         * un número pelado de una venta en dólares se informa en pesos. Manda la de la cuenta
         * corriente, que es la fila donde vive la deuda; la de la venta queda de respaldo.
         */
        $moneda_id = null;

        if (! is_null($cuenta) && ! is_null($cuenta->moneda_id)) {
            $moneda_id = (int) $cuenta->moneda_id;
        } elseif (! is_null($venta->moneda_id)) {
            $moneda_id = (int) $venta->moneda_id;
        }

        return [
            'venta_id'        => (int) $venta->id,
            'numero'          => is_null($venta->num) ? null : (int) $venta->num,
            'cliente'         => is_null($venta->client) ? null : (string) $venta->client->name,
            'fecha'           => is_null($venta->created_at) ? null : $venta->created_at->format('Y-m-d'),
            'dias_sin_cobrar' => is_null($venta->created_at) ? null : (int) $venta->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()),
            'total'           => is_null($venta->total) ? 0.0 : (float) $venta->total,
            'pendiente'       => round($debe - $pagandose, 2),
            'moneda_id'       => $moneda_id,
            // null se lee como pesos, igual que en todo el resto (MONEDAS_PESOS incluye el 0 además del 1).
            'en_pesos'        => is_null($moneda_id) || in_array($moneda_id, RecolectorBase::MONEDAS_PESOS, true),
        ];
    }
}
