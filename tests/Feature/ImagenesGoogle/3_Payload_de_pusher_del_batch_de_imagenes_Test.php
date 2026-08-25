<?php

namespace Tests\Feature\ImagenesGoogle;

use App\Events\ArticleBatchImagesProcessed;
use Tests\TestCase;

/**
 * Feature tests del payload que ArticleBatchImagesProcessed manda por Pusher al terminar un
 * lote de asignacion automatica de imagenes.
 *
 * Protege otra falla silenciosa que solo aparece con volumen: si alguien vuelve a meter un
 * array por articulo en broadcastWith(), el job de un cliente con 200 articulos revienta AL
 * FINAL de todo el procesamiento -despues de haber quemado la cuota diaria de Google y de
 * validar cada imagen con IA- con una BroadcastException de Pusher (limite de 10240 bytes por
 * evento), y el cliente no llega a ver ningun resumen. Con 40 articulos ya pasa (confirmado en
 * logs reales, 25/8/2026).
 *
 * No toca la base: el evento se arma en memoria con datos de mentira y se le llama
 * broadcastWith() directo, sin pasar por el job ni por Pusher.
 */
class Payload_de_pusher_del_batch_de_imagenes_Test extends TestCase
{
    /**
     * Arma el evento con `$cantidad_de_articulos` artículos "sin imagen" y "a revisar", con
     * nombres y resúmenes largos a propósito (mismo orden de magnitud que un artículo real, ver
     * los logs del 25/8/2026 con nombres de 80+ caracteres).
     *
     * Los contadores escalares van FIJOS y distintos entre sí (no derivados de
     * `$cantidad_de_articulos`), a propósito: así un test que compara dos corridas de distinto
     * tamaño puede pedir igualdad exacta de bytes (el JSON de los escalares no cambia), y un
     * test que compara claves contra valores puede detectar si `broadcastWith()` intercambiara
     * dos contadores entre sí.
     *
     * @param int $cantidad_de_articulos
     * @return \App\Events\ArticleBatchImagesProcessed
     */
    protected function evento_con(int $cantidad_de_articulos): ArticleBatchImagesProcessed
    {
        $skipped_names          = [];
        $skipped_items          = [];
        $needs_review_items     = [];
        $skipped_by_quota_names = [];

        for ($i = 1; $i <= $cantidad_de_articulos; $i++) {
            $nombre = 'MODULO HUSQVARNA (BOBINA) 236/235E/240/136/137 POULAN 295/2600/2750/2775 #'.$i;
            $resumen = str_repeat('Se buscó por código de barras y Google no devolvió ninguna imagen. ', 3);

            $skipped_names[] = $nombre;
            $skipped_items[] = [
                'article_id' => $i,
                'name'       => $nombre,
                'summary'    => $resumen,
            ];
            $needs_review_items[] = [
                'article_id' => $i,
                'name'       => $nombre,
                'image_url'  => 'https://storage.example.com/articles/'.$i.'/imagen-revision.webp',
            ];
            $skipped_by_quota_names[] = $nombre;
        }

        return new ArticleBatchImagesProcessed(
            500,
            71,
            3,
            $skipped_names,
            13,
            $needs_review_items,
            true,
            29,
            $skipped_by_quota_names,
            'batch-uuid-de-prueba',
            $skipped_items
        );
    }

    /**
     * @test
     * @group imagenes-google
     */
    public function el_shape_viejo_con_400_articulos_de_verdad_supera_el_limite_de_pusher()
    {
        // Reconstruye a mano el payload de ANTES del fix (los 4 arrays completos + los
        // contadores), leyendo las properties públicas del evento, que siguen existiendo. Esto
        // es lo que hace que el test de abajo reproduzca el bug de verdad: si esta aserción no
        // se cumpliera, el test siguiente estaría "pasando" sin haber probado nada.
        $evento = $this->evento_con(400);

        $shape_viejo = [
            'processed'              => $evento->processed,
            'skipped'                => $evento->skipped,
            'skipped_names'          => $evento->skipped_names,
            'needs_review'           => $evento->needs_review,
            'needs_review_items'     => $evento->needs_review_items,
            'quota_reached'          => $evento->quota_reached,
            'skipped_by_quota'       => $evento->skipped_by_quota,
            'skipped_by_quota_names' => $evento->skipped_by_quota_names,
            'batch_uuid'             => $evento->batch_uuid,
            'skipped_items'          => $evento->skipped_items,
        ];

        $this->assertGreaterThan(10240, strlen(json_encode($shape_viejo)));
    }

    /**
     * @test
     * @group imagenes-google
     */
    public function un_lote_de_400_articulos_no_supera_el_limite_de_pusher()
    {
        $evento = $this->evento_con(400);

        $tamano = strlen(json_encode($evento->broadcastWith()));

        $this->assertLessThan(10240, $tamano);
    }

    /**
     * @test
     * @group imagenes-google
     */
    public function el_tamano_del_payload_no_depende_de_la_cantidad_de_articulos()
    {
        $tamano_chico  = strlen(json_encode($this->evento_con(5)->broadcastWith()));
        $tamano_grande = strlen(json_encode($this->evento_con(400)->broadcastWith()));

        // Igualdad exacta: los contadores de evento_con() son fijos y no dependen de la
        // cantidad de artículos, así que el JSON de broadcastWith() tiene que ser byte a byte
        // el mismo aunque el lote pase de 5 a 400 artículos.
        $this->assertSame($tamano_chico, $tamano_grande);
    }

    /**
     * @test
     * @group imagenes-google
     */
    public function el_payload_no_lleva_los_arrays_por_articulo()
    {
        $payload = $this->evento_con(10)->broadcastWith();

        $this->assertArrayNotHasKey('skipped_names', $payload);
        $this->assertArrayNotHasKey('skipped_items', $payload);
        $this->assertArrayNotHasKey('needs_review_items', $payload);
        $this->assertArrayNotHasKey('skipped_by_quota_names', $payload);
    }

    /**
     * @test
     * @group imagenes-google
     */
    public function el_payload_lleva_los_contadores_y_el_batch_uuid()
    {
        $payload = $this->evento_con(3)->broadcastWith();

        $claves = array_keys($payload);
        sort($claves);

        $this->assertEquals(
            ['batch_uuid', 'needs_review', 'processed', 'quota_reached', 'skipped', 'skipped_by_quota'],
            $claves
        );

        // Los cuatro contadores son valores distintos entre sí (ver evento_con()): si
        // broadcastWith() intercambiara dos claves, alguno de estos asserts lo detecta.
        $this->assertSame(71, $payload['processed']);
        $this->assertSame(3, $payload['skipped']);
        $this->assertSame(13, $payload['needs_review']);
        $this->assertSame(true, $payload['quota_reached']);
        $this->assertSame(29, $payload['skipped_by_quota']);
        $this->assertSame('batch-uuid-de-prueba', $payload['batch_uuid']);
    }
}
