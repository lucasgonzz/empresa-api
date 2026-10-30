<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción controlada del chat con el asistente de IA (misión agente-ia-mano-derecha). Copia
 * estructural de `RecordatorioCobroException`, y por el mismo motivo: lo que la distingue de un
 * `\Throwable` cualquiera es tener un MOTIVO estable que el llamador lee.
 *
 * 🔴 EL PROBLEMA QUE VIENE A RESOLVER. Cuatro modos de falla bien distintos —el presupuesto de
 * tiempo agotado, el techo de iteraciones sin respuesta, un 529 de Anthropic y cualquier otra
 * falla técnica— terminaban los cuatro con el MISMO texto rojo en la pantalla del dueño
 * (`ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE`), porque el catch genérico del job pisaba
 * los mensajes finos que el servicio sí producía. "Probá de nuevo en unos segundos" es un consejo
 * correcto para el 529 y es un consejo INÚTIL para una consulta que se hizo larga: probar de nuevo
 * lo mismo va a tardar lo mismo.
 *
 * 🔴 DOS TEXTOS, NO UNO, Y NO SE MEZCLAN:
 *
 * - `getMessage()` es el DETALLE TÉCNICO. Va al log y a la columna `ai_messages.error_mensaje`.
 *   Puede traer el body crudo de la respuesta de Anthropic.
 * - `mensaje_para_la_persona()` es lo ÚNICO que puede llegar a la pantalla, y sale de un mapa fijo
 *   de acá adentro: nunca se arma con datos de la respuesta. Así el body crudo no tiene por dónde
 *   filtrarse aunque alguien agregue un motivo nuevo sin pensarlo.
 *
 * Extiende `RuntimeException` a propósito: `AsistenteIaService::responder()` ya declaraba
 * `@throws \RuntimeException` y todo lo que la atrapa hoy la sigue atrapando igual.
 *
 * PHP 7.4: sin enum, sin union types y sin promoción de propiedades.
 */
class AsistenteIaException extends RuntimeException
{
    /**
     * Se acabó el tiempo: presupuesto del loop agotado o techo de iteraciones sin texto final.
     *
     * Desde la misión agente-memoria-y-sin-techo (30/10/2026) casi nunca llega a la persona: cuando se
     * terminan las vueltas o el tiempo, el loop hace una LLAMADA DE CIERRE que cuenta lo hecho y lo que
     * falta (AsistenteIaService::responder_con_el_modelo()), y si esa llamada falla, vuelve vacía o no
     * tiene ventana, el turno igual cierra `listo` con lo hecho armado sin el modelo
     * (AsistenteIaService::respuesta_sin_terminar(), ronda de arreglos R1). Queda solo para un turno que
     * se quedó sin tiempo o sin vueltas SIN haber hecho ninguna ronda de herramientas: nada hecho que
     * contar.
     */
    const MOTIVO_TIEMPO_AGOTADO = 'tiempo_agotado';

    /** Anthropic contestó un error transitorio (529 / overloaded_error / api_error). */
    const MOTIVO_SOBRECARGADO = 'sobrecargado';

    /** El loop cerró sin texto sin haber agotado ni el tiempo ni las vueltas. */
    const MOTIVO_SIN_RESPUESTA = 'sin_respuesta';

    /** Cualquier otra falla de la llamada: el llamador usa su texto genérico. */
    const MOTIVO_FALLA_TECNICA = 'falla_tecnica';

