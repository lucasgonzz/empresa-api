<?php

namespace App\Services;

use App\Models\Address;
use App\Models\WhatsappBotConfig;
use Carbon\Carbon;
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
 *     sin dirección cargada es exactamente el contexto donde un modelo se inventa una calle. Y si el dato
 *     que falta hace imposible el objetivo (la tienda online sin su dirección), la capa no se agrega.
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
            // Solo "que pase por el local" usa las sucursales y el horario; "tienda" usa solo la URL, y
            // "pedido", nada. Así una respuesta del agente no paga una consulta a `addresses` que no usa.
            if ($objetivo === WhatsappBotConfig::OBJETIVO_VISITAR_LOCAL) {
                $contexto = $this->contexto((int) $config->user_id, $owner_user, $horarios);
            } else {
                $contexto = ['tienda_online' => $this->tienda_online($owner_user), 'sucursales' => [], 'hay_horario' => false];
            }

            // 🔴 "Que compre en la tienda online" SIN la dirección de la tienda (`users.online`) es un
            // objetivo que no se puede cumplir, y lo más probable es que el negocio ni tenga tienda: la
            // capa NO se agrega y el agente queda como sin objetivo. Antes se agregaba con un "no la
            // menciones por tu cuenta", pero convivía con el "orientalo hacia este objetivo" de
            // COMO_LLEVARLO y, medido contra DeepSeek, igual ofrecía la tienda y prometía el link. La
            // pantalla se lo avisa al dueño en el recuadro "Lo que va a usar el agente".
            if ($objetivo === WhatsappBotConfig::OBJETIVO_COMPRAR_ONLINE && is_null($contexto['tienda_online'])) {
                return '';
            }

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
        $hay_horario = false;
        try {
            $hay_horario = $horarios->hay_dato();
        } catch (\Throwable $e) {
            $hay_horario = false;
        }

        return [
            'tienda_online' => $this->tienda_online($owner_user),
            'sucursales'    => $this->sucursales($owner_id),
            'hay_horario'   => $hay_horario,
        ];
    }

    /**
     * La dirección de la tienda online del negocio (`users.online`, la misma que arma el Link de cada
     * producto en el catálogo), saneada, o null si no está cargada.
     *
     * @param \App\Models\User|null $owner_user Dueño del negocio, o null.
     *
     * @return string|null
     */
    private function tienda_online($owner_user): ?string
    {
        if (is_null($owner_user)) {
            return null;
        }

        $tienda = $this->texto_seguro((string) ($owner_user->online ?? ''), self::LARGO_URL);

        return $tienda === '' ? null : $tienda;
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
                //
                // "Salvo que esté escrita en estas instrucciones": el dueño puede haber escrito la dirección
                // en las indicaciones, la personalidad o las habilidades (chequeo independiente, 16/10). Sin
                // la salvedad, esta frase se la prohibía aunque estuviera ahí arriba.
                $renglones[] = 'El sistema no tiene cargada la dirección del local. Salvo que esté escrita en estas'
                    .' instrucciones, podés invitarlo a pasar, pero si te pregunta dónde están, decile que se la'
                    .' confirmás. Nunca inventes una dirección.';
            }

            if ($contexto['hay_horario']) {
                $renglones[] = 'Para decirle cuándo pasar, usá el horario de atención de arriba.';
            } else {
                // Misma salvedad: el placeholder de la pantalla sugiere justamente "los sábados abrimos
                // hasta las 13" como indicación.
                //
                // Y el día de hoy: sin la capa de horario el modelo no sabe qué día es, y medido contra
                // DeepSeek (16/10/2026, un viernes) contestó "hoy, sábado, abrimos hasta las 13" con el
                // horario que el dueño escribió en las indicaciones. La capa de horario ya trae su "Hoy es…";
                // acá se agrega solo cuando esa capa no está. Mismo nombre de día que usa esa capa.
                $hoy = BusinessHoursReader::LABELS_POR_DOW[(int) Carbon::now(config('app.timezone'))->dayOfWeek];

                $renglones[] = 'El sistema no tiene cargado el horario de atención. Salvo que esté escrito en estas'
                    .' instrucciones, no digas a qué hora abren ni a qué hora cierran. Hoy es '
                    .$this->en_minuscula($hoy).'.';
            }

            return $renglones;
        }

        if ($objetivo === WhatsappBotConfig::OBJETIVO_COMPRAR_ONLINE) {
            // Sin `tienda_online` no se llega acá: capa_del_objetivo() corta antes (objetivo imposible).
            //
            // 🔴 La frase del producto SIN Link no sobra: el Link falta justamente cuando el artículo no está
            // publicado en la tienda (`articles.online = 0`) o no tiene slug (WhatsappBotAiService::article_url()),
            // y sin ella el agente lo mandaba a comprar a la tienda algo que ahí no está.
            //
            // Y la última: la capa de horario le dice que "puede recordarle hasta qué hora están hoy", y con
            // este objetivo eso terminaba en "o pasá por el local" en casi cada respuesta (diluía el objetivo).
            return [
                'Objetivo de la conversación, que te pidió el negocio: que el cliente compre en la tienda online del'
                    .' negocio.',
                // La URL va al final del renglón y sin punto pegado: un "https://x.com.ar." invita a copiar
                // el punto adentro del link.
                'La dirección de la tienda online del negocio es: '.$contexto['tienda_online'],
                // 🔴 "Nunca armes un link": medido contra DeepSeek (16/10/2026), con la dirección de la tienda a
                // la vista y el Link de otro producto en el catálogo, el modelo ARMÓ el link de un producto no
                // publicado juntando la tienda con el nombre (un 404 en la tienda del cliente).
                'Cuando le interese un producto, pasale el Link de ese producto que viene en el catálogo, tal cual.'
                    .' Si un producto no trae Link, no está publicado en la tienda: no tiene link, no le digas que lo'
                    .' compre ahí y resolvelo por acá. Nunca armes un link de producto juntando la dirección de la'
                    .' tienda con el nombre o el código del producto: el único link de un producto es el que trae'
                    .' su renglón del catálogo.',
                'Priorizá la tienda: no lo invites a pasar por el local salvo que el cliente lo pregunte.',
            ];
        }

        // Pedido por WhatsApp: no necesita datos externos. El agente arma el pedido; lo confirma el negocio.
        // 🔴 No dice "cómo lo retira o lo recibe": medido, el modelo lo repetía tal cual y le insinuaba al
        // cliente un envío que el negocio puede no hacer. El pedido queda escrito en la conversación (no se
        // carga solo como venta): la pantalla se lo aclara al dueño.
        return [
            'Objetivo de la conversación, que te pidió el negocio: que el cliente te deje el pedido por este chat.',
            'Cuando quiera comprar, armá el pedido con él: qué productos y cuántos de cada uno, y a nombre de quién.'
                .' Repasale el pedido en un mensaje y decile que se lo confirmamos con el total y los pasos a seguir.'
                .' No lo des por confirmado vos, ni acuerdes el pago, el envío o el retiro.',
            'Priorizá el pedido por acá: no lo invites a pasar por el local salvo que el cliente lo pregunte.',
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
     * Pasa el nombre de un día a minúscula respetando las tildes ('Miércoles' => 'miércoles'), igual que
     * `BusinessHoursPromptBuilder::en_minuscula()`: `strtolower()` a secas rompe los acentos en UTF-8.
     *
     * @param string $texto Nombre del día.
     *
     * @return string
     */
    private function en_minuscula(string $texto): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($texto, 'UTF-8');
        }

        return strtolower($texto);
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
