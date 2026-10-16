<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Client;
use App\Models\WhatsappBotConfig;
use App\Services\RecordatorioCobroSenderService;

/**
 * "MANDALES UN RECORDATORIO A LOS QUE ME DEBEN MÁS DE X HACE MÁS DE N DÍAS" (misión
 * asistente-envios-a-clientes, 16/10/2026). El pedido de Lucas, textual: *"que le envíe recordatorios
 * de pago a todos los clientes que deban más de x plata hace más de x tiempo"*.
 *
 * 🔴 NO SE INVENTA NADA: ES EL RECORDATORIO DEL MÓDULO DE ALERTAS. El mismo mensaje
 * (`RecordatorioCobroSenderService`), el mismo camino (texto libre con la ventana de 24 h abierta, la
 * plantilla `cc_cli_recordatorio_cobro` con el PDF de la cuenta corriente si está cerrada), el mismo
 * freno de 24 h, el mismo permiso (`alerts.recordatorio_cobro`, o dueño/administrador), la misma
 * extensión (`whatsapp`) y el mismo encolado (`encolar_lote()`: dos pasadas, el lote en Cache antes
 * de despachar, un `SendRecordatorioCobroJob` por cliente que revalida todo antes de mandar).
 *
 * 🔴 Y EL FILTRO ES EL DE LA CONSULTA, NO UNO NUEVO. "Los que me deben más de X hace más de N días"
 * son los mismos clientes que el asistente le listó al dueño con `consultar_ventas_sin_cobrar`
 * (`monto_minimo` + `dias`): deuda TOTAL de X o más y alguna venta impaga de N días o más
 * (`VentasSinCobrarIaHelper::clientes_deudores()`). Si cada herramienta resolviera "me deben" a su
 * manera, el dueño vería una lista y el recordatorio le llegaría a otra.
 *
 * 🔴 SIEMPRE ESPERA EL SÍ, EN LOS TRES MODOS (HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES). Le
 * escribe a clientes del negocio y un mensaje que salió no se desmanda. La tarjeta (y lo que el
 * modelo le cuenta por WhatsApp) dice a quiénes, cuántos, a quiénes no y por qué, y el texto exacto
 * del primero, antes del sí.
 *
 * Al confirmar se mandan los clientes QUE EL DUEÑO VIO (los ids de la tarjeta), revalidados: el que
 * en el medio dejó de cumplir el filtro (pagó) no recibe nada, y el resto pasa por las guardas de
 * siempre.
 *
 * PHP 7.4: sin match, sin str_contains, sin nullsafe, sin argumentos nombrados.
 */
class PropuestaRecordatoriosCobroIaHelper
{
    /** La extensión del módulo de WhatsApp: el grupo de rutas del recordatorio la exige. */
    const EXTENSION = 'whatsapp';

    /** El permiso del recordatorio de cobro (el mismo que `RecordatorioCobroController::check_permiso()`). */
    const PERMISO = 'alerts.recordatorio_cobro';

    /** Motivo de salteo propio de esta herramienta: el cliente que se nombró no debe nada en el filtro. */
    const MOTIVO_NO_CUMPLE = 'no_cumple_el_filtro';

    /** Tope de clientes que se pueden pedir de a uno (la lista explícita). */
    const MAXIMO_DE_CLIENTES_NOMBRADOS = 100;