    /**
     * El texto de cada motivo tal como lo lee el dueño. Está acá y no en el job porque es el mismo
     * lugar donde se decide el motivo: partidos, uno se corrige sin el otro.
     *
     * MOTIVO_FALLA_TECNICA NO figura a propósito — no tiene nada que decirle a la persona que ella
     * pueda usar, así que cae al genérico del llamador.
     *
     * 🔴 EL DE TIEMPO AGOTADO YA NO PIDE «ALGO MÁS ACOTADO» (misión agente-memoria-y-sin-techo,
     * 30/10/2026; cambio de contrato a propósito). Era el texto que leyó Pablo (Ferre Total) por un pedido
     * razonable —un recordatorio por cheque— que solo se había comido el techo de vueltas: acotar no era el
     * remedio, y la persona no tiene por qué saber cómo partir un pedido. Lo que sirve es seguir con un
     * «seguí».
     *
     * 🔴 Y NO PROMETE «DESDE DONDE QUEDÓ» (ronda de arreglos de la revisión independiente, R1, 30/10/2026;
     * otro cambio de contrato a propósito). Un mensaje en `error` no viaja en el historial
     * (build_messages_payload() lee solo los `listo`), así que el «seguí» retoma desde el pedido de la
     * persona, no desde lo que el turno alcanzó a hacer. Y este texto ya solo sale cuando el turno no hizo
     * NINGUNA ronda de herramientas (con alguna, cierra `listo` con lo hecho): lo honesto es «lo intento de
     * nuevo».
     */
    const MENSAJES = [
        self::MOTIVO_TIEMPO_AGOTADO => 'No llegué a terminar esta respuesta. Escribime «seguí» y lo intento de nuevo.',
        self::MOTIVO_SOBRECARGADO   => 'El servicio de IA está sobrecargado. Probá de nuevo en unos segundos.',
        self::MOTIVO_SIN_RESPUESTA  => 'La IA no llegó a generar una respuesta. Probá mandar el mensaje de nuevo.',
    ];

    /**
     * Encabezado del detalle técnico de un error transitorio. Es el mismo texto que usan los otros
     * siete servicios de IA del repo para el mismo caso (ResumenIaService, AiExcelAnalyzer y
     * compañía): buscar esa frase en los logs tiene que seguir encontrando todos los transitorios,
     * incluidos los del chat.
     *
     * @var string
     */
    const DETALLE_TRANSITORIO = 'El servicio de IA no está disponible en este momento. Esperá unos segundos y volvé a intentarlo.';

    /**
     * Motivo estable del fallo (una de las constantes MOTIVO_*).
     *
     * @var string
     */
    protected $motivo;

    /**
     * @param string $message Detalle técnico: log y error_mensaje, NUNCA la pantalla.
     * @param string $motivo  Una de las constantes MOTIVO_*.
     */
    public function __construct($message, $motivo)
    {
        parent::__construct($message);

        $this->motivo = (string) $motivo;
    }

    /**
     * @return string
     */
    public function motivo(): string
    {
        return $this->motivo;
    }

    /**
     * El texto que puede ver la persona, o null si este motivo no tiene uno propio (y entonces el
     * llamador pone su genérico).
     *
     * @return string|null
     */
    public function mensaje_para_la_persona()
    {
        return isset(self::MENSAJES[$this->motivo]) ? self::MENSAJES[$this->motivo] : null;
    }

    /**
     * Se acabó el tiempo del loop.
     *
     * @param string $detalle
     * @return self
     */
    public static function tiempo_agotado($detalle): self
    {
        return new self((string) $detalle, self::MOTIVO_TIEMPO_AGOTADO);
    }

    /**
     * Anthropic devolvió un error transitorio.
     *
     * @param string $detalle
     * @return self
     */
    public static function sobrecargado($detalle): self
    {
        return new self(self::DETALLE_TRANSITORIO . ' ' . (string) $detalle, self::MOTIVO_SOBRECARGADO);
    }

    /**
     * El loop cerró sin texto.
     *
     * @param string $detalle
     * @return self
     */
    public static function sin_respuesta($detalle): self
    {
        return new self((string) $detalle, self::MOTIVO_SIN_RESPUESTA);
    }

    /**
     * Cualquier otra falla. El detalle puede traer el body crudo de Anthropic: por eso este motivo
     * no tiene texto propio para la persona.
     *
     * @param string $detalle
     * @return self
     */
    public static function falla_tecnica($detalle): self
    {
        return new self((string) $detalle, self::MOTIVO_FALLA_TECNICA);
    }
}
