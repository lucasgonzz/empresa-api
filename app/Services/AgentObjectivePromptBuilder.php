<?php

namespace App\Services;

use App\Models\Address;
use App\Models\WhatsappBotConfig;
use Illuminate\Support\Facades\Log;

/**
 * Arma la capa del OBJETIVO DE LA CONVERSACIÓN que se le suma al agente de WhatsApp
 * (`WhatsappBotAiService::build_system_prompt()`; misión objetivo-agente-whatsapp, 16/10/2026).
 *
 * El dueño elige en Configuración → Agente qué tiene que buscar el agente además de asesorar: que el
 * cliente pase por el local, que compre en la tienda online, que deje el pedido por el chat, u otro
 * objetivo que escribe él (`whatsapp_bot_configs.agent_objective` + `agent_objective_detail`).
 *
 * 🔴 CUATRO REGLAS QUE GOBIERNAN ESTE TEXTO:
 *
 *  1. **Sin objetivo, la capa NO se agrega: devuelve ''.** Mismo criterio que `agent_skills` y que
 *     `BusinessHoursPromptBuilder`. Vale también para un valor desconocido en la base y para "Otro
 *     objetivo" sin texto. Es la garantía de que el system prompt de un negocio que no eligió objetivo
 *     queda byte por byte igual al de antes de esta capa.
 *
 *  2. **Los datos salen del sistema, nunca del modelo.** El link de la tienda es `users.online`; la
 *     dirección, las sucursales con domicilio cargado (`addresses` del dueño, sin las de compradores de
 *     la tienda); el horario, la capa de horario que ya existe. Si falta un dato, el texto se lo dice al
 *     modelo con todas las letras y le prohíbe inventarlo: un objetivo que empuja a "pasá por el local"
 *     sin dirección cargada es exactamente el contexto donde un modelo se inventa una calle.
 *
 *  3. **El objetivo nunca va por delante del cliente.** Primero se resuelve lo que preguntó; el
 *     objetivo se trae cuando viene al caso, sin repetirlo en cada mensaje ni insistir. Las reglas fijas
 *     siguen ganando, y el texto lo repite porque esta capa va DESPUÉS de ellas (al final del prompt).
 *
 *  4. **Todo lo que viene de la base se sanea antes de entrar al prompt** (`texto_seguro()`): sin
 *     saltos de línea y con largo acotado, mismo motivo que en la capa de horario.
 *
 * ⚠️ Texto plano, sin markdown ni listas con guiones: `WhatsappBotAiService::FIXED_RULES` se lo prohíbe
 * al modelo en sus respuestas, y un prompt con viñetas le estaría mostrando lo contrario.
 *
 * Además de la capa, `contexto()` devuelve los mismos datos para que la pantalla de configuración le
 * muestre al dueño exactamente qué va a usar el agente (y qué le falta cargar).
 */
final class AgentObjectivePromptBuilder
{
    /**
     * Tope de sucursales que entran en la capa. Un comercio típico tiene una o dos; el tope existe
     * para que una cuenta con muchas no infle el prompt que se paga en cada respuesta.
     *
     * @var int
     */
    private const MAXIMO_SUCURSALES = 10;

    /**
     * Largo máximo de cada dato de una sucursal (nombre, domicilio, ciudad, provincia) en el prompt.
     *
     * @var int
     */
    private const LARGO_DATO_SUCURSAL = 80;

    /**
     * Largo máximo de la dirección de la tienda online en el prompt.
     *
     * @var int
     */
    private const LARGO_URL = 200;

    /**
     * Largo máximo del detalle que escribe el dueño. Es el mismo tope que valida el endpoint; acá se
     * vuelve a cortar porque la columna es `text` y se puede escribir por otro camino.
     *
     * @var int
     */
    private const LARGO_DETALLE = 1000;

    /**
     * Cómo llevar el objetivo durante la charla. Va siempre que hay objetivo, después de lo específico.
     */
    private const COMO_LLEVARLO = 'Cómo llevarlo: primero resolvé siempre lo que el cliente pregunta. Cuando venga al caso,'
        .' por ejemplo cuando ya encontró lo que buscaba, cuando pregunta cómo comprar o cuando la charla se está'
        .' cerrando, orientalo hacia este objetivo con una frase natural. Si ya se lo propusiste en un mensaje'
        .' anterior de esta conversación, no lo vuelvas a proponer hasta que el cliente muestre que quiere comprar'
        .' o te lo pregunte. No insistas si prefiere otra cosa y nunca lo pongas por delante de lo que pidió.';