    /**
     * Herramienta proponer_recordatorios_de_cobro.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  monto_minimo, dias, client_ids, reemplaza_a
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        $corte = self::verificar_acceso($contexto);

        if (!is_null($corte)) {

            return RespuestaDeCargaIa::error($corte);
        }

        $monto_minimo = self::numero_positivo(EntradaDeCargaIa::valor($input, 'monto_minimo'));

        $dias_pedidos = EntradaDeCargaIa::valor($input, 'dias');

        $dias_pedidos = is_numeric($dias_pedidos) && (int) $dias_pedidos > 0 ? (int) $dias_pedidos : null;

        $client_ids = self::ids(EntradaDeCargaIa::valor($input, 'client_ids'));

        if (count($client_ids) > self::MAXIMO_DE_CLIENTES_NOMBRADOS) {

            return RespuestaDeCargaIa::error('Son demasiados clientes para nombrarlos de a uno: usá el filtro de deuda (monto_minimo y dias).');
        }

        $evaluacion = self::evaluar($contexto, $monto_minimo, $dias_pedidos, $client_ids);

        $service = new RecordatorioCobroSenderService();

        $config = WhatsappBotConfig::getForUser($contexto->owner_id);

        $reciben = [];
        $salteados = $evaluacion['salteados'];

        // Las MISMAS guardas que el masivo de la pantalla (motivo_de_salteo), cliente por cliente.
        foreach ($evaluacion['clientes'] as $fila) {

            $client = $fila['client'];

            $chat = RecordatorioCobroSenderService::buscar_chat($contexto->owner_id, $client);

            $motivo = $service->motivo_de_salteo($contexto->owner_id, $client, $config, $chat);

            if (!is_null($motivo)) {

                $salteados[] = ['nombre' => (string) $client->name, 'motivo' => $motivo['motivo'], 'mensaje' => $motivo['mensaje']];

                continue;
            }

            $reciben[] = $fila;
        }

        $grupos = DestinatariosIaHelper::agrupar_por_motivo($salteados);

        if (!count($reciben)) {

            return RespuestaDeCargaIa::error(
                count($evaluacion['clientes']) || count($salteados)
                    ? 'No hay a quién mandarle el recordatorio: ' . self::motivos_en_una_linea($grupos)
                    : 'Con ese filtro no hay ningún cliente que deba: no hay a quién mandarle el recordatorio.',
                ['no_les_llega' => $grupos]
            );
        }

        /*
         * 🔴 EL TEXTO EXACTO: el cuerpo REAL de previsualizar() para el primero, que es el mismo
         * armado que va a usar enviar() (los dos pasan por armar_contenido()). A cada uno le llega el
         * suyo, con sus ventas y sus montos: eso lo dice la aclaración.
         */
        $primero = $reciben[0]['client'];

        $ventas_del_primero = self::ventas_del_cliente($contexto, $primero->id, $evaluacion['dias']);

        $preview = $service->previsualizar($contexto->owner_id, $primero, $ventas_del_primero, is_null($contexto->persona) ? null : $contexto->persona->id);

        $texto_que_sale = (string) $preview['body'];

        $nombres = [];
        $ids_que_reciben = [];
        $por_canal = ['texto_libre' => 0, 'plantilla' => 0];

        foreach ($reciben as $fila) {
            $nombres[] = (string) $fila['client']->name;
            $ids_que_reciben[] = (int) $fila['client']->id;

            $chat = RecordatorioCobroSenderService::buscar_chat($contexto->owner_id, $fila['client']);

            $por_canal[(!is_null($chat) && $chat->is_within_service_window()) ? 'texto_libre' : 'plantilla']++;
        }

        $aclaracion = 'A cada cliente le llega su propio recordatorio, con SUS ventas impagas'
            . ($evaluacion['dias'] > 0 ? ' de ' . $evaluacion['dias'] . ' días o más' : '')
            . ' y sus montos. El texto de arriba es el de ' . $primero->name . ' ('
            . ($preview['canal'] === 'texto_libre' ? 'como mensaje, porque escribió en las últimas 24 horas' : 'como plantilla de WhatsApp, con el resumen de su cuenta en PDF')
            . ').';

        $renglones = [
            ['etiqueta' => 'Filtro', 'valor' => self::descripcion_del_filtro($monto_minimo, $evaluacion['dias'], $client_ids)],
            ['etiqueta' => 'Le llega a ' . count($nombres), 'valor' => DestinatariosIaHelper::lista_de_nombres($nombres)],
            ['etiqueta' => 'Cómo sale', 'valor' => self::descripcion_de_canales($por_canal)],
        ];

        foreach (DestinatariosIaHelper::renglones_de_salteados($grupos) as $renglon) {
            $renglones[] = $renglon;
        }

        $renglones[] = ['etiqueta' => 'Texto (el de ' . $primero->name . ')', 'valor' => $texto_que_sale];

        sort($ids_que_reciben);

