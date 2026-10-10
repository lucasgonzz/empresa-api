<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Reconoce un texto final del asistente que le AFIRMA a la persona algo que el turno no hizo. Son dos
 * familias, con su detector cada una:
 *
 *   - LA TARJETA PROMETIDA (afirma_una_tarjeta): "Dejé la tarjeta…", "Dejo la tarjeta para agendar…",
 *     "Tocá Confirmar" — misión asistente-tarjeta-sin-accion, 10/10/2026.
 *   - LA CARGA DADA POR HECHA (afirma_una_carga_hecha): "Marqué la tarea como hecha", "Listo, quedó
 *     registrado el gasto", "La marco como hecha…" — misión asistente-carga-prometida-sin-accion, el
 *     mismo 10/10/2026. Con ella va clase_del_pedido(), que clasifica lo que escribió la persona
 *     (agradecimiento, "sí", consulta u otra cosa): el servicio decide con las dos.
 *
 * 🔴 EL CASO REAL DE LA TARJETA (`demo`, 4.3.8, DeepSeek "Pensando ágil", modo "Resuelto", 10/10/2026).
 * Grabando los videos del centro de recursos el asistente contestó, en dos tomas seguidas, "Dejé la
 * tarjeta para confirmar el borrado de la categoría Pruebas…", y como PRIMER mensaje de una
 * conversación nueva "Encontré la subcategoría Alquiler. Dejo la tarjeta para agendar el pago del
 * 15/10 por 400.000…". En los tres el mensaje quedó con `acciones: []`: la persona busca la tarjeta y
 * no hay nada que confirmar. El mismo pedido, en otras corridas, sí arma la tarjeta.
 *
 * 🔴 EL CASO REAL DE LA CARGA (medición real contra DeepSeek ágil, 10/10/2026, sobre la base de un
 * slot). En el modo "directo" —el default de un negocio nuevo desde el 28/9/2026— el asistente no
 * deja tarjetas: ejecuta. Y miente con esa forma: a "Ya lo llamé" contestó "Marqué la tarea como
 * hecha." en UNA vuelta `end_turn`, sin herramienta y con el mensaje en `acciones: []`; a "Cambiale el
 * teléfono a 3415559999", "El teléfono de Distribuidora Norte quedó cambiado a 3415559999.", igual; y
 * a otro "Ya lo llamé", "La marco como hecha con fecha de hoy, sábado 10/10/2026.". Ninguno nombra una
 * tarjeta, así que el detector de la tarjeta no los ve. Y en "resuelto" la variante es la inversa: el
 * texto da la carga por registrada y lo único que hay en el mensaje es la tarjeta sin confirmar.
 *
 * AsistenteIaService cruza lo que dicen los detectores con las `ai_message_actions` del mensaje y con
 * el pedido de la persona: si el texto afirma una tarjeta y el turno no creó ninguna, o da por hecha
 * una carga y el turno no ejecutó ninguna, reintenta UNA vez —un solo reintento por turno para las dos
 * familias— con la nota que corresponde y, si sigue, cambia el texto por el honesto que corresponde.
 * El detalle está en el servicio.
 *
 * 🔴 UN FALSO POSITIVO LE CAMBIA A LA PERSONA UNA RESPUESTA BUENA POR UN TEXTO HONESTO. Por eso se
 * mira por oración y cada afirmación tiene su tipo, con sus propias excepciones (chequeos adversariales
 * del 10/10/2026, que encontraron los casos de cada una). Y en la carga es peor: un "no pude" sobre algo
 * que SÍ se hizo invita a repetirlo, y la carga se DUPLICA.
 *
 * La tarjeta (afirma_una_tarjeta):
 *
 *   - PASADO ("Dejé la tarjeta…"): afirma aunque después venga una pregunta o un ofrecimiento ("Te
 *     dejé la tarjeta, ¿la confirmás?"). No cuenta un subjuntivo ("para que te deje la tarjeta":
 *     sin tildes se escriben igual) ni una negación pegada al verbo ("No te dejé la tarjeta").
 *   - PROMESA ("Dejo la tarjeta…", "Te voy a dejar la tarjeta…"): es el caso 2 de `demo`, pero
 *     también la forma de PEDIR UN DATO ("Si me pasás el monto, te dejo la tarjeta", "¿Cuánto fue?
 *     Con eso te dejo la tarjeta"). No cuenta si antes del verbo hay un condicional o un pedido, ni
 *     si la oración —o la de antes— es una pregunta.
 *   - ESTADO ("La tarjeta queda para que la confirmes", "Quedó lista la tarjeta", "Ahí tenés la
 *     tarjeta"). Si es la COLA de una promesa ("…y dejo la tarjeta para que la confirmes") se decide
 *     como la promesa: ver la_coincidencia_afirma().
 *   - BOTÓN ("Tocá Confirmar"): no cuenta "te toca confirmar" ni una explicación de cómo hacerlo
 *     desde una pantalla ("entrá a Presupuestos, abrilo y tocá Confirmar").
 *
 *   Y para todos: "tarjeta" como medio de pago ("ventas con tarjeta", "el recargo de la tarjeta",
 *   "tarjeta de crédito", "tarjetas SUBE") no es una tarjeta del asistente, y una oración que repasa
 *   una tarjeta VIEJA ("ayer te dejé la tarjeta y la confirmaste", "la tarjeta de arriba", "quedó
 *   confirmada") no afirma una nueva.
 *
 * La carga (afirma_una_carga_hecha):
 *
 *   - HECHO ("Marqué la tarea como hecha", "Listo, la pasé al miércoles", "Di de alta al proveedor"):
 *     la primera persona del pretérito, buscada CON TILDES: sin tilde es un subjuntivo ("¿querés que
 *     lo cargue?"). Los verbos que también sirven para presentar una consulta piden un objeto de
 *     carga: "marqué" sólo "como hecha/pagada…" ("te marqué en negrita" no), "cambié/actualicé" sólo
 *     el teléfono, el precio, la fecha… ("cambié el orden de la lista" no), "borré/creé/eliminé"
 *     sólo una entidad ("creé un resumen" no), "pasé" sólo hacia una fecha, "armé" sólo un combo o
 *     un presupuesto, "hice" sólo la venta o el pago, y "dejé" sólo con un participio de carga.
 *   - QUEDÓ ("Quedó pasada al miércoles", "Ya está cargado", "Agendada la tarea…", "Gasto N° 3
 *     registrado", y la oración que es sólo un cierre: "Listo ✅"): un participio de carga detrás de
 *     "quedó" / "ya está", al empezar la oración, o detrás de lo que se cargó. No cuenta "queda" en
 *     presente (la explicación de una tarjeta), algo que ya existía ("ese proveedor ya está creado",
 *     "cargado dos veces", "desde el lunes") ni un encabezado de reporte ("Registrado por Martín el
 *     5/10").
 *   - PROMESA ("La marco como hecha…", "Te cargo el gasto"): la primera persona del presente, acotada
 *     a los verbos y objetos de una carga ("te lo paso en limpio", "te anoto el CBU" no). No cuenta
 *     con una condición antes o DESPUÉS del verbo ("Lo cargo apenas me pases la caja"), ni si la
 *     ÚLTIMA oración del texto es una pregunta ("La marco como hecha… ¿Querés que la marque con fecha
 *     de hoy?", medido el 10/10/2026).
 *
 *   Y para todos: una oración que afirma una TARJETA no es una carga ("Te creé la tarjeta del gasto",
 *   "Quedó cargada la tarjeta para que la confirmes"); una que repasa algo VIEJO ("ayer…", "hace un
 *   rato…", "el lunes…") no afirma nada nuevo; una negación ("No lo cargué", "Ningún gasto quedó
 *   registrado"), un condicional o una duda ("si cargué mal…", "quizás ya quedó") tampoco, ni una
 *   subordinada que describe ("el gasto que te cargué recién…"); y un renglón de lista no se mira
 *   (ahí van los datos de una consulta).
 *
 * Un falso negativo deja pasar el defecto de hoy, que ya existía; un falso positivo es un defecto
 * nuevo. Ante la duda, no dispara.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class TarjetaPrometidaIaHelper
{
    /**
     * Lo que ve la persona cuando el modelo afirmó una tarjeta, no la armó y el reintento tampoco.
     * Es la frase que propuso Lucas: dice la verdad y le deja a la persona la decisión de reintentar.
     */
    const TEXTO_HONESTO = 'No pude armar la tarjeta para esto. ¿Lo intento de nuevo?';

    /**
     * Lo que ve la persona cuando el modelo dio por hecha una carga, el reintento tampoco la hizo, no
     * hay ninguna tarjeta esperando el botón y en TODA la conversación no hay ninguna carga confirmada
     * (misión asistente-carga-prometida-sin-accion, 10/10/2026). Dice la verdad del turno —no quedó
     * nada registrado— y, como TEXTO_HONESTO, le deja a la persona la decisión de reintentar: acá no hay
     * ninguna carga de antes que se pueda duplicar.
     */
    const TEXTO_HONESTO_CARGA = 'No pude hacer esa carga: no quedó nada registrado. ¿La intento de nuevo?';

    /**
     * Lo mismo, pero hay una tarjeta `propuesta` esperando el botón: en este mensaje (modo "resuelto", o
     * "directo" con una carga que no se auto-ejecuta: `requiere_confirmacion`, `confirmada_parecida`,
     * una auto-ejecución que falló o un tipo que nunca se auto-ejecuta) o viva en la respuesta anterior
     * ("¿Ya quedó cargado?" → "Sí, quedó registrado", con la tarjeta sin tocar). La carga no está hecha:
     * está esperando. "No pude" también sería mentir; lo verdadero es que falta confirmarla.
     */
    const TEXTO_HONESTO_CARGA_CON_TARJETA = 'Todavía no quedó registrado: te dejé la tarjeta para que la confirmes.';

    /**
     * Lo mismo, sin ninguna tarjeta esperando, pero con una carga CONFIRMADA en un mensaje anterior de la
     * conversación.
     *
     * 🔴 POR QUÉ NO DICE "NO PUDE" NI OFRECE REINTENTAR. Acá el texto puede estar mintiendo —el caso
     * medido del modo directo es justamente un seguimiento después de una carga hecha: "Cambiale el
     * teléfono" → "quedó cambiado", sin herramienta— o puede estar contando bien una carga de antes que
     * la excepción del repaso no reconoció. "No pude hacer esa carga" invita a repetirla, y si la de
     * antes sí se hizo la persona la DUPLICA; "¿La intento de nuevo?" le deja al modelo la orden de
     * rehacer la carga vieja. Esta frase es verdadera en los dos casos: este mensaje no registró nada,
     * y si había un pedido, la persona lo vuelve a decir. Y no se puede dejar el texto como estaba: si el
     * reintento falla, la clase entera del modo directo pasaría.
     */
    const TEXTO_HONESTO_CARGA_CON_HISTORIA = 'Con este mensaje no se registró nada nuevo. Si me pediste un cambio, decímelo de nuevo y lo hago.';

    /**
     * Cualquier carácter de la misma oración. Un punto entre dígitos ("400.000", "$ 1.500") no corta:
     * tampoco lo corta oraciones().
     */
    const VENTANA = '(?:[^.!?\n]|(?<=\d)\.(?=\d))';

    /** El artículo pegado a "tarjeta": "te dejo EL DETALLE de las ventas con tarjeta" no lo tiene. */
    const DETERMINANTE = '(?:la|las|una|tu|tus|esta|esa|otra|nueva)';

    /**
     * Verbos en pasado (sin tildes): "dejé", "armé", "creé"… Varios se escriben igual que su subjuntivo.
     * Sin los genéricos ("pasé", "cargué", "puse"): "Pasé la tarjeta al cliente" o "el cargo por usar la
     * tarjeta" no hablan de una tarjeta del asistente (tercer chequeo, 10/10/2026).
     */
    const PASADO = '(?:deje|dejamos|arme|prepare|genere|propuse|cree)';

    /** Verbos en presente o futuro: el preámbulo de una llamada ("Dejo la tarjeta para agendar…"). */
    const PRESENTE = '(?:dejo|armo|preparo|propongo|voy a (?:dejar|armar|preparar)(?:te|le|les)?)';

    /**
     * Una negación pegada antes de la afirmación, sobre texto normalizado: "No te dejé la tarjeta…",
     * "Todavía no hay tarjeta…", "ninguna tarjeta", "No lo cargué", "Todavía no quedó registrado". La
     * usan las dos familias; es el mismo patrón que la_coincidencia_afirma() tenía escrito adentro.
     */
    const NEGACION_PEGADA = '/(?:\b(?:no|nunca|ni|tampoco)\s+(?:(?:te|le|les|se|lo|la|me|hay|habia|tengo|tenes|existe|quedo|queda)\s+){0,3}(?:ninguna\s+|una\s+|la\s+)?|\bninguna\s+|\bsin\s+(?:la\s+|una\s+)?)$/u';

    /**
     * La carga: "nada", "ningún" o "ninguna" y hasta dos palabras antes del verbo, sobre texto
     * normalizado: "Ningún gasto quedó registrado hoy", "Nada quedó registrado todavía".
     */
    const NEGACION_DE_CANTIDAD = '/\b(?:nada|ningun|ninguna|ninguno)(?:\s+\S+){0,2}\s+$/u';

    /**
     * La carga: una oración que repasa algo VIEJO, sobre texto normalizado. Parte del repaso de la
     * tarjeta (ver oracion_afirma_una_tarjeta()), con tres cambios medidos por los chequeos del
     * 10/10/2026:
     *
     *   - sin "quedó registrado": para la tarjeta es el repaso de una ya confirmada; para la carga es
     *     justamente la afirmación ("Listo, quedó registrado el gasto", con la tarjeta sin confirmar);
     *   - sin "antes" ni "anterior": "Cambié el precio: antes estaba a $1.000" y "Actualicé el teléfono;
     *     el anterior era…" son la forma natural de mentir un cambio;
     *   - con las marcas de algo YA hecho: "hace un rato", "esta mañana", "la semana pasada", "parecido"
     *     (el aviso de una carga parecida ya registrada), y un día o una fecha con artículo ("lo registré
     *     el lunes", "te lo cargué el 5/10") — salvo detrás de "para", "al", "hasta", "desde" o "en":
     *     "la pasé para el miércoles" y "quedó en el miércoles" son la carga.
     *
     * "Recién" no está a propósito: "recién lo cargué" es también la mentira.
     */
    const REPASO_DE_UNA_CARGA = '/\b(?:ayer|anteriormente|de arriba|el otro dia|la otra vez|confirmaste|cancelaste|ya la confirm\w*|(?:quedo|quedaron|esta|estan) (?:confirmad|cancelad|reemplazad|vencid)\w*|hace un rato|hace un momento|hace (?:unos|un par de) (?:minutos|dias)|esta ma[nñ]ana|esta tarde|hoy a la ma[nñ]ana|la semana pasada|el mes pasado|parecid\w*)\b|(?<!para )(?<!al )(?<!hasta )(?<!desde )(?<!en )\bel (?:lunes|martes|miercoles|jueves|viernes|sabado|domingo|\d{1,2}\/\d{1,2})\b/u';

    /**
     * La carga: la primera persona del pretérito que afirma una carga SIN mirar el objeto, con tildes
     * (sin tilde es subjuntivo: "¿querés que lo cargue?"). Los que también sirven para presentar una
     * consulta ("marqué", "cambié", "creé"…) van en patrones_de_hecho() con su objeto.
     */
    const PRETERITO_DE_CARGA = '(?:cargué|registré|agendé|apunté|asenté|ingresé|asigné|guardé|reprogramé|programé|moví|subí|cobré|facturé|aumenté|completé|di de alta|di de baja|di por hech[oa]s?|mandé a buscar)';

    /**
     * Lo que tiene que venir justo detrás de "cambié", "actualicé", "modifiqué" o "edité" para que sea
     * una carga, normalizado: un dato ("el teléfono", "los precios"), un monto ("a 6000"), una fecha
     * ("al viernes", "para el 14/10"), una entidad ("el cliente") o "de fecha". "Cambié el orden de la
     * lista" y "Actualicé la búsqueda" presentan una consulta.
     */
    const OBJETO_DE_UN_CAMBIO = '(?:(?:el|la|los|las|su|sus) (?:telefono|precio|fecha|nombre|direccion|mail|email|cuit|stock|categoria|caja|monto|estado|dia|descripcion|costo|margen|foto|imagen)(?:s|es)?\b|a \$?\s?\d|(?:al|para el) (?:lunes|martes|miercoles|jueves|viernes|sabado|domingo|\d|proxim|mes\b)|(?:el|la|al|a la|los|las) (?:cliente|proveedor|articulo|producto|tarea|gasto|pago|venta)(?:s|es)?\b|de (?:fecha|dia|caja|categoria)\b)';

    /**
     * Lo que tiene que venir justo detrás de "eliminé", "borré", "creé" o "anoté" para que sea una carga,
     * normalizado: una entidad del negocio ("la categoría Pruebas", "una de las tareas", "las 3
     * tareas"). "Creé un resumen" y "Eliminé los duplicados del listado" presentan una consulta.
     */
    const ENTIDAD_DE_CARGA = '(?:(?:una|uno|dos|tres|cuatro|cinco|\d+) de )?(?:la|el|los|las|un|una|tu|tus|al|a la|a los|a las) (?:\d+ )?(?:categoria|subcategoria|cliente|proveedor|articulo|producto|tarea|recordatorio|gasto|pago|venta|lista de precios|deposito|sucursal|usuario|empleado|marca|rubro|combo|oferta|presupuesto|cheque|banco)(?:s|es)?\b';

    /**
     * La carga: los participios, sin tildes ("cargado", "agendadas", "dado de alta"). "Cambiado" está
     * por el caso medido del modo directo: "El teléfono de Distribuidora Norte quedó cambiado a
     * 3415559999.", sin herramienta (10/10/2026); "cobrado", porque "cobré" es una carga. "Marcado" sólo
     * cuenta "como hecho / pagado…", igual que "marqué": "Te dejé marcados en negrita los que vencen
     * hoy" presenta una consulta.
     */
    const PARTICIPIO_DE_CARGA = '(?:(?:cargad|registrad|agendad|anotad|guardad|cread|actualizad|modificad|editad|asignad|borrad|eliminad|reprogramad|movid|pasad|cambiad|completad|cobrad)[oa]s?|marcad[oa]s? como (?:hech|pagad|cobrad|complet|entregad)\w*|dad[oa]s? de (?:alta|baja))';

    /** Los mismos sin "pasado" ni "movido", para la oración que EMPIEZA con el participio: "Pasado mañana vence el alquiler". */
    const PARTICIPIO_AL_EMPEZAR = '(?:(?:cargad|registrad|agendad|anotad|guardad|cread|actualizad|modificad|editad|asignad|borrad|eliminad|reprogramad|cambiad|completad|cobrad)[oa]s?|marcad[oa]s? como (?:hech|pagad|cobrad|complet|entregad)\w*|dad[oa]s? de (?:alta|baja))';

    /** Lo que se cargó, al empezar la oración: "Gasto N° 3 registrado", "Teléfono actualizado ✅". */
    const QUE_SE_CARGO = '(?:gasto|pago|cobro|venta|presupuesto|proveedor|cliente|tarea|combo|oferta|articulo|telefono|precio|stock|foto|imagen|permiso|categoria|subcategoria|recordatorio)(?:s|es)?';

    /** Los emojis y el markdown con los que el modelo abre o cierra una oración: "✅ Gasto registrado", "**Listo**". */
    const SIMBOLOS = '[\s*_~¡!✅✔☑👍👌🙌🎉📝\x{FE0F}]';

    /** Lo que puede abrir la oración antes de lo que se cargó: "Listo, agendada.", "✅ Perfecto: gasto registrado". */
    const APERTURA = self::SIMBOLOS . '*(?:(?:listo|perfecto|joya|hecho|ya|bien|dale|ok|okey|todo)\b(?:' . self::SIMBOLOS . '|[,.:;>-])*){0,2}';

    /** Entre "quedó" y el participio, lo que lo desarma: "Quedó a medias: no está guardado el teléfono". */
    const ENTRE_QUEDO_Y_PARTICIPIO = '/\b(?:no|sin|pendiente|falta|faltan|a medias)\b/u';

    /**
     * Una oración que habla de algo que YA existía, normalizada: "Ese pago ya está registrado desde el
     * lunes", "ya está cargado dos veces", "ya existe". Ahí "ya está cargado" es un dato, no una carga.
     * "Desde" sólo cuando marca un tiempo: "El proveedor quedó creado, pero no puedo mandarle un
     * WhatsApp desde acá" sí afirma la carga.
     */
    const YA_EXISTIA = '/\b(?:desde (?:el|la|los|ayer|hace|hoy|antes|\d|enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)|dos veces|repetid\w*|duplicad\w*|ya existe|ya existia|estaba)\b/u';

    /** Un demostrativo antes del verbo, CON tildes ("esta" y no "está"): "Ese proveedor ya está creado". */
    const DEMOSTRATIVO = '/\b(?:ese|esa|esos|esas|este|esta|estos|estas)\b/u';

    /**
     * Un verbo en presente o futuro entre el comienzo de la oración y el participio: medido en esta
     * rama contra DeepSeek el 10/10/2026, "El gasto queda agendado como tarea con gasto asociado para el
     * jueves 15/10 porque la fecha es futura." explica, no afirma.
     */
    const PRESENTE_O_FUTURO = '/\b(?:queda|quedan|quedaria|quedarian|quedara|quedaran|va a quedar|van a quedar|se va a|iria|irian|seria|serian)\b/u';

    /** Lo que sigue al participio en un encabezado de reporte: "Registrado por Martín el 5/10", "Actualizado al 9/10". */
    const ENCABEZADO_DE_REPORTE = '/^\s*(?:por (?![$\d])\S|el \d|desde\b|al \d)/u';

    /** La oración que es SÓLO un cierre, normalizada: "Listo ✅", "Ya está.", "Listo, ya está.". */
    const CIERRE = '/^' . self::SIMBOLOS . '*(?:(?:listo|hecho|ya esta|ya quedo|todo listo|ya lo hice)[\W_]*)+$/u';

    /**
     * Lo que, en otra oración del mismo texto, hace que un cierre ("Listo.") sea sólo el comienzo de un
     * pedido de datos: "Listo. ¿De qué caja sale?", "Listo. Decime el monto". Ahí no afirma una carga.
     */
    const PIDE_UN_DATO = '/\b(?:decime|pasame|necesito|me falta|me faltan|falta|faltan|indicame|confirmame)\b/u';

    /**
     * Lo que, DESPUÉS del verbo de una promesa y en la misma oración, la vuelve condición: "Lo cargo
     * apenas me pases la caja", "Te lo agendo, pero necesito la fecha", "Lo registro, solo decime la caja".
     */
    const CONDICION_DESPUES = '/\b(?:si|cuando|apenas|en cuanto|una vez que|pero|solo decime|necesito|me falta|me pasas|me decis|decime)\b/u';

    /** Una duda antes de la afirmación, normalizada: "Quizás ya quedó hecha o borrada" (medido en "resuelto"). */
    const DUDA = '/\b(?:quizas|quiza|capaz que|tal vez|puede que|a lo mejor|probablemente|seguramente|me parece que|parece que|creo que|supongo que)\b/u';

    /**
     * Una subordinada que DESCRIBE algo, justo antes del verbo, normalizada: el artículo, hasta tres
     * palabras, "que" y los clíticos — "el que quedó recién cargado" (medido en "directo"), "El gasto
     * que te cargué recién…", "Sin contar el pago que registré hoy…". No "así que" ni "ya que", que
     * cuentan lo que se hizo.
     */
    const RELATIVO = '/\b(?:el|la|los|las|lo)(?:\s+(?!(?:asi|ya)\s)\S+){0,3}\s+que\s+(?:(?:ya|te|le|les|lo|la|los|las|se|me)\s+)*$/u';

    /** Una pregunta indirecta justo antes del verbo, normalizada: "Te muestro cómo quedó cargado el gasto". */
    const PREGUNTA_INDIRECTA = '/\b(?:como|donde)\s+(?:(?:ya|te|le|les|lo|la|se)\s+)*$/u';

    /** Lo que vuelve condicional un "quedó": la explicación de una tarjeta ("quedó registrado al confirmar"). */
    const AL_CONFIRMAR = '/\b(?:al confirmar|cuando (?:la |lo )?confirmes|si (?:la |lo )?confirmas|apenas (?:la |lo )?confirmes|una vez que (?:la |lo )?confirmes|para que (?:la |lo )?confirmes|cuando toques)\b/u';

    /**
     * Las palabras de un agradecimiento o una conformidad, normalizadas y con las letras repetidas
     * colapsadas ("Graciasss", "grosso"): ver clase_del_pedido().
     *
     * @var array<int, string>
     */
    const PALABRAS_DE_GRACIAS = [
        'gracias', 'muchas', 'mil', 'muchisimas', 'genial', 'perfecto', 'perfecta', 'buenisimo', 'buenisima',
        'barbaro', 'barbara', 'joya', 'joyita', 'excelente', 'impecable', 'espectacular', 'divino', 'genio',
        'capo', 'crack', 'idolo', 'groso', 'maquina', 'che', 'amigo', 'amiga', 'por', 'todo', 'sos', 'lo',
        'mas', 're', 'bien', 'ahi', 'buenas', 'de', 'una', 'que', 'ah', 'muy',
    ];

    /**
     * Las palabras de un SÍ, normalizadas y colapsadas ("Siii", "okk"); "de una" también cuenta: ver
     * clase_del_pedido().
     *
     * @var array<int, string>
     */
    const PALABRAS_DE_SI = [
        'si', 'sisi', 'dale', 'ok', 'oka', 'okey', 'okay', 'oki', 'okis', 'listo', 'lista', 'vale', 'bueno',
        'buena', 'joya',
    ];

    /** Las palabras, como mucho, de un agradecimiento; y las de un "sí" sin mezcla. */
    const LARGO_MAXIMO_DE_UN_GRACIAS = 8;
    const LARGO_MAXIMO_DE_UN_SI = 4;

    /**
     * Un pedido de carga escrito como pregunta, normalizado: "¿Podés pasarla al miércoles?", "¿Me la
     * pasás al miércoles?", "¿Le cambiás el teléfono?". La segunda persona de un verbo de carga.
     */
    const PEDIDO_DE_CARGA_EN_PREGUNTA = '/\b(?:podes|podrias|puedes|podras|me haces el favor|pasas|cargas|agendas|anotas|registras|cambias|marcas|moves|borras|eliminas|actualizas|modificas|haces|armas|das de alta|sacas|pones|subis|mandas|cobras|pagas|creas|asignas|reprogramas|agregas|sumas)\b/u';

    /**
     * Un imperativo de carga con un pronombre pegado, normalizado: "Pasala", "Cambiale", "Cargame",
     * "Hacelo", "Dale de alta". Sin el pronombre "carga", "agenda" o "marca" son sustantivos, y "dale"
     * a secas es un sí.
     */
    const IMPERATIVO_CON_ENCLITICO = '/\b(?:(?:pasa|carga|agenda|anota|registra|cambia|marca|move|borra|elimina|actualiza|modifica|hace|arma|saca|pone|subi|manda|cobra|paga|crea|asigna|reprograma|agrega|suma|deja|guarda)(?:(?:me|te|le|les|nos)(?:la|lo|las|los)?|la|lo|las|los)|da(?:le|lo|la|me)\s+de (?:alta|baja))\b/u';

    /** El imperativo de voseo de un verbo de carga, CON tildes: "Cargá un gasto", "Pasá la tarea", "Dá de alta". */
    const IMPERATIVO_DE_VOSEO = '/\b(?:cargá|pasá|agendá|anotá|registrá|cambiá|marcá|mové|borrá|eliminá|actualizá|modificá|hacé|armá|sacá|poné|mandá|cobrá|pagá|creá|asigná|reprogramá|agregá|sumá|dejá|guardá|da de alta|dá de alta)(?!\p{L})/u';

    /**
     * Un pedido de MOSTRAR algo, normalizado: "Mostrame las tareas", "Decime cuánto vendí", "Fijate si
     * quedó". "Pasame" no está: "pasame la tarea al miércoles" es una carga.
     */
    const PEDIDO_DE_MOSTRAR = '/\b(?:mostrame|mostra|decime|deci|listame|fijate|revisa|revisame|busca|buscame|contame|conta|quiero ver|necesito ver|quiero saber|necesito saber|me decis|me mostras)\b/u';

    /**
     * Las afirmaciones, sobre el texto normalizado (minúscula y sin tildes), cada una con su tipo.
     *
     * @return array<int, array{0: string, 1: string}>  [patrón, tipo]
     */
    protected static function patrones()
    {
        $v = self::VENTANA;
        $det = self::DETERMINANTE;

        return [
            /* "Dejé la tarjeta…", "Te armé una tarjeta…", "Te creé la tarjeta del gasto…". */
            ['/\b' . self::PASADO . '\b' . $v . '{0,50}\b' . $det . ' tarjetas?\b/u', 'pasado'],
            /* "Dejé para confirmar el borrado…", "Te dejé el gasto listo para que lo confirmes". */
            ['/\b' . self::PASADO . '\b' . $v . '{0,60}\bpara (?:que )?(?:la |lo |las |los )?confirm/u', 'pasado'],
            /* "Dejo la tarjeta para agendar…", "Te voy a dejar la tarjeta…". */
            ['/\b' . self::PRESENTE . '\b' . $v . '{0,50}\b' . $det . ' tarjetas?\b/u', 'promesa'],
            /* "Ahí tenés la tarjeta", "Abajo te queda la tarjeta". */
            ['/\b(?:aca|ahi|abajo) (?:te |la )?(?:tenes|va|esta|queda|quedo)\b' . $v . '{0,30}\b' . $det . ' tarjetas?\b/u', 'estado'],
            /* "Ya tenés la tarjeta abajo", "Tenés la tarjeta para revisar". */
            ['/\btenes\b' . $v . '{0,10}\b' . $det . ' tarjetas?\b/u', 'estado'],
            /* "La tarjeta queda para que la confirmes", "La tarjeta está lista". */
            ['/\btarjetas?\b' . $v . '{0,40}\b(?:queda|quedo|quedan|quedaron|esta|estan) (?:lista|listas|armada|armadas|pendiente de|esperando|para (?:que|confirm|revis|aprob))/u', 'estado'],
            /* "Quedó lista la tarjeta", "Te quedó armada la tarjeta del alquiler". */
            ['/\b(?:queda|quedo|quedan|quedaron|esta|estan) (?:lista|listas|armada|armadas)\b' . $v . '{0,20}\b' . $det . ' tarjetas?\b/u', 'estado'],
            /* "Tenés la tarjeta para confirmar", "La tarjeta para que la revises". */
            ['/\btarjetas?\b' . $v . '{0,30}\bpara (?:que (?:la |lo )?(?:confirmes|revises|apruebes)|confirmar|revisar|aprobar)\b/u', 'estado'],
            /* "Tocá Confirmar", "Tocá en la tarjeta el botón Confirmar", "Dale a Confirmar". */
            ['/\b(?:toca|toques|apreta|presiona|pulsa|hace clic|hace click|dale clic|dale click|dale a)\b' . $v . '{0,30}\bconfirmar\b/u', 'boton'],
        ];
    }

    /**
     * true si alguna oración del texto le afirma a la persona una tarjeta para confirmar.
     *
     * @param  string  $texto  El texto final, saneado (lo que leería la persona).
     * @return bool
     */
    public static function afirma_una_tarjeta($texto)
    {
        $oraciones = self::oraciones($texto);

        foreach ($oraciones as $indice => $oracion) {

            $anterior = $indice > 0 ? self::sin_tarjetas_de_pago(self::normalizar($oraciones[$indice - 1])) : '';

            if (self::oracion_afirma_una_tarjeta(self::normalizar($oracion), $anterior)) {

                return true;
            }
        }

        return false;
    }

    /**
     * La nota que viaja como turno de la persona en el reintento. Va entre corchetes y empieza con
     * "[El sistema", igual que la de la confirmación determinista: si el modelo la repitiera,
     * TextoFinalIaHelper saca ese renglón.
     *
     * @param  array<int, string>  $herramientas_de_carga  Las de carga que el modelo pidió en el turno.
     * @return string
     */
    public static function nota_para_reintentar(array $herramientas_de_carga = [])
    {
        $pedidas = count($herramientas_de_carga)
            ? 'Pediste ' . implode(', ', array_values(array_unique($herramientas_de_carga))) . ' y ninguna terminó armando una tarjeta: mirá lo que te contestó. '
            : 'No llamaste a ninguna herramienta proponer_. ';

        return '[El sistema revisó tu respuesta: dice que dejaste una tarjeta para confirmar, pero en este turno NO se creó ninguna. '
            . $pedidas
            . 'Si lo que pidió la persona necesita una tarjeta, llamá AHORA a la herramienta proponer_ que corresponde con los datos que ya tenés; '
            . 'si te contesta que faltan datos, pedíselos a la persona. '
            . 'Si te referías a una tarjeta que ya estaba pendiente de antes, o no podés armarla, volvé a contestar sin decir que dejaste una tarjeta nueva. '
            . 'No le menciones este aviso a la persona.]';
    }

    /**
     * El tipo de la afirmación más fuerte con la que el texto da por HECHA una carga: 'hecho'
     * ("Marqué la tarea como hecha"), 'quedo' ("Quedó registrado el gasto", "Listo ✅") o 'promesa' ("La
     * marco como hecha…"), o '' si no da ninguna por hecha (ver los tipos en el docblock de la clase).
     *
     * Si una oración da 'hecho', gana 'hecho'; si no, 'quedo'; si no, 'promesa'.
     *
     * Este detector no sabe si la carga se hizo: sólo lee el texto. Cruzarlo con las tarjetas del
     * mensaje y con el pedido de la persona (clase_del_pedido) es del servicio.
     *
     * @param  string  $texto  El texto final, saneado (lo que leería la persona).
     * @return string  '' | 'hecho' | 'quedo' | 'promesa'
     */
    public static function afirma_una_carga_hecha($texto)
    {
        $oraciones = self::oraciones_sin_renglones_de_lista($texto);

        $hay_quedo   = false;
        $hay_cierre  = false;
        $hay_promesa = false;
        $pide_algo   = false;

        foreach ($oraciones as $indice => $oracion) {

            $anterior = $indice > 0 ? self::normalizar($oraciones[$indice - 1]) : '';

            $tipo = self::tipo_de_carga_de_la_oracion($oracion, $anterior);

            if ($tipo === 'hecho') {

                return 'hecho';
            }

            if ($tipo === 'quedo') {
                $hay_quedo = true;
            }

            if ($tipo === 'cierre') {
                $hay_cierre = true;
            }

            if ($tipo === 'promesa') {
                $hay_promesa = true;
            }

            if (self::es_pregunta($oracion) || preg_match(self::PIDE_UN_DATO, self::normalizar($oracion))) {
                $pide_algo = true;
            }
        }

        if ($hay_quedo) {

            return 'quedo';
        }

        /*
         * La oración que es sólo un cierre ("Listo ✅", "Ya está.") es la mentira clásica cuando el turno
         * no hizo nada — salvo que otra oración del texto pregunte o pida un dato: "Listo. ¿De qué caja
         * sale?" es el "entendido" de un pedido incompleto, y cambiarlo por el texto honesto le borraría
         * a la persona la pregunta (el mismo daño que el falso positivo de la tarjeta del 10/10/2026).
         */
        if ($hay_cierre && ! $pide_algo) {

            return 'quedo';
        }

        /*
         * La promesa no cuenta si el texto TERMINA preguntando (medido en "directo" el 10/10/2026): "La
         * marco como hecha para el miércoles 14/10/2026. Está agendada para el miércoles, que todavía no
         * llegó. ¿Querés que la marque hecha con fecha de hoy?" no promete nada: pregunta.
         */
        if ($hay_promesa && ! self::termina_preguntando($texto)) {

            return 'promesa';
        }

        return '';
    }

    /**
     * Qué es lo que escribió la persona, para decidir si un texto que da por hecha una carga puede
     * estar contando la verdad (correcciones del chequeo independiente, 10/10/2026):
     *
     *   - 'acuse_gracias': un agradecimiento o una conformidad, de hasta LARGO_MAXIMO_DE_UN_GRACIAS
     *     palabras de PALABRAS_DE_GRACIAS ("Gracias genio!", "mil gracias, sos lo más", "bien ahí").
     *   - 'acuse_si': un SÍ ("Dale", "Sí", "ok", "de una"), o un agradecimiento con un sí adentro ("dale
     *     gracias"). Es el sí a un ofrecimiento: puede ser un pedido nuevo. También un mensaje que es
     *     sólo emojis ("👍"): puede ser las dos cosas, y el "sí" es el que exige más para no disparar.
     *   - 'consulta': una pregunta por un estado ("¿Ya quedó cargado?", "¿Lo cargaste?") o un pedido de
     *     mostrar ("Mostrame las tareas"), siempre que no traiga un pedido de carga.
     *   - 'otro': todo lo demás — un pedido de carga ("Pasala al miércoles"), un pedido en forma de
     *     pregunta ("¿Me la pasás al miércoles?", "Cambiale el teléfono, ¿ok?") o un hecho que implica
     *     una carga ("Ya lo llamé", "Gasté 15000…").
     *
     * 🔴 Un mensaje SIN texto no es un acuse: es una foto sola, que casi siempre es el pedido de OTRA
     * carga (una factura). Es 'otro'.
     *
     * @param  string|null  $pedido
     * @return string  'acuse_gracias' | 'acuse_si' | 'consulta' | 'otro'
     */
    public static function clase_del_pedido($pedido)
    {
        $texto = trim((string) $pedido);

        if ($texto === '') {

            return 'otro';
        }

        $normalizado = self::normalizar($texto);

        $limpio = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalizado);
        $limpio = (string) preg_replace('/(\p{L})\1+/u', '$1', $limpio);

        $palabras = preg_split('/\s+/u', trim($limpio), -1, PREG_SPLIT_NO_EMPTY);
        $palabras = is_array($palabras) ? $palabras : [];

        if (count($palabras) === 0) {

            return 'acuse_si';
        }

        $tiene_un_si = strpos(' ' . implode(' ', $palabras) . ' ', ' de una ') !== false;
        $todas       = true;

        foreach ($palabras as $palabra) {

            if (in_array($palabra, self::PALABRAS_DE_SI, true)) {
                $tiene_un_si = true;
                continue;
            }

            if (! in_array($palabra, self::PALABRAS_DE_GRACIAS, true)) {
                $todas = false;
                break;
            }
        }

        /*
         * Un "sí" mezclado con un agradecimiento ("dale, gracias") es 'acuse_si', aunque pase de cuatro
         * palabras: de las dos, es la clase que exige más para no disparar.
         */
        if ($todas && count($palabras) <= self::LARGO_MAXIMO_DE_UN_GRACIAS) {

            return $tiene_un_si ? 'acuse_si' : 'acuse_gracias';
        }

        if (self::pide_una_carga($texto, $normalizado)) {

            return 'otro';
        }

        if (self::es_pregunta($texto) || preg_match(self::PEDIDO_DE_MOSTRAR, $normalizado)) {

            return 'consulta';
        }

        return 'otro';
    }

    /**
     * true si el texto es una pregunta (tiene "?" o "¿" en cualquier lado).
     *
     * @param  string  $texto
     * @return bool
     */
    public static function es_pregunta($texto)
    {
        $texto = (string) $texto;

        return mb_strpos($texto, '?') !== false || mb_strpos($texto, '¿') !== false;
    }

    /**
     * true si la ÚLTIMA oración del texto es una pregunta. El servicio lo usa también con la respuesta
     * ANTERIOR del asistente: si terminó ofreciendo algo ("¿Querés que también te la pase al
     * miércoles?"), el "dale" que sigue es un pedido nuevo.
     *
     * @param  string  $texto
     * @return bool
     */
    public static function termina_preguntando($texto)
    {
        $oraciones = self::oraciones($texto);

        if (count($oraciones) === 0) {

            return false;
        }

        return self::es_pregunta($oraciones[count($oraciones) - 1]);
    }

    /**
     * La nota del reintento cuando el texto dio por hecha una carga que el turno no ejecutó (misión
     * asistente-carga-prometida-sin-accion). Empieza con "[El sistema revisó tu respuesta:", igual que
     * la de la tarjeta, así TextoFinalIaHelper saca el renglón si el modelo la repitiera. Dice la
     * verdad del turno: nada ejecutado, una tarjeta de este mensaje esperando el botón, o una de la
     * respuesta anterior que sigue sin confirmar.
     *
     * @param  array<int, string>  $herramientas_de_carga  Las de carga que el modelo pidió en el turno.
     * @param  bool  $hay_tarjeta_propuesta  true si ESTE mensaje tiene una tarjeta `propuesta`.
     * @param  bool  $hay_tarjeta_pendiente_de_antes  true si la respuesta anterior dejó una tarjeta que sigue `propuesta`.
     * @return string
     */
    public static function nota_para_reintentar_carga(array $herramientas_de_carga, $hay_tarjeta_propuesta, $hay_tarjeta_pendiente_de_antes = false)
    {
        if ($hay_tarjeta_propuesta) {

            return '[El sistema revisó tu respuesta: dice que la carga ya quedó hecha, pero NO se ejecutó: quedó como tarjeta para que la persona la confirme. '
                . 'Volvé a contestar diciendo que dejaste la tarjeta para confirmar, sin decir que ya está hecho ni registrado. '
                . 'No le menciones este aviso a la persona.]';
        }

        $pedidas = count($herramientas_de_carga)
            ? 'Pediste ' . implode(', ', array_values(array_unique($herramientas_de_carga))) . ' y ninguna terminó en una carga: mirá lo que te contestó. '
            : '';

        $de_antes = $hay_tarjeta_pendiente_de_antes
            ? 'La tarjeta que dejaste antes sigue sin confirmar: no digas que quedó registrado. '
            : '';

        $referencia = $hay_tarjeta_pendiente_de_antes
            ? 'Si te referías a esa tarjeta, volvé a contestar diciendo que sigue esperando que la persona la confirme. '
            : 'Si te referías a algo que en el historial ya figura como confirmado, volvé a contestar dejando claro que eso se hizo antes, no ahora. ';

        return '[El sistema revisó tu respuesta: dice que la carga ya quedó hecha, pero en este turno NO se ejecutó ninguna carga y no quedó nada registrado. '
            . $de_antes
            . $pedidas
            . 'Si la persona pidió una carga o un cambio, llamá AHORA a la herramienta proponer_ que corresponde con los datos que ya tenés; '
            . 'si te contesta que faltan datos, pedíselos a la persona. '
            . $referencia
            . 'No le menciones este aviso a la persona.]';
    }

    /**
     * Las oraciones del texto: se corta después de un punto, un signo de cierre o un salto de línea.
     * "400.000" o "15/10" no se cortan (no hay espacio después del punto).
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    protected static function oraciones($texto)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {

            return [];
        }

        $partes = preg_split('/(?<=[.!?…])\s+|\n+/u', $texto);

        if (! is_array($partes)) {

            return [$texto];
        }

        $oraciones = [];

        foreach ($partes as $parte) {

            if (trim($parte) !== '') {
                $oraciones[] = trim($parte);
            }
        }

        return $oraciones;
    }

    /**
     * true si UNA oración afirma una tarjeta nueva (ver los tipos en el docblock de la clase).
     *
     * @param  string  $oracion   Normalizada.
     * @param  string  $anterior  La oración de antes, normalizada ('' si es la primera).
     * @return bool
     */
    protected static function oracion_afirma_una_tarjeta($oracion, $anterior)
    {
        if ($oracion === '') {

            return false;
        }

        /*
         * Repasa una tarjeta VIEJA: "ayer te dejé la tarjeta y la confirmaste", "la tarjeta de
         * arriba", "quedó confirmada". No afirma una nueva. "antes de confirmar" sí puede ser nueva.
         */
        if (preg_match('/\b(?:ayer|antes(?! de)|anterior|anteriormente|de arriba|el otro dia|la otra vez|confirmaste|cancelaste|ya la confirm\w*|(?:quedo|quedaron|esta|estan) (?:confirmad|registrad|cancelad|reemplazad|vencid)\w*)\b/u', $oracion)) {

            return false;
        }

        $oracion = self::sin_tarjetas_de_pago($oracion);

        foreach (self::patrones() as $patron) {

            if (! preg_match_all($patron[0], $oracion, $coincidencias, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($coincidencias[0] as $coincidencia) {

                if (self::la_coincidencia_afirma($patron[1], $oracion, substr($oracion, 0, $coincidencia[1]), $anterior)) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Las excepciones de cada tipo, mirando lo que hay ANTES de la coincidencia en la oración.
     *
     * @param  string  $tipo      pasado | promesa | estado | boton
     * @param  string  $oracion   La oración normalizada.
     * @param  string  $antes     Lo que hay antes de la coincidencia.
     * @param  string  $anterior  La oración de antes, normalizada.
     * @return bool
     */
    protected static function la_coincidencia_afirma($tipo, $oracion, $antes, $anterior)
    {
        /* Una pregunta que arranca antes: "¿Te dejo la tarjeta…?", "¿Tenés la tarjeta…?". */
        if (strpos($antes, '¿') !== false) {

            return false;
        }

        /* Una negación pegada: "No te dejé la tarjeta…", "Todavía no hay tarjeta…", "ninguna tarjeta". */
        if (preg_match(self::NEGACION_PEGADA, $antes)) {

            return false;
        }

        if ($tipo === 'pasado') {

            /*
             * "…para que te deje la tarjeta": subjuntivo, no un hecho. Sólo las construcciones que lo
             * piden: "así que te dejé la tarjeta" o "ya que…" SÍ afirman.
             */
            return ! preg_match('/\b(?:para|hasta|antes de|despues de|a menos|sin|necesitas|preferis|esperas|pedis|queres|quieras|hace falta|falta) que (?:te |le |les |se )?(?:la |lo |las |los )?$/u', $antes);
        }

        if ($tipo === 'promesa') {

            /* Una pregunta (con o sin "¿"), o justo después de una: está pidiendo un dato. */
            if (substr(rtrim($oracion), -1) === '?' || substr(rtrim($anterior), -1) === '?') {

                return false;
            }

            /*
             * Un condicional o un pedido antes del verbo: "Si me pasás el monto, te dejo la tarjeta".
             * El "si" va seguido de una palabra: sin tildes, "Sí, te dejo la tarjeta" (con la coma) es
             * un sí, y afirma.
             */
            return ! preg_match('/(?:\bsi\s+\w|\b(?:queres que|puedo|podria|podemos|cuando|apenas|en cuanto|una vez que|con eso|con ese dato|con esos datos|decime|pasame|mandame|confirmame|avisame|contame|indicame)\b)/u', $antes);
        }

        if ($tipo === 'boton') {

            /* "Mañana te toca confirmar el pedido": la tercera persona, no el botón. */
            if (preg_match('/\b(?:te|le|les|nos|me)\s+$/u', $antes)) {

                return false;
            }

            /*
             * Sin una tarjeta nombrada en la oración o en la de antes, "Tocá Confirmar" es una
             * explicación de cómo se hace algo en una pantalla ("Para guardar, tocá Confirmar"), no
             * una tarjeta del asistente (tercer chequeo, 10/10/2026).
             */
            if (! preg_match('/\btarjetas?\b/u', $oracion . ' ' . $anterior)) {

                return false;
            }

            /* Una explicación de cómo se hace desde una pantalla. */
            return ! preg_match('/\b(?:entra a|entra en|anda a|abri|abrila|abrilo|desde la pantalla|en la pantalla|de la pantalla|desde el menu|en el menu)\b/u', $oracion);
        }

        /*
         * 🔴 EL ESTADO COMO COLA DE UNA PROMESA (misión asistente-carga-prometida-sin-accion, medido con
         * esta rama contra DeepSeek real el 10/10/2026). "Gracias!" con el gasto esperando la caja: "Si
         * querés que arme la carga, decime a qué caja va la plata y dejo la tarjeta para que la
         * confirmes." hace coincidir dos patrones: la promesa ("dejo la tarjeta"), que sus excepciones
         * desarman porque está pidiendo la caja, y el estado ("la tarjeta para que la confirmes"), que no
         * tenía ninguna. La guarda cambiaba la respuesta por "No pude armar la tarjeta para esto. ¿Lo
         * intento de nuevo?" y a la persona se le BORRABA la pregunta por la caja (igual con "…Decime
         * cuál y dejo la tarjeta para confirmar."). Si lo de antes termina en el verbo de una promesa
         * (con sus pronombres y el determinante), la coincidencia es la cola de esa promesa y se decide
         * con sus reglas, sobre lo que hay antes del verbo.
         */
        if ($tipo === 'estado'
            && preg_match('/\b' . self::PRESENTE . '\s+(?:(?:te|le|les|lo|la)\s+)*(?:' . self::DETERMINANTE . '\s+)?$/u', $antes, $verbo, PREG_OFFSET_CAPTURE)) {

            return self::la_coincidencia_afirma('promesa', $oracion, substr($antes, 0, $verbo[0][1]), $anterior);
        }

        return true;
    }

    // -------------------------------------------------------------------------------------------
    // La carga dada por hecha (misión asistente-carga-prometida-sin-accion, 10/10/2026)
    // -------------------------------------------------------------------------------------------

    /**
     * Las oraciones del texto, sin los renglones de lista ("- ", "* ", "• ", "1. "): ahí van los datos
     * de una consulta ("- Tarea cargada: llamar a Juan"), no lo que el turno hizo. Se cortan los
     * renglones ANTES de partir en oraciones: si no, "1. Gasto registrado…" se partiría en "1." y en
     * una oración que ya no parece de lista.
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    protected static function oraciones_sin_renglones_de_lista($texto)
    {
        $renglones = preg_split('/\r\n|\n/u', (string) $texto);

        if (! is_array($renglones)) {

            return self::oraciones($texto);
        }

        $oraciones = [];

        foreach ($renglones as $renglon) {

            if (trim($renglon) === '' || preg_match('/^\s*(?:[-*•·]|\d+[.)])\s/u', $renglon)) {
                continue;
            }

            foreach (self::oraciones($renglon) as $oracion) {
                $oraciones[] = $oracion;
            }
        }

        return $oraciones;
    }

    /**
     * true si el pedido de la persona trae un pedido de CARGA, aunque venga como pregunta o junto con un
     * pedido de mostrar: "¿Me la pasás al miércoles?", "¿Podés cambiarle el teléfono?", "Cambiale el
     * teléfono, ¿ok?", "Cargá el gasto y mostrame cómo quedó". Ahí lo que pidió es una carga, y un texto
     * que la da por hecha sin que se haya hecho miente.
     *
     * @param  string  $texto        El pedido, tal cual (para el voseo, que se distingue por la tilde).
     * @param  string  $normalizado  El mismo, normalizado.
     * @return bool
     */
    protected static function pide_una_carga($texto, $normalizado)
    {
        return (bool) (preg_match(self::PEDIDO_DE_CARGA_EN_PREGUNTA, $normalizado)
            || preg_match(self::IMPERATIVO_CON_ENCLITICO, $normalizado)
            || preg_match(self::IMPERATIVO_DE_VOSEO, mb_strtolower((string) $texto, 'UTF-8')));
    }

    /**
     * El tipo de carga que afirma UNA oración ('hecho' > 'quedo' > 'promesa' > 'cierre'), o ''.
     * 'cierre' es la oración que es sólo "Listo ✅": afirma_una_carga_hecha() la cuenta como 'quedo'
     * mirando el resto del texto.
     *
     * Cada tipo se busca sobre la versión que le corresponde de la misma oración: 'hecho' y 'promesa'
     * en minúscula CON tildes (la tilde es lo que separa "cargué" de "cargue", y "la cambio" de "la
     * cambió"); 'quedo', en la normalizada. Las dos versiones tienen la misma cantidad de caracteres,
     * así que lo de antes de cada coincidencia se pasa de una a la otra contando caracteres.
     *
     * ⚠️ sin_tarjetas_de_pago() no se aplica a la oración: ningún patrón de la carga mira la palabra
     * "tarjeta", y además la reescribe y correría los desplazamientos. Sí se usa para la oración de
     * antes cuando se pregunta si ésta afirma una tarjeta, igual que en afirma_una_tarjeta().
     *
     * @param  string  $oracion   La oración tal cual.
     * @param  string  $anterior  La oración de antes, normalizada ('' si es la primera).
     * @return string
     */
    protected static function tipo_de_carga_de_la_oracion($oracion, $anterior)
    {
        $minuscula   = mb_strtolower(trim((string) $oracion), 'UTF-8');
        $normalizada = self::normalizar($oracion);

        if ($normalizada === '') {

            return '';
        }

        /*
         * Una oración que afirma una TARJETA no es una carga dada por hecha, aunque use un verbo de carga
         * (chequeo del 10/10/2026): "Te creé la tarjeta del gasto…", "Quedó cargada la tarjeta para que
         * la confirmes", "Cargué el gasto en la tarjeta para que lo confirmes". Ésa la mira la otra
         * familia.
         */
        if (self::oracion_afirma_una_tarjeta($normalizada, self::sin_tarjetas_de_pago($anterior))) {

            return '';
        }

        if (preg_match(self::REPASO_DE_UNA_CARGA, $normalizada)) {

            return '';
        }

        if (self::oracion_afirma_un_hecho($minuscula, $normalizada)) {

            return 'hecho';
        }

        if (self::oracion_afirma_que_quedo($minuscula, $normalizada)) {

            return 'quedo';
        }

        if (self::oracion_promete_una_carga($minuscula, $normalizada, $anterior)) {

            return 'promesa';
        }

        if (preg_match(self::CIERRE, $normalizada)) {

            return 'cierre';
        }

        return '';
    }

    /**
     * Los patrones de 'hecho', sobre la oración en minúscula CON tildes: [patrón del verbo, lo que
     * tiene que venir detrás del verbo (sobre la normalizada) o null si no pide nada].
     *
     * @return array<int, array{0: string, 1: string|null}>
     */
    protected static function patrones_de_hecho()
    {
        $v = self::VENTANA;
        $adverbio = '(?:(?:tambien|ya|recien|ahora|bien)\s+)*';

        return [
            /* "Listo, lo cargué", "Agendé la tarea", "Le cobré $20.000 a Juan", "Di de alta al proveedor". */
            ['/\b' . self::PRETERITO_DE_CARGA . '\b/u', null],
            /* "Marqué la tarea como hecha" — y no "Te marqué en negrita los que vencen hoy". */
            ['/\bmarqué\b/u', '/^' . $v . '{0,40}?\bcomo (?:hech|pagad|cobrad|complet|entregad)/u'],
            /* "Le cambié el teléfono", "Lo cambié a 6000", "La cambié al viernes" — y no "Cambié el orden de la lista". */
            ['/\b(?:cambié|actualicé|modifiqué|edité)\b/u', '/^' . $adverbio . self::OBJETO_DE_UN_CAMBIO . '/u'],
            /* "Borré la categoría Pruebas", "Te creé la tarea" — y no "Creé un resumen con las ventas". */
            ['/\b(?:eliminé|borré|creé)\b/u', '/^' . $adverbio . self::ENTIDAD_DE_CARGA . '/u'],
            /* "Anoté la tarea", "Te lo anoté para el martes" — y no "Te anoté abajo el detalle". */
            ['/\banoté\b/u', '/^(?:' . $adverbio . self::ENTIDAD_DE_CARGA . '|' . $v . '{0,40}?\b(?:en la agenda|para el)\b)/u'],
            /* "La pasé al miércoles", "Te pasé la tarea para el 14/10" — y no "Pasé la tarjeta al cliente". */
            ['/\bpasé\b' . $v . '{0,40}?\b(?:al|para el)\s+(?:lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo|\d|mes\b|pr[oó]xim)/u', null],
            /*
             * "Te la dejé para el miércoles", con la fecha PEGADA al verbo: con ventana, el texto medido en
             * "resuelto" "…y dejé dos tarjetas para pasarlos a ambos al miércoles 14/10/2026" daba 'hecho'.
             */
            ['/\bdejé\s+(?:al|para el)\s+(?:lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo|\d|mes\b|pr[oó]xim)/u', null],
            /* "Te armé el combo", "Armé la oferta" — pegado: "Te armé el resumen: 3 ventas…" no. */
            ['/\barmé\s+(?:(?:el|la|los|las|un|una|tu|su)\s+)?(?:combo|oferta|presupuesto|venta|pedido)s?\b/u', null],
            /* "Hice la venta", "Te hice el cambio" — y no "Hice la cuenta". */
            ['/\bhice\s+(?:la|el)\s+(?:venta|pago|cobro|cambio|carga|alta|baja|movimiento|presupuesto|transferencia)\b/u', null],
            /* "Te dejé agendada la tarea", "Te lo dejé cargado" — "Dejé la tarjeta" es de la otra familia. */
            ['/\bdejé\s+' . self::PARTICIPIO_DE_CARGA . '\b/u', null],
            /* "Emití la factura", "Apliqué el descuento", "Le agregué el teléfono", "Bajé el precio". */
            ['/\bemití\s+(?:la|el)\s+(?:factura|comprobante|nota)/u', null],
            ['/\bapliqué\s+(?:el|la)\s+(?:descuento|recargo|oferta)/u', null],
            ['/\ble\s+(?:agregué|puse|sumé|saqué)\b/u', null],
            ['/\bbajé\s+(?:el|los)\s+precios?\b/u', null],
        ];
    }

    /**
     * Los patrones de 'quedo', sobre la oración normalizada: [patrón, forma]. Las formas tienen sus
     * propias excepciones (ver oracion_afirma_que_quedo()).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected static function patrones_de_quedo()
    {
        $v = self::VENTANA;
        $participio = self::PARTICIPIO_DE_CARGA;

        return [
            /* (a) "Quedó pasada al miércoles", "Ya está cargado", "Listo, la tarea quedó hecha". */
            ['/\b(?:quedo|quedaron|ya esta|ya estan)\b(?P<entre>' . $v . '{0,25}?)\b(?:' . $participio . '|hech[oa]s?)\b/u', 'a'],
            /* (a) "Listo, quedó para el miércoles", "Quedó para el 14/10". */
            ['/\b(?:quedo|quedaron) (?:para|en) el\s+(?:lunes|martes|miercoles|jueves|viernes|sabado|domingo|dia\b|\d|proxim)/u', 'a'],
            /* (b) "Agendada la tarea…", "Listo, agendada.", "✅ Registrado". */
            ['/^' . self::APERTURA . '(?:(?:el|la|los|las) )?' . self::PARTICIPIO_AL_EMPEZAR . '\b/u', 'b'],
            /* (c) "Gasto N° 3 registrado", "Proveedor Distribuidora Norte creado", "Teléfono actualizado". */
            ['/^' . self::APERTURA . '(?:(?:el|la|los|las) )?' . self::QUE_SE_CARGO . '\b(?P<entre>' . $v . '{0,40}?)\b' . $participio . '\b/u', 'c'],
        ];
    }

    /**
     * Los patrones de 'promesa', sobre la oración en minúscula CON tildes: "lo cambio" es una promesa y
     * "lo cambió" (el proveedor, ayer) no; sin tildes se escriben igual.
     *
     * 🔴 ACOTADOS a los verbos y objetos de una carga (chequeo del 10/10/2026): con "paso", "anoto",
     * "marco" o "cambio" sueltos, "Te lo paso en limpio", "Te los paso ordenados", "Te anoto el CBU",
     * "Te lo marco en la lista" o "Te lo cambio a dólares" —que presentan una consulta— daban promesa.
     *
     * @return array<int, string>
     */
    protected static function patrones_de_promesa()
    {
        return [
            /* "Te lo cargo", "Lo registro", "Lo doy de alta", "La borro". */
            '/\b(?:lo|la|los|las)\s+(?:cargo|registro|agendo|doy de alta|doy de baja|borro|elimino)\b/u',
            /* "La marco como hecha…". */
            '/\b(?:lo|la)\s+marco\s+como\s+(?:hech|pagad|cobrad|complet)/u',
            /* "La paso al miércoles", "Lo muevo para el 14/10". */
            '/\b(?:lo|la|los|las)\s+(?:paso|muevo)\s+(?:al|para el)\s+(?:lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo|\d|pr[oó]xim)/u',
            /* "Te cargo el gasto", "Te agendo la tarea". */
            '/\b(?:te|le|les)\s+(?:cargo|registro|agendo)\s+(?:el|la|un|una)\s+(?:gasto|pago|cobro|venta|tarea|recordatorio|presupuesto|compra)/u',
            /* "Le cambio el teléfono". */
            '/\ble\s+cambio\s+(?:el|la)\s+(?:tel[eé]fono|precio|fecha|nombre|direcci[oó]n|mail|stock|categor[ií]a)/u',
        ];
    }

    /**
     * true si la oración afirma un 'hecho' que no está desarmado por lo que tiene antes, y —si el verbo
     * lo pide— con su objeto de carga detrás.
     *
     * @param  string  $minuscula    La oración en minúscula, con tildes.
     * @param  string  $normalizada  La misma, normalizada (tiene la misma cantidad de caracteres).
     * @return bool
     */
    protected static function oracion_afirma_un_hecho($minuscula, $normalizada)
    {
        foreach (self::patrones_de_hecho() as $patron) {

            if (! preg_match_all($patron[0], $minuscula, $coincidencias, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($coincidencias[0] as $coincidencia) {

                $antes_con_tildes = substr($minuscula, 0, $coincidencia[1]);
                $antes            = mb_substr($normalizada, 0, mb_strlen($antes_con_tildes, 'UTF-8'), 'UTF-8');

                /*
                 * El condicional se mira CON tildes: "Sí, lo cargué" y "Sí lo cargué" afirman; "si
                 * cargué mal el monto, avisame" no.
                 */
                if (self::lo_de_antes_desarma_la_carga($antes, $antes_con_tildes)) {
                    continue;
                }

                if (! is_null($patron[1])) {

                    $hasta_el_fin = mb_strlen(substr($minuscula, 0, $coincidencia[1] + strlen($coincidencia[0])), 'UTF-8');
                    $despues      = ltrim(mb_substr($normalizada, $hasta_el_fin, null, 'UTF-8'));

                    if (! preg_match($patron[1], $despues)) {
                        continue;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * true si la oración afirma un 'quedo' que no está desarmado.
     *
     * Para todas las formas: no con una condición de confirmar ("quedó registrado al confirmar"), no si
     * lo de antes la desarma (con el condicional mirado CON tildes: "Listo, ahora sí quedó registrado"
     * afirma, y es la frase típica de un reintento que vuelve a mentir), ni con una pregunta indirecta
     * ("Te muestro cómo quedó cargado"). Además:
     *
     *   - (a) y (c), con algo entre lo que se cargó (o "quedó") y el participio: no con "no", "sin",
     *     "pendiente", "falta" o "a medias" en el medio ("Quedó a medias: no está guardado", "La venta
     *     quedó sin facturar porque no está registrado…"), ni con un "que" (una subordinada: "Los gastos
     *     que quedaron registrados este mes…"). En (c), tampoco con dos puntos en el medio: es un renglón
     *     de reporte ("Las ventas de hoy: 3 cobradas con tarjeta").
     *   - (a): no si la oración habla de algo que YA existía ("desde el lunes", "dos veces", "ya
     *     existe"), ni con un demostrativo antes ("Ese proveedor ya está creado: es el id 421").
     *   - (b) y (c), al empezar la oración: no con un presente o un futuro antes del participio ("El
     *     gasto queda agendado como tarea…", medido en esta rama), ni con el participio seguido de "por
     *     Martín", "el 5/10", "desde" o "al 9/10" (un encabezado de reporte: "Registrado por Martín el
     *     5/10").
     *
     * @param  string  $minuscula    La oración en minúscula, con tildes.
     * @param  string  $normalizada  La misma, normalizada.
     * @return bool
     */
    protected static function oracion_afirma_que_quedo($minuscula, $normalizada)
    {
        if (preg_match(self::AL_CONFIRMAR, $normalizada)) {

            return false;
        }

        foreach (self::patrones_de_quedo() as $patron) {

            if (! preg_match_all($patron[0], $normalizada, $coincidencias, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }

            foreach ($coincidencias as $coincidencia) {

                $inicio           = $coincidencia[0][1];
                $antes            = substr($normalizada, 0, $inicio);
                $antes_con_tildes = mb_substr($minuscula, 0, mb_strlen($antes, 'UTF-8'), 'UTF-8');

                if (self::lo_de_antes_desarma_la_carga($antes, $antes_con_tildes) || preg_match(self::PREGUNTA_INDIRECTA, $antes)) {
                    continue;
                }

                /*
                 * El hueco entre "quedó" —o lo que se cargó— y el participio: "Quedó a medias: no está
                 * guardado", "La venta quedó sin facturar porque no está registrado el punto de venta",
                 * "Los gastos que quedaron registrados este mes…" (una subordinada).
                 */
                if (isset($coincidencia['entre'])
                    && (preg_match(self::ENTRE_QUEDO_Y_PARTICIPIO, $coincidencia['entre'][0])
                        || preg_match('/\bque\b/u', $coincidencia['entre'][0])
                        || ($patron[1] === 'c' && strpos($coincidencia['entre'][0], ':') !== false))) {
                    continue;
                }

                if ($patron[1] === 'a' && (preg_match(self::YA_EXISTIA, $normalizada) || preg_match(self::DEMOSTRATIVO, $antes_con_tildes))) {
                    continue;
                }

                if ($patron[1] === 'b' || $patron[1] === 'c') {

                    if (preg_match(self::PRESENTE_O_FUTURO, $coincidencia[0][0])) {
                        continue;
                    }

                    if (preg_match(self::ENCABEZADO_DE_REPORTE, substr($normalizada, $inicio + strlen($coincidencia[0][0])))) {
                        continue;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * true si la oración promete una carga, con las MISMAS excepciones que la promesa de la tarjeta
     * (la_coincidencia_afirma() con el tipo 'promesa': pregunta en la oración o en la de antes,
     * condicional, "querés que", "puedo", "cuando", "apenas", "con eso", "decime", "pasame"…), y además
     * sin una condición DESPUÉS del verbo ("Lo cargo apenas me pases la caja", "Te lo agendo, pero
     * necesito la fecha").
     *
     * @param  string  $minuscula    La oración en minúscula, con tildes.
     * @param  string  $normalizada  La misma, normalizada.
     * @param  string  $anterior     La oración de antes, normalizada.
     * @return bool
     */
    protected static function oracion_promete_una_carga($minuscula, $normalizada, $anterior)
    {
        foreach (self::patrones_de_promesa() as $patron) {

            if (! preg_match_all($patron, $minuscula, $coincidencias, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($coincidencias[0] as $coincidencia) {

                $hasta_el_inicio = mb_strlen(substr($minuscula, 0, $coincidencia[1]), 'UTF-8');
                $hasta_el_fin    = mb_strlen(substr($minuscula, 0, $coincidencia[1] + strlen($coincidencia[0])), 'UTF-8');

                $antes   = mb_substr($normalizada, 0, $hasta_el_inicio, 'UTF-8');
                $despues = mb_substr($normalizada, $hasta_el_fin, null, 'UTF-8');

                if (preg_match(self::CONDICION_DESPUES, $despues)) {
                    continue;
                }

                if (self::la_coincidencia_afirma('promesa', $normalizada, $antes, $anterior)) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true si lo que hay ANTES de una afirmación de carga, en la misma oración, la desarma: una
     * pregunta que arranca antes ("¿Quedó registrado?"), un condicional ("si cargué mal…"), una duda
     * ("quizás ya quedó hecha"), una negación pegada ("No lo cargué", "Todavía no quedó registrado") o
     * de cantidad ("Ningún gasto quedó registrado hoy"), o una subordinada que describe ("el gasto que
     * te cargué recién…").
     *
     * @param  string  $antes         Lo de antes, normalizado.
     * @param  string  $antes_del_si  Lo de antes CON tildes, donde se busca el condicional "si".
     * @return bool
     */
    protected static function lo_de_antes_desarma_la_carga($antes, $antes_del_si)
    {
        if (strpos($antes, '¿') !== false) {

            return true;
        }

        /* "si" + palabra, o "si" pegado al verbo: "Si cargué mal el monto, avisame". Con tildes, "sí" no. */
        if (preg_match('/\bsi\s+(?:\w|$)/u', $antes_del_si)) {

            return true;
        }

        return (bool) (preg_match(self::DUDA, $antes)
            || preg_match(self::NEGACION_PEGADA, $antes)
            || preg_match(self::NEGACION_DE_CANTIDAD, $antes)
            || preg_match(self::RELATIVO, $antes));
    }

    /**
     * Saca de la oración las "tarjetas" que son un medio de pago o un producto: "ventas con tarjeta",
     * "el recargo de la tarjeta", "tarjeta de crédito", "tarjetas SUBE". Las tarjetas del asistente
     * quedan como estaban.
     *
     * @param  string  $oracion  Normalizada.
     * @return string
     */
    protected static function sin_tarjetas_de_pago($oracion)
    {
        /*
         * "con (la) tarjeta", "por tarjeta", "de la tarjeta" (el recargo de la tarjeta) y "en tarjeta"
         * SIN artículo (pagó en tarjeta). "en LA tarjeta" no: "tocá Confirmar en la tarjeta" es la del
         * asistente.
         */
        $oracion = preg_replace('/\b(?:(?:con|por|sin|de)\s+(?:la |las |una |tu |tus |su |sus )?|en\s+)tarjetas?\b/u', ' medio_de_pago', $oracion);

        return (string) preg_replace('/\btarjetas?\s+(?:de credito|de debito|credito|debito|naranja|visa|master\w*|amex|american|cabal|sube|de memoria|de regalo|de puntos|de descuentos?|de fidelidad|de socio|de presentacion|prepagas?)\b/u', 'medio_de_pago', (string) $oracion);
    }

    /**
     * Minúscula y sin tildes, para que "Dejé", "deje" y "DEJÉ" sean lo mismo.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar($texto)
    {
        $minuscula = mb_strtolower(trim((string) $texto), 'UTF-8');

        return strtr($minuscula, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
    }
}