    /**
     * Cierre de la capa: el objetivo no habilita a inventar. Lo dice aunque las reglas fijas ya lo digan,
     * porque esta capa va después de ellas y es la que empuja al modelo a "cerrar" la venta.
     */
    private const SIN_INVENTAR = 'Para cumplir el objetivo usá solo los datos que están en estas instrucciones o en el'
        .' catálogo: no inventes direcciones, links, horarios, envíos, formas de pago, reservas ni descuentos. Las'
        .' reglas fijas de arriba siguen ganando.';

    /**
     * La capa del objetivo lista para sumar al system prompt.
     *
     * @param WhatsappBotConfig     $config     Configuración del bot (de acá salen el objetivo y el detalle).
     * @param \App\Models\User|null $owner_user Dueño del negocio (para `online`), o null si no se resolvió.
     * @param BusinessHoursReader   $horarios   Lector del horario, el mismo que arma la capa de horario.
     *
     * @return string Vacío cuando no hay objetivo: en ese caso la capa NO se agrega.
     */
    public function capa_del_objetivo(WhatsappBotConfig $config, $owner_user, BusinessHoursReader $horarios): string
    {
        $objetivo = self::objetivo_vigente($config);

        if (is_null($objetivo)) {
            return '';
        }

        $detalle = $this->detalle($config);

        if ($objetivo === WhatsappBotConfig::OBJETIVO_PERSONALIZADO) {
            // En "Otro objetivo" el detalle ES el objetivo: no hay frase fija ni datos del sistema.
            $renglones = ['Objetivo de la conversación, que te pidió el negocio: '.$detalle];
        } else {
            $contexto  = $this->contexto((int) $config->user_id, $owner_user, $horarios);
            $renglones = $this->renglones_del_objetivo($objetivo, $contexto);

            if ($detalle !== '') {
                $renglones[] = 'Indicaciones del negocio para este objetivo: '.$detalle;
            }
        }

        $renglones[] = self::COMO_LLEVARLO;
        $renglones[] = self::SIN_INVENTAR;

        return implode("\n", $renglones);
    }

    /**
     * El objetivo que efectivamente aplica, o null si no hay ninguno.
     *
     * Null cuando la columna está vacía, cuando trae un valor que no está en la lista cerrada (un dato
     * viejo o escrito a mano no rompe nada: se comporta como sin objetivo) y cuando es "Otro objetivo"
     * sin texto (no hay nada que perseguir).
     *
     * @param WhatsappBotConfig $config
     *
     * @return string|null
     */
    public static function objetivo_vigente(WhatsappBotConfig $config): ?string
    {
        $objetivo = trim((string) $config->agent_objective);

        if ($objetivo === '' || ! in_array($objetivo, WhatsappBotConfig::OBJETIVOS, true)) {
            return null;
        }

        if ($objetivo === WhatsappBotConfig::OBJETIVO_PERSONALIZADO && trim((string) $config->agent_objective_detail) === '') {
            return null;
        }

        return $objetivo;
    }

    /**
     * Los datos reales que usa el agente para los objetivos que necesitan datos del negocio. Es la misma
     * fuente que la capa del prompt, y la expone el endpoint de configuración para que la pantalla le
     * muestre al dueño qué va a usar el agente.
     *
     * 🔴 NUNCA LANZA: se llama desde el armado del prompt, donde una excepción no se ve como error sino
     * como "el agente dejó de contestar". Si una lectura falla, ese dato vuelve vacío (y el agente lo
     * trata como dato faltante, que es el lado seguro).
     *
     * @param int                   $owner_id   Dueño del negocio (de él son las sucursales).
     * @param \App\Models\User|null $owner_user Dueño del negocio (para `online`), o null.
     * @param BusinessHoursReader   $horarios   Lector del horario.
     *
     * @return array{tienda_online: string|null, sucursales: array<int, string>, hay_horario: bool}
     */
    public function contexto(int $owner_id, $owner_user, BusinessHoursReader $horarios): array
    {
        $tienda = '';
        if (! is_null($owner_user)) {
            $tienda = $this->texto_seguro((string) ($owner_user->online ?? ''), self::LARGO_URL);
        }

        $hay_horario = false;
        try {
            $hay_horario = $horarios->hay_dato();
        } catch (\Throwable $e) {
            $hay_horario = false;
        }

        return [
            'tienda_online' => $tienda === '' ? null : $tienda,
            'sucursales'    => $this->sucursales($owner_id),
            'hay_horario'   => $hay_horario,
        ];
    }

