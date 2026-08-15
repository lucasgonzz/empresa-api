<?php

namespace App\Jobs;

use App\Models\OfferSuggestion;
use App\Models\OfferSuggestionLine;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\OfertasClientes\OfertaSugeridaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Arma los lotes de una corrida del motor de ofertas y procesa cada uno en el mismo job (mismo patrón
 * que GeneratePurchaseSuggestionChunksJob): no depende de que haya varios workers, así que llamarlo
 * con ->handle() desde el controller alcanza para que una corrida chica termine dentro del mismo
 * request. 🔴 Ese camino sincrónico no es un lujo: en WAMP casi nunca hay un queue:work corriendo y
 * sin él una sugerencia manual quedaba 'pendiente' para siempre.
 * 🔴 LOS LOTES SON DE CLIENTES, NO DE ARTÍCULOS, al revés que el molde: el tope
 * max_ofertas_por_cliente se aplica por cliente, así que un cliente entero tiene que caer en un solo
 * lote (si no, dos jobs tendrían que coordinarse para no pasarse). Ver OfertaSugeridaService.
 */
class GenerateOfferSuggestionChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Reintentos antes de marcar la corrida como fallida */
    public $tries = 3;

    /**
     * Clientes por lote. Más chico que el chunk de artículos del molde (5000) porque cada cliente
     * arrastra hasta max_ofertas_por_cliente líneas y cada una resuelve su precio con la cascada
     * completa de ArticlePricesHelper.
     */
    const CLIENTES_POR_CHUNK = 200;

    /** @var int Identificador de la corrida a procesar (offer_suggestions.id) */
    protected $offer_suggestion_id;

    /** @param int $offer_suggestion_id */
    public function __construct($offer_suggestion_id)
    {
        $this->offer_suggestion_id = $offer_suggestion_id;
    }

    /**
     * Arma los lotes, persiste total_chunks y ejecuta cada lote de forma secuencial.
     * @return void
     */
    public function handle()
    {
        $suggestion = OfferSuggestion::find($this->offer_suggestion_id);
        if (!$suggestion) {
            return;
        }

        // 🔴 Re-entrada limpia ANTES de calcular total_chunks: con $tries = 3, un reintento tras un
        // fallo parcial re-inserta desde cero en vez de duplicar lo que la corrida anterior alcanzó
        // a escribir. Si esto se moviera abajo del cálculo, un retry dejaría dos juegos de líneas
        // para el mismo par (cliente, artículo) y la vista mostraría la misma oferta dos veces.
        OfferSuggestionLine::where('offer_suggestion_id', $suggestion->id)->delete();

        $suggestion->update([
            'processed_chunks'  => 0,
            'status'            => 'pendiente',
            'error_mensaje'     => null,
            'resumen_ia'        => null,
            'resumen_ia_estado' => null,
            'resumen_ia_error'  => null,
            // Los tres informativos también se resetean acá y no solo al cerrar: sin esto, una
            // corrida re-ejecutada que termina vacía seguiría mostrando los totales de la anterior.
            'total_clientes'                     => null,
            'total_lineas'                       => null,
            'total_clientes_excluidos_por_deuda' => null,
        ]);

        $service = new OfertaSugeridaService($suggestion);

        // 🔴 Los criterios corren UNA sola vez por corrida, acá: son las consultas caras del motor
        // (article_purchases entera, tres veces) y llamarlas adentro de cada lote las multiplicaría
        // por la cantidad de lotes. Cada lote recibe su pedazo ya calculado.
        $candidatos = $service->candidatos();
        $evaluacion = $service->evaluacion_crediticia();
        $excluidos  = $service->clientes_excluidos_por_deuda();

        $lotes       = $this->armar_lotes_por_cliente($candidatos);
        $chunk_count = count($lotes);

        // total_chunks antes de procesar evita condiciones de carrera al marcar terminado.
        $suggestion->update(['total_chunks' => $chunk_count]);

        if ($chunk_count === 0) {
            // Corrida sin un solo candidato (comercio sin historial, o todos sus clientes excluidos
            // por deuda). Cierra igual, con los totales en cero y el conteo de excluidos cargado: es
            // la única explicación que va a tener el comerciante de por qué la tabla está vacía. No se
            // notifica —igual que el molde— porque un aviso de "listo" sobre una corrida sin nada
            // adentro es ruido, y la vista la está polleando y ve el 'terminado'.
            $suggestion->update([
                'status'                             => 'terminado',
                'total_clientes'                     => 0,
                'total_lineas'                       => 0,
                'total_clientes_excluidos_por_deuda' => $excluidos,
            ]);

            return;
        }

        foreach ($lotes as $lote) {
            (new ProcessOfferSuggestionChunkJob($lote, $suggestion->id, $evaluacion, $excluidos))->handle();
        }
    }

    /**
     * Agrupa los candidatos por cliente y reparte CLIENTES ENTEROS en lotes: ninguna línea de un
     * cliente puede caer en un lote distinto del resto de sus líneas.
     * @param  array $candidatos
     * @return array Lista de lotes, cada uno una lista de candidatos
     */
    protected function armar_lotes_por_cliente(array $candidatos)
    {
        $por_cliente = [];
        foreach ($candidatos as $candidato) {
            $por_cliente[$candidato['client_id']][] = $candidato;
        }

        $lotes = [];
        // true para preservar las claves: array_chunk sobre un array asociativo las tira, y acá la
        // clave es el client_id.
        foreach (array_chunk($por_cliente, self::CLIENTES_POR_CHUNK, true) as $grupo) {
            $lote = [];
            foreach ($grupo as $lineas_del_cliente) {
                $lote = array_merge($lote, $lineas_del_cliente);
            }
            $lotes[] = $lote;
        }

        return $lotes;
    }

    /**
     * Marca la corrida como fallida cuando el job agotó sus reintentos: sin esto, quedaba 'pendiente'
     * para siempre y el usuario esperando un resultado que no iba a llegar.
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('GenerateOfferSuggestionChunksJob: failed()',
            ['offer_suggestion_id' => $this->offer_suggestion_id, 'message' => $exception->getMessage()]);

        $suggestion = OfferSuggestion::find($this->offer_suggestion_id);
        // Si por alguna carrera ya quedó terminada, no se pisa con error.
        if (!$suggestion || $suggestion->status === 'terminado') {
            return;
        }

        $suggestion->status = 'error';
        $suggestion->error_mensaje = $exception->getMessage();
        $suggestion->save();

        $user = User::find($suggestion->user_id);
        if (!$user) {
            return;
        }

        $user->notify(new GlobalNotification([
            'message_text'          => 'La corrida de ofertas falló y quedó marcada con error',
            'color_variant'         => 'danger',
            'functions_to_execute'  => [['btn_text' => 'Entendido', 'btn_variant' => 'primary']],
            'info_to_show'          => [],
            'owner_id'              => $user->id,
            'is_only_for_auth_user' => false,
        ]));
    }
}
