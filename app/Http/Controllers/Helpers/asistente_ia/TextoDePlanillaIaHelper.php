<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessageAction;
use App\Models\AiPlanilla;
use App\Models\Client;
use App\Models\IvaCondition;
use Carbon\Carbon;

/**
 * Lo que lee el dueño sobre las ventas de una planilla, armado por CÓDIGO (misión
 * agente-venta-por-planilla, 02/11/2026).
 *
 * 🔴 EL MODELO NO LO ESCRIBE, y no es prolijidad. En el caso real, el modelo escribió una lista correcta, el
 * dueño dijo «SI», y en el mensaje siguiente otra lista con clientes cruzados y una caja de 12 unidades: el
 * dueño confirmó sin notarlo. La lista que se confirma tiene que ser LA TABLA, palabra por palabra.
 *
 * 🔴 EL LARGO IMPORTA. El admin manda el texto de una respuesta en un solo mensaje de WhatsApp, y Meta rechaza
 * lo que pasa de 4096 caracteres (el mensaje se pierde): ver LARGO_MAXIMO y
 * PlanillaIaHelper::RENGLONES_POR_TARJETA_DE_TEXTO.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class TextoDePlanillaIaHelper
{
    /** El tope del texto, con margen sobre los 4096 de WhatsApp. */
    const LARGO_MAXIMO = 3800;

    /** La salida de una compra repetida de verdad: la herramienta es corregir_renglon_de_planilla con `cargar_igual`. */
    const SALIDA_DE_LAS_REPETIDAS = 'Si alguna es otra compra, decime cuál y la cargo igual.';

    /** Una propuesta por WhatsApp sale en un único mensaje: se mide completa, sin cortar filas ni avisos. */
    public static function cabe_en_un_mensaje($texto): bool
    {
        return mb_strlen((string) $texto) <= self::LARGO_MAXIMO;
    }

    /**
     * Si ni una fila entra con sus detalles, no hay una tarjeta que la persona pueda confirmar.
     * El pedido identifica la fila sin repetir el nombre enorme que causó el exceso.
     */
    public static function pedido_para_acortar(AiPlanilla $planilla, array $fila): string
    {
        $inicio = 'El renglón ' . (int) $fila['numero'] . ' no entra completo en un mensaje de WhatsApp. No armé la tarjeta. ';
        $nombre = self::nombre_del_cliente($fila);
        $codigo = isset($fila['valores'][RolesDePlanillaIaHelper::CODIGO]) ? (string) $fila['valores'][RolesDePlanillaIaHelper::CODIGO] : '';

        if (mb_strlen($nombre) > 1000 || mb_strlen($codigo) > 1000) {
            return $inicio . 'Acortá el nombre del cliente o el código del artículo de ese renglón y volvé a pedirme la carga.';
        }

        if (count(self::filas_sin_venta($planilla)) || self::cambios_pedidos($planilla, [$fila]) !== '') {
            return $inicio . 'El detalle de los cambios o de las personas sin venta es demasiado largo. Mandá una planilla nueva con las correcciones ya aplicadas y solo las ventas que quieras cargar.';
        }

        return $inicio . 'Acortá el nombre del cliente o del artículo de ese renglón, o cargá esa venta por separado desde Ventas.';
    }

    /**
     * El texto, la tarjeta y el resumen de una propuesta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiPlanilla  $planilla
     * @param  array  $tanda  Los renglones que entran en esta tarjeta.
     * @param  array  $cobro  Lo que devolvió PlanillaIaHelper::definiciones_de_cobro().
     * @param  string|null  $aviso_de_stock
     * @param  int  $quedan_despues  Los renglones que no entraron en la tarjeta.
     * @param  bool  $por_texto  true en un canal sin botones.
     * @return array{texto: string, presentacion: array, resumen: string}
     */
    public static function propuesta(ContextoDeCargaIa $contexto, AiPlanilla $planilla, array $tanda, array $cobro, $aviso_de_stock, $quedan_despues, $por_texto): array
    {
        $total = 0.0;
        $avisos_de_redondeo = [];
        $nuevos = [];

        foreach ($tanda as $fila) {

            $total_de_la_fila = (float) RolesDePlanillaIaHelper::total_de($fila, $planilla->importe_es);
            $se_vende = (float) RolesDePlanillaIaHelper::total_que_se_vende_de($fila, $planilla->importe_es);
            $cantidad = (float) RolesDePlanillaIaHelper::cantidad_de($fila);

            /* 🔴 Lo que se va a vender, no lo que dice la planilla: es lo que el dueño confirma. */
            $total += $se_vende;

            /* Un total que no se divide exacto por la cantidad: lo dice, porque la venta se guarda por unidad. */
            if (abs($se_vende - $total_de_la_fila) >= 0.01) {

                $avisos_de_redondeo[] = 'En el renglón ' . (int) $fila['numero'] . ' ' . FormatoIaHelper::monto($total_de_la_fila) . ' dividido ' . PropuestaVentaIaHelper::numero($cantidad)
                    . ' no da exacto: queda en ' . FormatoIaHelper::monto($se_vende) . '.';
            }

            if (!empty($fila['cliente_nuevo'])) {

                $nombre = isset($fila['valores'][RolesDePlanillaIaHelper::NOMBRE]) ? (string) $fila['valores'][RolesDePlanillaIaHelper::NOMBRE] : '';

                $nuevos[RolesDePlanillaIaHelper::normalizar_texto($nombre)] = $nombre;
            }
        }

        $texto_del_cobro = self::texto_del_cobro($cobro);

        $sin_facturar = self::sin_facturar($tanda);

        $avisos = array_values(array_filter(array_merge(is_string($aviso_de_stock) ? [$aviso_de_stock] : [], $avisos_de_redondeo, self::avisos_de_iva($tanda))));

        $ya = self::ya_registradas($planilla);

        $se_pueden_reabrir = self::hay_ya_registradas_para_reabrir($planilla);

        $sin_venta = self::filas_sin_venta($planilla);

        $cambios = self::cambios_pedidos($planilla, $tanda);

        $de_la_fecha = self::linea_de_la_fecha($tanda);

        $texto = '';

        /*
         * Se achica el nombre del artículo y al final se resumen las ya registradas, conservando completas las filas.
         * Esto puede seguir excediendo el límite por nombres de clientes, cambios o avisos: PlanillaIaHelper mide
         * el candidato completo y reduce la tanda ANTES de crear una tarjeta, o rechaza si ni una fila entra.
         */
        foreach ([60, 40, 25, 0, -1] as $largo_del_nombre) {

            $de_las_ya = $largo_del_nombre < 0 && count($ya) ? [count($ya) . ' de esta planilla ya estaban registradas y no se vuelven a cargar.'] : $ya;

            $largo_del_nombre = max(0, $largo_del_nombre);

            $lineas = [];

            $lineas[] = 'Planilla N° ' . (int) $planilla->id . ': ' . self::cuantas_ventas(count($tanda)) . ' para cargar.';
            $lineas[] = '';

            foreach ($tanda as $fila) {

                $lineas[] = self::linea_del_renglon($fila, $planilla->importe_es, $largo_del_nombre);
            }

            if (count($de_las_ya)) {

                $lineas[] = '';
                $lineas[] = 'Ya registradas, no se vuelven a cargar:';

                foreach ($de_las_ya as $linea) {

                    $lineas[] = '- ' . $linea;
                }

                if ($se_pueden_reabrir) {

                    $lineas[] = self::SALIDA_DE_LAS_REPETIDAS;
                }
            }

            if (count($sin_venta)) {

                $lineas[] = '';
                $lineas[] = 'No cruzaron con ningún renglón y no traen qué se vendió, no se cargan: ' . implode('; ', $sin_venta) . '.';
            }

            $lineas[] = '';
            $lineas[] = 'Total: ' . FormatoIaHelper::monto($total);
            $lineas[] = 'Cobro: ' . $texto_del_cobro;
            $lineas[] = $de_la_fecha;

            if ($cambios !== '') {

                $lineas[] = $cambios;
            }

            if (count($nuevos)) {

                $lineas[] = 'Clientes nuevos que se crean: ' . count($nuevos) . '.';
            }

            foreach ($avisos as $aviso) {

                $lineas[] = $aviso;
            }

            if ($sin_facturar !== '') {

                $lineas[] = $sin_facturar;
            }

            if ($quedan_despues > 0) {

                $lineas[] = 'Después de estas quedan ' . (int) $quedan_despues . ' renglones más, que van en la tarjeta siguiente.';
            }

            $lineas[] = '';
            $lineas[] = $por_texto
                ? '¿Las cargo? Contestame «sí».'
                : '¿Las cargo? Tocá Confirmar en la tarjeta o contestame «sí».';

            $texto = implode("\n", $lineas);

            if (self::cabe_en_un_mensaje($texto)) {

                break;
            }
        }

        $renglones = [];

        foreach ($tanda as $fila) {

            $renglones[] = ['etiqueta' => 'Renglón ' . (int) $fila['numero'], 'valor' => self::linea_sin_numero($fila, $planilla->importe_es, 60)];
        }

        $renglones[] = ['etiqueta' => 'Cobro', 'valor' => $texto_del_cobro];

        if (count($nuevos)) {

            $renglones[] = ['etiqueta' => 'Clientes nuevos', 'valor' => implode(', ', array_values($nuevos))];
        }

        foreach ($ya as $linea) {

            $renglones[] = ['etiqueta' => 'Ya registrada', 'valor' => $linea];
        }

        $renglones[] = ['etiqueta' => 'Total', 'valor' => FormatoIaHelper::monto($total)];

        $aviso_de_la_tarjeta = trim(implode(' ', array_merge([$de_la_fecha], $cambios === '' ? [] : [$cambios], $avisos, $sin_facturar === '' ? [] : [$sin_facturar])));

        return [
            'texto'        => $texto,
            'presentacion' => [
                'titulo'          => 'Ventas de la planilla N° ' . (int) $planilla->id,
                'renglones'       => $renglones,
                'aviso'           => $aviso_de_la_tarjeta === '' ? null : $aviso_de_la_tarjeta,
                'texto_confirmar' => 'Cargar ' . self::cuantas_ventas(count($tanda)),
            ],
            'resumen'      => 'Planilla N° ' . (int) $planilla->id . ' · ' . self::cuantas_ventas(count($tanda)) . ' · ' . $texto_del_cobro . ' · Total ' . FormatoIaHelper::monto($total),
        ];
    }

    /**
     * Con qué fecha se cargan, SIEMPRE y por código. 🔴 Con «TODAS CON FECHA 31/10» el modelo contestaba «la fecha anotada» y la venta salía con
     * la de hoy, que es lo decidido: la de la planilla va a observaciones (EjecucionDePlanillaIaHelper::observaciones()).
     *
     * @param  array  $tanda
     * @return string
     */
    protected static function linea_de_la_fecha(array $tanda): string
    {
        $trae_fechas = false;

        foreach ($tanda as $fila) {

            $trae_fechas = $trae_fechas || isset($fila['valores'][RolesDePlanillaIaHelper::FECHA]);
        }

        return 'Se cargan con la fecha de hoy (' . Carbon::today()->format('d/m') . ')' . ($trae_fechas ? '; la de la planilla queda en observaciones.' : '.');
    }

    /**
     * El aviso de los clientes que ya existen y a los que la planilla les pide otro comprobante del que les
     * corresponde por su condición de IVA. 🔴 Al cliente existente no se lo cambia sin que nadie lo pida: se avisa,
     * porque es lo que le pasó al dueño en el caso real: la Factura A le salió B y tuvo que rehacerla.
     *
     * @param  array  $filas
     * @return array<int, string>
     */
    protected static function avisos_de_iva(array $filas): array
    {
        $ids = [];

        foreach ($filas as $fila) {

            if (!empty($fila['cliente_id'])) {

                $ids[] = (int) $fila['cliente_id'];
            }
        }

        $de_factura_a = (int) IvaCondition::where('name', 'Responsable inscripto')->value('id');

        $condiciones = count($ids) ? Client::whereIn('id', array_values(array_unique($ids)))->pluck('iva_condition_id', 'id') : [];

        $avisos = [];

        foreach ($filas as $fila) {

            /* El cliente nuevo: la planilla no alcanza para darle una condición (EjecucionDePlanillaIaHelper::condicion_de_iva()). */
            if (!empty($fila['cliente_nuevo'])) {

                $aviso = EjecucionDePlanillaIaHelper::condicion_de_iva($fila)['aviso'];

                if (!is_null($aviso)) {

                    $avisos[] = 'Ojo con el renglón ' . (int) $fila['numero'] . ': la planilla ' . $aviso . '. ' . self::nombre_del_cliente($fila) . ' se crea sin condición de IVA: cargásela antes de facturar.';
                }

                continue;
            }

            if (empty($fila['cliente_id'])) {
                continue;
            }

            $letra = RolesDePlanillaIaHelper::letra_de_comprobante(isset($fila['valores'][RolesDePlanillaIaHelper::COMPROBANTE]) ? $fila['valores'][RolesDePlanillaIaHelper::COMPROBANTE] : '');

            $condicion = isset($condiciones[(int) $fila['cliente_id']]) ? (int) $condiciones[(int) $fila['cliente_id']] : 0;

            if ($letra === 'a' && $condicion !== $de_factura_a) {

                $avisos[] = 'Ojo con el renglón ' . (int) $fila['numero'] . ': la planilla pide Factura A y ' . self::nombre_del_cliente($fila) . ' no figura como responsable inscripto. Revisalo antes de facturar.';
            }
        }

        return $avisos;
    }

    /**
     * Lo que se le dice cuando no queda nada por cargar.
     *
     * @param  \App\Models\AiPlanilla  $planilla
     * @return string
     */
    public static function nada_para_cargar(AiPlanilla $planilla): string
    {
        $ya = self::ya_registradas($planilla);

        if (!count($ya)) {

            return 'La planilla N° ' . (int) $planilla->id . ' no tiene renglones por cargar.';
        }

        return 'De la planilla N° ' . (int) $planilla->id . ' no queda nada por cargar. Ya están registradas: ' . implode('; ', $ya) . '. No se vuelven a cargar.'
            . (self::hay_ya_registradas_para_reabrir($planilla) ? ' ' . self::SALIDA_DE_LAS_REPETIDAS : '');
    }

    /**
     * El texto con el resultado de una tarjeta ya ejecutada, armado con lo que devolvió la ejecución.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @return string
     */
    public static function resultado(AiMessageAction $accion): string
    {
        $resultado = json_decode((string) json_encode($accion->resultado), true);

        if (!is_array($resultado)) {

            return 'Listo: las ventas de la planilla quedaron registradas.';
        }

        $ventas = isset($resultado['ventas']) && is_array($resultado['ventas']) ? $resultado['ventas'] : [];
        $ya = isset($resultado['ya_registradas']) && is_array($resultado['ya_registradas']) ? $resultado['ya_registradas'] : [];
        $no_cargadas = isset($resultado['no_cargadas']) && is_array($resultado['no_cargadas']) ? $resultado['no_cargadas'] : [];
        $sin_intentar = isset($resultado['sin_intentar']) ? (int) $resultado['sin_intentar'] : 0;

        $lineas = [];

        if (count($ventas)) {

            $lineas[] = 'Listo, cargué ' . self::cuantas_ventas(count($ventas)) . ' de la planilla N° ' . (int) ($resultado['planilla_id'] ?? 0) . ' por ' . FormatoIaHelper::monto((float) ($resultado['total'] ?? 0)) . ':';
            $lineas[] = '';

            foreach ($ventas as $venta) {

                $lineas[] = (int) $venta['renglon'] . '. ' . (string) $venta['cliente'] . ': Venta N° ' . (int) $venta['numero'] . ', ' . FormatoIaHelper::monto((float) $venta['total']);
            }

        } else {

            $lineas[] = 'No cargué ninguna venta nueva de la planilla N° ' . (int) ($resultado['planilla_id'] ?? 0) . '.';
        }

        if (count($ya)) {

            $lineas[] = '';
            $lineas[] = 'Ya estaban registradas, no las volví a cargar:';

            foreach ($ya as $venta) {

                $lineas[] = '- Renglón ' . (int) $venta['renglon'] . ', ' . (string) $venta['cliente'] . ': Venta N° ' . (int) $venta['numero'];
            }
        }

        if (count($no_cargadas)) {

            $lineas[] = '';
            $lineas[] = 'No se pudieron cargar:';

            foreach ($no_cargadas as $renglon) {

                $lineas[] = '- Renglón ' . (int) $renglon['renglon'] . ', ' . (string) $renglon['cliente'] . ': ' . (string) $renglon['motivo'];
            }
        }

        if ($sin_intentar > 0) {

            $lineas[] = '';
            $lineas[] = 'Quedaron ' . $sin_intentar . ' renglones sin cargar porque se terminó el tiempo: pedime seguir y los cargo sin repetir nada.';
        }

        if (!empty($resultado['clientes_creados'])) {

            $lineas[] = '';
            $lineas[] = 'Clientes nuevos creados: ' . (int) $resultado['clientes_creados'] . '.';
        }

        if (!empty($resultado['en_negativo']) && is_array($resultado['en_negativo'])) {

            $negativos = [];

            foreach ($resultado['en_negativo'] as $articulo) {

                $negativos[] = (string) $articulo['nombre'] . ' (' . PropuestaVentaIaHelper::numero((float) $articulo['stock']) . ')';
            }

            $lineas[] = '';
            $lineas[] = 'Quedaron con stock en negativo: ' . implode('; ', $negativos) . '.';
        }

        if (!empty($resultado['sin_facturar'])) {

            $lineas[] = '';
            $lineas[] = (string) $resultado['sin_facturar'];
        }

        return implode("\n", $lineas);
    }

    /**
     * «Quedan sin facturar: la planilla pide 2 Factura A y 8 a consumidor final. Se facturan desde Ventas.»
     * 🔴 Facturar desde el agente queda afuera de esta misión: no se emiten comprobantes sin que lo decida
     * Lucas. El aviso va siempre, para que nadie crea que quedaron facturadas.
     *
     * @param  array  $filas
     * @return string
     */
    public static function sin_facturar(array $filas): string
    {
        $a = 0;
        $b = 0;

        foreach ($filas as $fila) {

            $letra = RolesDePlanillaIaHelper::letra_de_comprobante(isset($fila['valores'][RolesDePlanillaIaHelper::COMPROBANTE]) ? $fila['valores'][RolesDePlanillaIaHelper::COMPROBANTE] : '');

            if ($letra === 'a') {

                $a++;
            }

            if ($letra === 'b') {

                $b++;
            }
        }

        if ($a === 0 && $b === 0) {

            return 'Quedan sin facturar: se facturan desde Ventas.';
        }

        $partes = [];

        if ($a > 0) {

            $partes[] = $a . ' Factura A';
        }

        if ($b > 0) {

            $partes[] = $b . ' a consumidor final';
        }

        return 'Quedan sin facturar: la planilla pide ' . implode(' y ', $partes) . '. Se facturan desde Ventas.';
    }

    /**
     * «3. Juan Pérez (nuevo): 2 × Bomba Alfa (70101), $ 88.400»
     *
     * @param  array  $fila
     * @param  string|null  $importe_es
     * @param  int  $largo_del_nombre
     * @return string
     */
    protected static function linea_del_renglon(array $fila, $importe_es, $largo_del_nombre): string
    {
        return (int) $fila['numero'] . '. ' . self::linea_sin_numero($fila, $importe_es, $largo_del_nombre);
    }

    /**
     * @param  array  $fila
     * @param  string|null  $importe_es
     * @param  int  $largo_del_nombre  0: solo el código.
     * @return string
     */
    protected static function linea_sin_numero(array $fila, $importe_es, $largo_del_nombre): string
    {
        $codigo = isset($fila['valores'][RolesDePlanillaIaHelper::CODIGO]) ? RolesDePlanillaIaHelper::codigo_limpio($fila['valores'][RolesDePlanillaIaHelper::CODIGO]) : '';

        $articulo = (string) ($fila['articulo_nombre'] ?? '');

        if ($largo_del_nombre > 0 && mb_strlen($articulo) > $largo_del_nombre) {

            $articulo = rtrim(mb_substr($articulo, 0, $largo_del_nombre - 1)) . '…';
        }

        if ($largo_del_nombre === 0 && $codigo !== '') {

            $que = $codigo;

        } else {

            $que = $articulo . ($codigo !== '' ? ' (' . $codigo . (empty($fila['codigo_tomado_como']) ? '' : ', tomado como ' . $fila['codigo_tomado_como']) . ')' : '');
        }

        return self::nombre_del_cliente($fila) . (!empty($fila['cliente_nuevo']) ? ' (nuevo)' : '') . ': '
            . PropuestaVentaIaHelper::numero((float) RolesDePlanillaIaHelper::cantidad_de($fila)) . ' × ' . $que
            . ', ' . FormatoIaHelper::monto((float) RolesDePlanillaIaHelper::total_que_se_vende_de($fila, $importe_es))
            . (empty($fila['cambios']) ? '' : ' [corregido]');
    }

    /**
     * El nombre con el que se nombra al cliente de un renglón.
     *
     * @param  array  $fila
     * @return string
     */
    public static function nombre_del_cliente(array $fila): string
    {
        if (!empty($fila['cliente_nombre'])) {

            return (string) $fila['cliente_nombre'];
        }

        return isset($fila['valores'][RolesDePlanillaIaHelper::NOMBRE]) ? (string) $fila['valores'][RolesDePlanillaIaHelper::NOMBRE] : 'Sin cliente';
    }

    /**
     * Una línea por cada renglón ya registrado: «Renglón 4, Ana: Venta N° 1234».
     *
     * @param  \App\Models\AiPlanilla  $planilla
     * @return array<int, string>
     */
    protected static function ya_registradas(AiPlanilla $planilla): array
    {
        $lineas = [];

        foreach (is_array($planilla->filas) ? $planilla->filas : [] as $fila) {

            if (in_array($fila['estado'], [AiPlanilla::FILA_YA_REGISTRADA, AiPlanilla::FILA_REGISTRADA], true) && !empty($fila['venta_numero'])) {

                $lineas[] = 'Renglón ' . (int) $fila['numero'] . ', ' . self::nombre_del_cliente($fila) . ': Venta N° ' . (int) $fila['venta_numero'];
            }
        }

        return $lineas;
    }

    /**
     * «Cambios que pediste: renglón 6, cantidad 12 → 1.» 🔴 Lo escribe el código, y es la otra mitad del error del 31/10:
     * el modelo cambió una lista y el dueño confirmó sin notarlo. Una lista que cambió lo tiene que decir.
     *
     * @param  \App\Models\AiPlanilla  $planilla  Lo que cambió para toda la planilla va primero (definir_planilla).
     * @param  array  $filas
     * @return string
     */
    protected static function cambios_pedidos(AiPlanilla $planilla, array $filas): string
    {
        $definiciones = is_array($planilla->definiciones) ? $planilla->definiciones : [];

        $partes = isset($definiciones['cambios']) && is_array($definiciones['cambios']) ? $definiciones['cambios'] : [];

        foreach ($filas as $fila) {

            foreach (isset($fila['cambios']) && is_array($fila['cambios']) ? $fila['cambios'] : [] as $cambio) {

                $partes[] = 'renglón ' . (int) $fila['numero'] . ', ' . $cambio['que'] . ($cambio['a'] === '' ? '' : ' ' . $cambio['de'] . ' → ' . $cambio['a']);
            }
        }

        return count($partes) ? 'Cambios que pediste: ' . implode('; ', $partes) . '.' : '';
    }

    /**
     * true si hay renglones que el sistema marcó como ya registrados (no los que cargó esta misma planilla): son los que
     * pueden ser otra compra igual.
     *
     * @param  \App\Models\AiPlanilla  $planilla
     * @return bool
     */
    protected static function hay_ya_registradas_para_reabrir(AiPlanilla $planilla): bool
    {
        foreach (is_array($planilla->filas) ? $planilla->filas : [] as $fila) {

            if ($fila['estado'] === AiPlanilla::FILA_YA_REGISTRADA) {

                return true;
            }
        }

        return false;
    }

    /**
     * Quiénes quedaron sin venta porque una foto trajo su documento y su nombre y no cruzó con ningún renglón. El dueño
     * los ve: puede ser que falte su venta.
     *
     * @param  \App\Models\AiPlanilla  $planilla
     * @return array<int, string>
     */
    protected static function filas_sin_venta(AiPlanilla $planilla): array
    {
        $nombres = [];

        foreach (is_array($planilla->filas) ? $planilla->filas : [] as $fila) {

            if (!empty($fila['sin_venta'])) {

                $nombres[] = self::nombre_del_cliente($fila) . (isset($fila['valores'][RolesDePlanillaIaHelper::DOCUMENTO]) ? ' (' . $fila['valores'][RolesDePlanillaIaHelper::DOCUMENTO] . ')' : '');
            }
        }

        return $nombres;
    }

    /**
     * @param  array  $cobro
     * @return string
     */
    protected static function texto_del_cobro(array $cobro): string
    {
        if ($cobro['cobro'] === PropuestaVentaIaHelper::COBRO_CUENTA_CORRIENTE) {

            return 'a la cuenta corriente de cada cliente';
        }

        if (is_null($cobro['metodo'])) {

            return 'contado';
        }

        return (string) $cobro['metodo']->name . (is_null($cobro['caja']) ? '' : ' · ' . (string) $cobro['caja']->name);
    }

    /**
     * «1 venta» o «10 ventas».
     *
     * @param  int  $cuantas
     * @return string
     */
    public static function cuantas_ventas($cuantas): string
    {
        return (int) $cuantas === 1 ? '1 venta' : (int) $cuantas . ' ventas';
    }
}