    /**
     * Los renglones propios de cada objetivo con datos del sistema: qué buscar y con qué datos.
     *
     * @param string $objetivo Objetivo vigente (nunca "Otro objetivo": ese no pasa por acá).
     * @param array  $contexto Datos reales (ver `contexto()`).
     *
     * @return array<int, string>
     */
    private function renglones_del_objetivo(string $objetivo, array $contexto): array
    {
        if ($objetivo === WhatsappBotConfig::OBJETIVO_VISITAR_LOCAL) {
            // 🔴 La segunda oración no sobra: medido contra DeepSeek (16/10/2026), sin ella el empujón a
            // "pasá por el local" terminaba en "te la aparto" / "te la dejo lista" en un negocio que nunca
            // dijo que aparta nada, aunque la regla fija de servicios ya lo prohíbe.
            $renglones = ['Objetivo de la conversación, que te pidió el negocio: que el cliente pase por el local.'
                .' Invitalo a acercarse a ver o a retirar lo que busca. Invitarlo no es prometerle nada: no le'
                .' ofrezcas apartarle, reservarle ni dejarle listo un producto si eso no está escrito en estas'
                .' instrucciones.'];

            if (count($contexto['sucursales']) > 0) {
                $renglones[] = 'Dónde está el negocio, tal como lo cargó (nombre de la sucursal y después su domicilio,'
                    .' ciudad y provincia). Pasale la dirección con estos datos, sin agregarles nada:';

                foreach ($contexto['sucursales'] as $sucursal) {
                    $renglones[] = $sucursal.'.';
                }
            } else {
                // 🔴 El objetivo empuja a "pasá por el local": sin esta frase, la pregunta "¿dónde están?"
                // es el lugar exacto donde un modelo completa una calle de su cosecha.
                $renglones[] = 'El negocio no tiene cargada la dirección del local: podés invitarlo a pasar, pero si'
                    .' te pregunta dónde están, decile que se la confirmás. Nunca inventes una dirección.';
            }

            if ($contexto['hay_horario']) {
                $renglones[] = 'Para decirle cuándo pasar, usá el horario de atención de arriba.';
            } else {
                $renglones[] = 'No tenés el horario de atención: no digas a qué hora abren ni a qué hora cierran.';
            }

            return $renglones;
        }

        if ($objetivo === WhatsappBotConfig::OBJETIVO_COMPRAR_ONLINE) {
            $renglones = ['Objetivo de la conversación, que te pidió el negocio: que el cliente compre en la tienda'
                .' online del negocio.'];

            if (! is_null($contexto['tienda_online'])) {
                $renglones[] = 'La tienda online del negocio es '.$contexto['tienda_online'].'. Cuando le interese un'
                    .' producto, pasale el Link de ese producto si viene en el catálogo; si no, la dirección de la'
                    .' tienda. Los dos tal cual, sin modificarlos.';
            } else {
                // Sin `users.online` tampoco hay Link por producto (WhatsappBotAiService::article_url() lo
                // necesita): no hay ningún link verdadero para pasar. Medido contra DeepSeek: con solo "no la
                // inventes", igual ofrecía la tienda en cada respuesta y prometía "el link te lo confirmo".
                // Sin el dato el objetivo no se puede cumplir, así que no se lo empuja: solo se contesta si
                // el cliente pregunta.
                $renglones[] = 'Pero el negocio no tiene cargada la dirección de su tienda online, así que este objetivo'
                    .' no lo podés llevar adelante: no menciones la tienda por tu cuenta, no la inventes ni pases ningún'
                    .' link. Solo si el cliente pregunta por comprar online, decile que la dirección se la confirmás.';
            }

            return $renglones;
        }

        // Pedido por WhatsApp: no necesita datos externos. El agente arma el pedido; lo confirma el negocio.
        return [
            'Objetivo de la conversación, que te pidió el negocio: que el cliente te deje el pedido por este chat.',
            'Cuando quiera comprar, armá el pedido con él: qué productos y cuántos de cada uno, y a nombre de quién.'
                .' Repasale el pedido en un mensaje y decile que se lo confirmamos, con el total y cómo lo retira o'
                .' lo recibe. No lo des por confirmado vos, ni acuerdes el pago, el envío o el retiro.',
        ];
    }

