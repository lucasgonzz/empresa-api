<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Evento Pusher que notifica al frontend el resultado del batch de generacion de
 * descripciones inteligentes (ProcessArticleBatchDescriptionsJob). Misma estructura que
 * ArticleBatchImagesProcessed, con contadores propios de este flujo.
 */
class ArticleBatchDescriptionsProcessed implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * 🔴 Límite duro de Pusher para el payload de un evento. Superarlo no degrada nada: la
     * llamada falla con BroadcastException y, como este evento se emite AL FINAL del job, el
     * usuario se queda sin ningún aviso después de haber quemado la cuota diaria de Google.
     * Es el mismo bug que ya se midió en el batch de imágenes con 40 artículos.
     */
    const LIMITE_DE_PUSHER_BYTES = 10240;

    /**
     * Presupuesto de bytes que se le concede a CADA una de las dos listas de nombres que el
     * payload todavía transporta.
     *
     * La cuenta del techo: 2 listas × 3500 + ~350 bytes de contadores, claves y uuid = ~7350,
     * casi 3000 bytes por debajo del límite. Y el piso útil: con nombres de artículo reales
     * entran unos 70 por lista, así que un lote del tamaño que se encola desde el listado viaja
     * entero y el usuario no ve ningún recorte. Bajarlo a 2500 ya recortaba un lote de 40
     * artículos con nombres acentuados, que es un caso corriente.
     *
     * Cada lista tiene su propio presupuesto en vez de compartir uno solo a propósito: con un
     * presupuesto compartido, un lote con muchos salteados por descripción existente dejaría a
     * la otra lista sin un solo nombre.
     */
    const PRESUPUESTO_POR_LISTA_BYTES = 3500;

    public $user_id;
    public $processed;
    public $skipped;
    public $skipped_names;
    public $skipped_existing;
    public $skipped_existing_names;
    public $needs_review;
    public $needs_review_items;
    public $quota_reached;
    public $skipped_by_quota;
    public $skipped_by_quota_names;

    /**
     * @var string UUID de la corrida del job. Es lo único que le permite al frontend reconocer
     * SU lote entre los que pasan por el canal (ver el comentario de broadcastOn()).
     */
    public $batch_uuid;

    /**
     * @param int    $user_id                  ID del usuario dueño.
     * @param int    $processed                Artículos con descripción generada y ya publicable (confianza high/medium).
     * @param int    $skipped                  Artículos intentados sin poder generar nada (sin evidencia o found=false).
     * @param array  $skipped_names            Nombres de los artículos sin descripción generada.
     * @param int    $skipped_existing         Artículos salteados por ya tener descripciones cargadas a mano.
     * @param array  $skipped_existing_names   Nombres de los artículos salteados por descripciones humanas existentes.
     * @param int    $needs_review             Artículos con descripción de confianza low, guardada pero no publicada.
     * @param array  $needs_review_items       Detalle de los artículos pendientes de revisión (id, nombre, ids de description, preview).
     * @param bool   $quota_reached            Si el procesamiento se cortó por alcanzar la cuota diaria de Google.
     * @param int    $skipped_by_quota         Artículos sin procesar por cuota agotada.
     * @param array  $skipped_by_quota_names   Nombres de los artículos sin procesar por cuota agotada.
     * @param string $batch_uuid               UUID de la corrida, para que el frontend distinga su lote del de otro.
     */
    public function __construct(
        $user_id,
        $processed,
        $skipped,
        array $skipped_names,
        $skipped_existing,
        array $skipped_existing_names,
        $needs_review,
        array $needs_review_items,
        $quota_reached,
        $skipped_by_quota,
        array $skipped_by_quota_names,
        $batch_uuid = ''
    ) {
        $this->user_id                = $user_id;
        $this->processed              = $processed;
        $this->skipped                = $skipped;
        $this->skipped_names          = $skipped_names;
        $this->skipped_existing       = $skipped_existing;
        $this->skipped_existing_names = $skipped_existing_names;
        $this->needs_review           = $needs_review;
        $this->needs_review_items     = $needs_review_items;
        $this->quota_reached          = $quota_reached;
        $this->skipped_by_quota       = $skipped_by_quota;
        $this->skipped_by_quota_names = $skipped_by_quota_names;
        /*
         * 🔴 El parámetro va SIN type hint `string` y con `(string)` acá, aunque el evento
         * gemelo de imágenes lo tipe: el job que construye este evento puede venir de la cola
         * de ANTES de este deploy, deserializado sin la property nueva. Ese job ya tiene su
         * propio guard por veracidad, pero un `string $batch_uuid` volvería a hacer que
         * cualquier null que se le escape reviente con TypeError en PHP 7.4 recién al final de
         * la corrida, con la cuota de Google ya gastada. El cast no puede fallar; el type hint sí.
         */
        $this->batch_uuid             = (string) $batch_uuid;
    }

    /**
     * Canal EXACTO al que se emite (uno por usuario, igual que el de imágenes).
     *
     * 🔴 Es un canal PÚBLICO y se nombra por el id del owner, así que dos instalaciones que
     * compartan ese id sobre la misma app de Pusher están literalmente en el mismo canal y cada
     * una recibe los eventos de la otra. Por eso el payload lleva `batch_uuid`: sin él, la
     * pestaña acepta el primer evento que pasa, se da de baja del canal y se queda esperando
     * para siempre el suyo. Lo mismo con dos lotes seguidos del mismo usuario.
     */
    public function broadcastOn()
    {
        Log::info('Broadcast ArticleBatchDescriptionsProcessed', [
            'channel' => 'article_batch_descriptions.'.$this->user_id,
        ]);

        return new Channel('article_batch_descriptions.'.$this->user_id);
    }

    /**
     * Nombre del evento (esto es CLAVE para Vue).
     */
    public function broadcastAs()
    {
        return 'ArticleBatchDescriptionsProcessed';
    }

    /**
     * Payload que recibe el frontend por Pusher.
     *
     * 🔴 Este método existe para que el tamaño del payload NO dependa de cuántos artículos
     * tenga el lote. Antes mandaba las cuatro listas por artículo enteras
     * (`skipped_names`, `needs_review_items`, `skipped_existing_names`,
     * `skipped_by_quota_names`) y con un lote grande se pasaba de los 10240 bytes de Pusher:
     * el job terminaba en BroadcastException al final de todo, después de haber gastado la
     * cuota diaria de Google. Medido en el batch de imágenes, que tenía el mismo defecto.
     *
     * Dos de esas cuatro listas se sacaron del payload porque NADIE las mostraba (verificado
     * en el SPA el 28/8/2026):
     *
     * - `needs_review_items`: `BatchDescriptionsSummaryModal` se lo pasa a
     *   `AiDescriptionsReviewModal` como prop `initial_items`, y ese modal no la lee nunca:
     *   al abrirse pide la bandeja completa a `GET article-description-ai/pending-review`,
     *   que es la única fuente que tiene el título, el contenido y las fuentes editables.
     * - `skipped_by_quota_names`: el modal de descripciones muestra solo el contador
     *   `skipped_by_quota`. (El de IMÁGENES sí lista esos nombres, por eso la property se
     *   mantiene acá y solo se saca del broadcast.)
     *
     * Las otras dos SÍ se listan en el modal, así que se siguen mandando pero recortadas a un
     * presupuesto de bytes, con el total al lado (`*_names_total`) para que el modal pueda
     * decir cuántos nombres no entraron en vez de mostrar una lista corta sin avisar.
     *
     * Las properties completas quedan disponibles igual: son el contrato en proceso del
     * evento, no solo el de Pusher. No borrarlas "porque no se usan".
     *
     * Prohibido volver a meter acá cualquier campo cuyo tamaño crezca sin techo con la
     * cantidad de artículos del lote: es exactamente el bug que este método arregla.
     */
    public function broadcastWith()
    {
        $payload = [
            'processed'                    => $this->processed,
            'skipped'                      => $this->skipped,
            'skipped_names'                => $this->recortar_para_el_payload($this->skipped_names),
            'skipped_names_total'          => count($this->skipped_names),
            'skipped_existing'             => $this->skipped_existing,
            'skipped_existing_names'       => $this->recortar_para_el_payload($this->skipped_existing_names),
            'skipped_existing_names_total' => count($this->skipped_existing_names),
            'needs_review'                 => $this->needs_review,
            'quota_reached'                => $this->quota_reached,
            'skipped_by_quota'             => $this->skipped_by_quota,
            'batch_uuid'                   => $this->batch_uuid,
        ];

        /*
         * Red de seguridad, no control de flujo: con los presupuestos de arriba el payload no
         * puede llegar al límite, así que si esto se dispara alguna vez es porque alguien volvió
         * a meter un campo que crece con el lote. Se deja en el log en lugar de recortar a lo
         * bruto para que el motivo quede escrito y no haya que deducirlo de un
         * BroadcastException pelado.
         */
        $tamanio = strlen((string) json_encode($payload));

        if ($tamanio > self::LIMITE_DE_PUSHER_BYTES) {
            Log::warning('[DescripcionesIA] El payload del broadcast se pasó del límite de Pusher.', [
                'bytes'      => $tamanio,
                'limite'     => self::LIMITE_DE_PUSHER_BYTES,
                'batch_uuid' => $this->batch_uuid,
            ]);
        }

        return $payload;
    }

    /**
     * Devuelve los primeros nombres de la lista que entran en PRESUPUESTO_POR_LISTA_BYTES.
     *
     * 🔴 El costo se mide sobre el nombre YA CODIFICADO como JSON y con strlen (bytes), no con
     * mb_strlen (caracteres). No es una sutileza: json_encode escapa cada acento como `á`,
     * que son 6 bytes por carácter. Un tope contado en caracteres daría una cota que un lote de
     * nombres acentuados rompe, y romper la cota es volver al BroadcastException.
     *
     * @param  array $nombres Lista completa de nombres de artículo.
     * @return array Prefijo de la lista que entra en el presupuesto.
     */
    private function recortar_para_el_payload(array $nombres)
    {
        $recortados = [];
        $usados     = 0;

        foreach ($nombres as $nombre) {
            $codificado = json_encode((string) $nombre);

            // Un nombre con UTF-8 inválido no se puede codificar: se saltea. Dejarlo pasar
            // haría fallar el json_encode del payload entero, que es peor que perder un nombre.
            if ($codificado === false) {
                continue;
            }

            // +1 por la coma que separa este elemento del anterior dentro del array JSON.
            $costo = strlen($codificado) + 1;

            if (($usados + $costo) > self::PRESUPUESTO_POR_LISTA_BYTES) {
                break;
            }

            $usados      += $costo;
            $recortados[] = (string) $nombre;
        }

        return $recortados;
    }
}