        // Lo que el sistema le agrega al mensaje por WhatsApp antes del sí (DestinatariosIaHelper::bloque()).
        $bloque = DestinatariosIaHelper::bloque(
            'Recordatorio de cobro por WhatsApp',
            [
                DestinatariosIaHelper::linea_de_destinatarios($nombres),
                'Filtro: ' . self::descripcion_del_filtro($monto_minimo, $evaluacion['dias'], $client_ids),
                'Cómo sale: ' . self::descripcion_de_canales($por_canal),
            ],
            $grupos,
            'El texto que sale (el de ' . $primero->name . ')',
            $texto_que_sale,
            $aclaracion
        );

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_RECORDATORIOS_COBRO,
            self::clave($ids_que_reciben, $evaluacion['dias']),
            [
                // 🔴 Los clientes que el dueño VIO: al confirmar se manda a estos y a nadie más.
                'client_ids'   => $ids_que_reciben,
                'dias'         => (int) $evaluacion['dias'],
                'monto_minimo' => $monto_minimo,
                'nombrados'    => count($client_ids) > 0,
                'bloque'       => $bloque,
            ],
            [
                'titulo'          => 'Recordatorio de cobro por WhatsApp a ' . count($nombres) . ' ' . (count($nombres) === 1 ? 'cliente' : 'clientes'),
                'renglones'       => $renglones,
                'aviso'           => $aclaracion,
                'texto_confirmar' => DestinatariosIaHelper::TEXTO_CONFIRMAR,
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        $resumen = 'Recordatorio de cobro por WhatsApp a ' . count($nombres) . ' ' . (count($nombres) === 1 ? 'cliente' : 'clientes');

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, [
            'para_mostrar' => DestinatariosIaHelper::para_mostrar($nombres, $grupos, $texto_que_sale, $aclaracion),
            // Reemplaza a la nota de siempre: ver el docblock de DestinatariosIaHelper::COMO_MOSTRARLO.
            'nota'         => DestinatariosIaHelper::COMO_MOSTRARLO,
        ]);
    }

    /**
     * Encola los recordatorios de los clientes de la tarjeta, revalidados.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, encolados, no_salieron}
     *
     * @throws AccionIaException
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        $corte = self::verificar_acceso($contexto);

        if (!is_null($corte)) {

            throw new AccionIaException(422, $corte);
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $ids = self::ids(isset($datos['client_ids']) ? $datos['client_ids'] : []);

        /*
         * 🔴 M1 del chequeo: sin ids no se manda a NADIE. evaluar() con la lista vacía entiende "todos
         * los del filtro", y eso sería mandarle a gente que el dueño no vio.
         */
        if (!count($ids)) {

            throw new AccionIaException(422, 'Esta tarjeta no tiene a quién mandarle: pedímela de nuevo.');
        }

        $dias = isset($datos['dias']) ? (int) $datos['dias'] : 0;

        $monto_minimo = isset($datos['monto_minimo']) ? self::numero_positivo($datos['monto_minimo']) : null;

        /*
         * 🔴 SE REVALIDA EL FILTRO CON LOS DATOS DE HOY. Entre la tarjeta y el sí pueden pasar horas, y
         * el que pagó en el medio no puede recibir un reclamo. Se vuelven a evaluar SOLO los ids de la
         * tarjeta (nunca se suma a nadie que el dueño no vio), con el mismo filtro de deuda.
         */
        $evaluacion = self::evaluar($contexto, $monto_minimo, $dias > 0 ? $dias : null, $ids);

        $clientes = [];

        foreach ($evaluacion['clientes'] as $fila) {
            $clientes[] = $fila['client'];
        }

        $no_salieron = [];

        foreach ($evaluacion['salteados'] as $salteado) {
            $no_salieron[] = ['cliente' => $salteado['nombre'], 'motivo' => $salteado['mensaje']];
        }

        $lote = (new RecordatorioCobroSenderService())->encolar_lote(
            $contexto->owner_id,
            $clientes,
            $evaluacion['dias'],
            is_null($contexto->persona) ? null : (int) $contexto->persona->id
        );

        foreach ($lote['salteados'] as $salteado) {
            $no_salieron[] = ['cliente' => (string) $salteado['client_name'], 'motivo' => (string) $salteado['mensaje']];
        }

        $encolados = (int) $lote['encolados'];

        if ($encolados === 0) {

            throw new AccionIaException(422, 'No salió ningún recordatorio: ' . self::motivos_de_no_salieron($no_salieron));
        }

        $texto = 'Recordatorio de cobro en camino a ' . $encolados . ' ' . ($encolados === 1 ? 'cliente' : 'clientes')
            . ': sale por WhatsApp en segundo plano, uno por cliente.';

        return [
            'texto'       => $texto,
            'encolados'   => $encolados,
            'no_salieron' => $no_salieron,
            'lote_uuid'   => (string) $lote['lote_uuid'],
        ];
    }

    /**
     * Identidad del envío para el reemplazo: los mismos clientes con el mismo umbral. Una corrección
     * del filtro arma otra lista y otra clave; si el modelo la quiere reemplazar, manda `reemplaza_a`.
     *
     * @param  array<int, int>  $ids
     * @param  int  $dias
     * @return string
     */
    public static function clave(array $ids, $dias): string
    {
        return 'recordatorios_cobro:' . (int) $dias . ':' . md5(implode(',', $ids));
    }

    /**
     * Quién puede mandar recordatorios: el comercio con el módulo de WhatsApp, y la persona con el
     * permiso (o dueña o administradora), en ese orden. Es `RecordatorioCobroController::check_permiso()`
     * más la extensión del grupo de rutas.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return string|null  El motivo, o null si puede.
     */
    protected static function verificar_acceso(ContextoDeCargaIa $contexto)
    {
        if (!PermisosIaHelper::tiene_extencion($contexto->owner, self::EXTENSION)) {

            return PermisosIaHelper::mensaje_sin_extencion('WhatsApp') . ' Sin él no se pueden mandar recordatorios de cobro.';
        }

        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return 'No tenés permiso para mandar recordatorios de cobro desde tu usuario.';
        }

        return null;
    }

    /**
     * Los clientes del filtro (o los nombrados, si vinieron) que deben, con su modelo; y los nombrados
     * que no cumplen, como salteados.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  float|null  $monto_minimo
     * @param  int|null  $dias
     * @param  array<int, int>  $solo_estos  Vacío = todos los del filtro.
     * @return array{dias: int, clientes: array<int, array{client: Client, deuda: float}>, salteados: array}
     */
    protected static function evaluar(ContextoDeCargaIa $contexto, $monto_minimo, $dias, array $solo_estos)
    {
        $deudores = VentasSinCobrarIaHelper::clientes_deudores($contexto->owner_id, $dias, $contexto->persona, $monto_minimo);

        $por_id = [];

        foreach ($deudores['clientes'] as $deudor) {
            $por_id[(int) $deudor['cliente_id']] = $deudor;
        }

        $salteados = [];

        if (count($solo_estos)) {

            /*
             * La lista explícita ("a Pérez y a Gómez"): solo clientes de ESTE negocio (los de otro, ni
             * se nombran: no existen para este dueño), y de esos, solo los que deben con el filtro.
             */
            $modelos = Client::where('user_id', $contexto->owner_id)->whereIn('id', $solo_estos)->get()->keyBy('id');

            $ids = [];

            foreach ($solo_estos as $id) {

                if (!isset($modelos[$id])) {
                    continue;
                }

                if (!isset($por_id[$id])) {
                    $salteados[] = [
                        'nombre'  => (string) $modelos[$id]->name,
                        'motivo'  => self::MOTIVO_NO_CUMPLE,
                        'mensaje' => 'No tiene ventas sin cobrar con ese filtro.',
                    ];
                    continue;
                }

                $ids[] = $id;
            }

        } else {

            $ids = array_keys($por_id);
        }

        $modelos = count($ids) ? Client::where('user_id', $contexto->owner_id)->whereIn('id', $ids)->get()->keyBy('id') : collect();

        $clientes = [];

        // En el orden del filtro: de mayor a menor deuda total, que es como el dueño los vio listados.
        foreach ($deudores['clientes'] as $deudor) {

            $id = (int) $deudor['cliente_id'];

            if (!in_array($id, $ids, true) || !isset($modelos[$id])) {
                continue;
            }

            $clientes[] = ['client' => $modelos[$id], 'deuda' => (float) $deudor['deuda_total_en_pesos']];
        }

        return ['dias' => (int) $deudores['dias'], 'clientes' => $clientes, 'salteados' => $salteados];
    }

    /**
     * Las ventas sin cobrar de UN cliente, con el recorte de la persona: lo mismo que va a
     * recalcular el job antes de mandar, para que el texto del primero sea el que sale.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  int  $client_id
     * @param  int  $dias
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected static function ventas_del_cliente(ContextoDeCargaIa $contexto, $client_id, $dias)
    {
        // El mismo recorte que usa el filtro (y que el job aplica con la misma persona).
        $recorte = VentasSinCobrarIaHelper::employee_id_del_recorte($contexto->persona, $contexto->owner_id);

        return VentasSinCobrarHelper::query_de_ventas($contexto->owner_id, $recorte, (int) $dias)
            ->where('client_id', (int) $client_id)
            ->with('current_acount')
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * "Deuda total de $ 200.000 o más y alguna venta impaga de 20 días o más".
     *
     * @param  float|null  $monto_minimo
     * @param  int  $dias
     * @param  array  $client_ids
     * @return string
     */
    protected static function descripcion_del_filtro($monto_minimo, $dias, array $client_ids)
    {
        $partes = [];

        if (count($client_ids)) {
            $partes[] = 'los clientes que nombraste';
        }

        if (!is_null($monto_minimo)) {
            $partes[] = 'deuda total de ' . FormatoIaHelper::monto($monto_minimo) . ' o más';
        }

        if ((int) $dias > 0) {
            $partes[] = 'alguna venta impaga de ' . (int) $dias . ' días o más';
        }

        if (!count($partes)) {
            return 'Todos los clientes con ventas sin cobrar';
        }

        return ucfirst(implode(', ', $partes));
    }

    /**
     * "3 como mensaje y 9 como plantilla de WhatsApp".
     *
     * @param  array{texto_libre: int, plantilla: int}  $por_canal
     * @return string
     */
    protected static function descripcion_de_canales(array $por_canal)
    {
        $partes = [];

        if ($por_canal['texto_libre'] > 0) {
            $partes[] = $por_canal['texto_libre'] . ' como mensaje (escribieron en las últimas 24 horas)';
        }

        if ($por_canal['plantilla'] > 0) {
            $partes[] = $por_canal['plantilla'] . ' como plantilla de WhatsApp con el resumen de cuenta en PDF';
        }

        return implode(' y ', $partes);
    }

    /**
     * Los motivos agrupados, en una línea, para el error de "no hay a quién".
     *
     * @param  array  $grupos
     * @return string
     */
    protected static function motivos_en_una_linea(array $grupos)
    {
        $partes = [];

        foreach ($grupos as $grupo) {
            $partes[] = rtrim($grupo['mensaje'], '.') . ' (' . $grupo['clientes'] . ')';
        }

        return implode('; ', $partes) . '.';
    }

    /**
     * Los que no salieron, en una línea.
     *
     * @param  array<int, array{cliente: string, motivo: string}>  $no_salieron
     * @return string
     */
    protected static function motivos_de_no_salieron(array $no_salieron)
    {
        $agrupados = [];

        foreach ($no_salieron as $fila) {
            $agrupados[] = ['nombre' => $fila['cliente'], 'motivo' => $fila['motivo'], 'mensaje' => $fila['motivo']];
        }

        return self::motivos_en_una_linea(DestinatariosIaHelper::agrupar_por_motivo($agrupados));
    }

    /**
     * Una lista de ids enteros positivos, sin repetidos, en el orden en que vinieron.
     *
     * @param  mixed  $valor
     * @return array<int, int>
     */
    protected static function ids($valor)
    {
        if (!is_array($valor)) {
            return [];
        }

        $ids = [];

        foreach ($valor as $id) {
            if (is_numeric($id) && (int) $id > 0 && !in_array((int) $id, $ids, true)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Un número positivo, o null.
     *
     * @param  mixed  $valor
     * @return float|null
     */
    protected static function numero_positivo($valor)
    {
        return is_numeric($valor) && (float) $valor > 0 ? (float) $valor : null;
    }
}