    /**
     * El detalle que escribió el dueño, recortado. Es texto del dueño para su propio agente, igual que la
     * personalidad y las habilidades: se respetan sus renglones y solo se acota el largo.
     *
     * @param WhatsappBotConfig $config
     *
     * @return string
     */
    private function detalle(WhatsappBotConfig $config): string
    {
        $detalle = trim(str_replace("\r", '', (string) $config->agent_objective_detail));

        if (function_exists('mb_substr')) {
            return trim(mb_substr($detalle, 0, self::LARGO_DETALLE, 'UTF-8'));
        }

        return trim(substr($detalle, 0, self::LARGO_DETALLE));
    }

    /**
     * Las sucursales del dueño con domicilio cargado, como texto ("Casa central: Av. Almafuerte 850,
     * Paraná, Entre Ríos").
     *
     * Solo cuentan las que tienen DOMICILIO (`street_number`, rotulado "Domicilio" en la pantalla de
     * sucursales): una sucursal con solo el nombre ("Tucumán", "Depósito") no es una dirección, y pasarla
     * como tal es inventar. Quedan afuera los domicilios de compradores de la tienda, que viven en la
     * misma tabla con `buyer_id`.
     *
     * @param int $owner_id
     *
     * @return array<int, string>
     */
    private function sucursales(int $owner_id): array
    {
        if ($owner_id <= 0) {
            return [];
        }

        try {
            $filas = Address::where('user_id', $owner_id)
                ->whereNull('buyer_id')
                ->whereNotNull('street_number')
                ->where('street_number', '!=', '')
                ->orderBy('id')
                ->limit(self::MAXIMO_SUCURSALES)
                ->get(['street', 'street_number', 'city', 'province']);
        } catch (\Throwable $e) {
            Log::warning('AgentObjectivePromptBuilder: no se pudieron leer las sucursales.', [
                'user_id' => $owner_id,
                'error'   => $e->getMessage(),
            ]);

            return [];
        }

        $sucursales = [];

        foreach ($filas as $fila) {
            $domicilio = $this->texto_seguro((string) $fila->street_number, self::LARGO_DATO_SUCURSAL);

            if ($domicilio === '') {
                continue;
            }

            $partes = [$domicilio];

            foreach (['city', 'province'] as $campo) {
                $valor = $this->texto_seguro((string) $fila->{$campo}, self::LARGO_DATO_SUCURSAL);

                if ($valor !== '') {
                    $partes[] = $valor;
                }
            }

            $nombre = $this->texto_seguro((string) $fila->street, self::LARGO_DATO_SUCURSAL);

            $sucursales[] = ($nombre === '' ? '' : $nombre.': ').implode(', ', $partes);
        }

        return $sucursales;
    }

    /**
     * Normaliza un texto que viene de la base ANTES de interpolarlo en el system prompt: todo carácter de
     * control (saltos de línea incluidos) pasa a espacio, los espacios se colapsan y se corta al largo.
     *
     * Mismo criterio y mismo motivo que `BusinessHoursPromptBuilder::texto_seguro()`: un dato con saltos
     * de línea escribiría renglones enteros que el modelo lee como instrucciones, indistinguibles de las
     * capas legítimas del prompt.
     *
     * @param string $valor Texto crudo.
     * @param int    $largo Largo máximo en caracteres.
     *
     * @return string
     */
    private function texto_seguro(string $valor, int $largo): string
    {
        // Con /u los caracteres de control van como code points (ver el comentario de la capa de horario).
        $limpio = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', ' ', $valor);

        if ($limpio === null) {
            // Texto que no es UTF-8 válido: no se arriesga nada.
            return '';
        }

        $limpio = trim(preg_replace('/\s+/u', ' ', $limpio));

        if (function_exists('mb_substr')) {
            return trim(mb_substr($limpio, 0, $largo, 'UTF-8'));
        }

        return trim(substr($limpio, 0, $largo));
    }
}
