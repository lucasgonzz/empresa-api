<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\providerOrder\FacturaDeCompraHelper;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lista —y con `--aplicar`, borra— las facturas de compra huérfanas y las alícuotas huérfanas
 * (misión `factura-compra-tres-defectos`, 9/10/2026).
 *
 * ─── De dónde salen ───────────────────────────────────────────────────────────────────────────
 *
 * `provider_order_afip_tickets` y `provider_order_afip_ticket_ivas` no tienen ninguna clave
 * foránea, y `ProviderOrder` es borrado duro. Hasta esta misión:
 *
 *  1. Borrar una compra dejaba vivas sus facturas (y las alícuotas de esas facturas).
 *  2. Una factura cargada adentro de una compra NUEVA que después no se guardaba quedaba con
 *     `provider_order_id` NULL para siempre (nace con `temporal_id` y la ata a la compra
 *     `updateRelationsCreated` recién al guardarla). Lo mismo una alícuota cargada adentro de una
 *     factura nueva que no se guardó.
 *  3. El modo "sin factura", las facturas sobrantes del modo automático y el destroy de una
 *     factura borraban la factura sola: sus alícuotas quedaban colgando.
 *
 * El código nuevo ya no deja ninguna (todas las bajas pasan por
 * `FacturaDeCompraHelper::borrar_facturas()`), y los reportes ya no suman las facturas huérfanas
 * (`ProviderOrderAfipTicket::scopeDeCompraExistente()`). Lo que queda son las que ya están en las
 * bases de los clientes: este comando las limpia.
 *
 * ─── Qué cuenta como huérfana ─────────────────────────────────────────────────────────────────
 *
 *  - Factura: su `provider_order_id` apunta a una compra que no existe, o es NULL y la factura
 *    tiene más de 24 horas (o no tiene `created_at`, que es lo mismo que ser vieja). Una factura
 *    sin compra de menos de 24 horas puede ser la de una compra que alguien está cargando AHORA:
 *    esa no se toca.
 *  - Alícuota: su `provider_order_afip_ticket_id` apunta a una factura que no existe, o es NULL y
 *    tiene más de 24 horas, por el mismo motivo.
 *
 * ─── Por dueño, y las alícuotas aparte ────────────────────────────────────────────────────────
 *
 * Las facturas se recorren SOLO por dueño (`users.owner_id` NULL): es la misma columna `user_id`
 * por la que las leen la Posición Fiscal y el rendimiento del mes, así que el reporte dice exactamente cuánto
 * IVA crédito y cuántas percepciones le estaban sumando de más a cada dueño. En una base
 * compartida (varios comercios en la misma base) cada uno ve lo suyo.
 *
 * Las alícuotas huérfanas NO tienen dueño: la tabla no tiene `user_id` y su factura, por
 * definición, no está. Se informan en un renglón aparte, de toda la base. No suman en ningún
 * reporte (todos leen la factura, no el desglose), así que son basura inofensiva; se borran
 * para que la base quede prolija. Las alícuotas de las facturas huérfanas que SÍ existen se van
 * junto con su factura, por `FacturaDeCompraHelper::borrar_facturas()`, y se cuentan con ella.
 *
 * ─── Los dos modos ────────────────────────────────────────────────────────────────────────────
 *
 *     php artisan facturas-de-compra:huerfanas             # solo lista, no escribe nada
 *     php artisan facturas-de-compra:huerfanas --aplicar   # borra
 *
 * Con `--aplicar` cada dueño va en su propia transacción: una corrida cortada deja dueños
 * terminados y dueños intactos, y se puede volver a correr (lo ya borrado no vuelve a aparecer).
 *
 * Lo que NO toca: `retenciones_sufridas.provider_order_afip_ticket_id`. Esa tabla ya prevé que la
 * factura de origen se haya borrado (ver `FacturaDeCompraHelper::borrar_facturas()`).
 *
 * ⚠️ No corre solo: es una tarea manual para Lucas, cliente por cliente, después del release que
 * trae esta misión. Primero sin `--aplicar`, para ver cuánto movía de la Posición Fiscal.
 */
class FacturasDeCompraHuerfanas extends Command
{
    /**
     * @var string
     */
    protected $signature = 'facturas-de-compra:huerfanas
                            {--aplicar : Borra las facturas y alícuotas huérfanas. Sin esta opción solo lista.}';

    /**
     * @var string
     */
    protected $description = 'Lista (y con --aplicar borra) las facturas de compra cuya compra no existe y las alícuotas cuya factura no existe';

    /** Horas que tiene que tener una factura o alícuota sin padre para contar como huérfana. */
    const HORAS_DE_GRACIA = 24;

    /**
     * @return int
     */
    public function handle()
    {
        $aplicar = (bool) $this->option('aplicar');

        $limite = Carbon::now()->subHours(self::HORAS_DE_GRACIA);

        $this->info($aplicar
            ? 'Modo --aplicar: se BORRAN las facturas y alícuotas huérfanas.'
            : 'Solo lectura: no se escribe nada. Para borrar, volvé a correr con --aplicar.');

        $this->line('Huérfana = compra inexistente, o sin compra y creada antes de '.$limite->format('d/m/Y H:i').'.');
        $this->line('');

        $duenos = User::whereNull('owner_id')->orderBy('id')->get(['id', 'company_name', 'name']);

        $total_facturas = 0;
        $filas = [];

        foreach ($duenos as $dueno) {

            $ids = $this->ids_de_facturas_huerfanas($dueno->id, $limite);

            if (count($ids) === 0) {
                continue;
            }

            $resumen = $this->resumen_de_facturas($ids);

            $filas[] = [
                $dueno->id,
                $this->nombre_del_dueno($dueno),
                $resumen['compra_borrada'],
                $resumen['sin_compra'],
                $resumen['alicuotas'],
                $this->plata($resumen['total_iva']),
                $this->plata($resumen['percepcion_iva']),
                $this->plata($resumen['percepcion_iibb']),
            ];

            $total_facturas += count($ids);

            if ($aplicar) {

                /*
                 * Una transacción por dueño. Los ids se vuelven a pedir ADENTRO de la transacción:
                 * entre el listado y este punto alguien pudo haber guardado la compra que estaba
                 * cargando (y la factura dejó de ser huérfana).
                 */
                DB::transaction(function () use ($dueno, $limite) {
                    FacturaDeCompraHelper::borrar_facturas($this->ids_de_facturas_huerfanas($dueno->id, $limite));
                });
            }
        }

        if (count($filas) === 0) {
            $this->info('Ningún dueño tiene facturas de compra huérfanas.');
        } else {
            $this->table(
                ['Dueño', 'Nombre', 'Compra borrada', 'Sin compra', 'Sus alícuotas', 'IVA crédito', 'Percep. IVA', 'Percep. IIBB'],
                $filas
            );
            $this->line(($aplicar ? 'Facturas borradas: ' : 'Facturas huérfanas: ').$total_facturas.
                        ' (con sus alícuotas). IVA crédito y percepciones son lo que estaban sumando de más en la Posición Fiscal.');
        }

        $this->line('');

        /* Alícuotas sin factura: de toda la base, no tienen dueño (ver el encabezado). */
        $alicuotas_sin_factura = $this->query_alicuotas_huerfanas($limite)->count();

        if ($alicuotas_sin_factura === 0) {
            $this->info('No hay alícuotas huérfanas (sin factura).');
        } else {
            $this->line(($aplicar ? 'Alícuotas sin factura borradas: ' : 'Alícuotas sin factura (de toda la base, no tienen dueño): ').$alicuotas_sin_factura);

            if ($aplicar) {
                $this->query_alicuotas_huerfanas($limite)->delete();
            }
        }

        if (!$aplicar && ($total_facturas > 0 || $alicuotas_sin_factura > 0)) {
            $this->line('');
            $this->warn('No se borró nada. Para borrar: php artisan facturas-de-compra:huerfanas --aplicar');
        }

        return 0;
    }

    /**
     * Ids de las facturas huérfanas de un dueño: compra inexistente, o sin compra y viejas.
     *
     * @param  int  $user_id
     * @param  \Carbon\Carbon  $limite
     * @return array<int,int>
     */
    protected function ids_de_facturas_huerfanas($user_id, $limite)
    {
        return ProviderOrderAfipTicket::where('user_id', $user_id)
                    ->where(function ($q) use ($limite) {

                        // La compra a la que apunta no existe.
                        $q->where(function ($q) {
                            $q->whereNotNull('provider_order_afip_tickets.provider_order_id')
                              ->whereNotExists(function ($sub) {
                                  $sub->select(DB::raw(1))
                                      ->from('provider_orders')
                                      ->whereColumn('provider_orders.id', 'provider_order_afip_tickets.provider_order_id');
                              });
                        })
                        // O nunca llegó a colgar de una compra, y ya pasaron las horas de gracia.
                        ->orWhere(function ($q) use ($limite) {
                            $q->whereNull('provider_order_afip_tickets.provider_order_id')
                              ->where(function ($q) use ($limite) {
                                  $q->whereNull('provider_order_afip_tickets.created_at')
                                    ->orWhere('provider_order_afip_tickets.created_at', '<', $limite);
                              });
                        });
                    })
                    ->orderBy('id')
                    ->pluck('id')
                    ->all();
    }

    /**
     * Cuántas son de cada clase, cuántas alícuotas arrastran y cuánto suman de lo que lee la
     * Posición Fiscal.
     *
     * @param  array<int,int>  $ids
     * @return array
     */
    protected function resumen_de_facturas(array $ids)
    {
        $fila = ProviderOrderAfipTicket::whereIn('id', $ids)
                    ->selectRaw('SUM(CASE WHEN provider_order_id IS NULL THEN 0 ELSE 1 END) as compra_borrada')
                    ->selectRaw('SUM(CASE WHEN provider_order_id IS NULL THEN 1 ELSE 0 END) as sin_compra')
                    ->selectRaw('COALESCE(SUM(total_iva), 0) as total_iva')
                    ->selectRaw('COALESCE(SUM(percepcion_iva), 0) as percepcion_iva')
                    ->selectRaw('COALESCE(SUM(percepcion_iibb), 0) as percepcion_iibb')
                    ->first();

        return [
            'compra_borrada'  => (int) $fila->compra_borrada,
            'sin_compra'      => (int) $fila->sin_compra,
            'alicuotas'       => ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $ids)->count(),
            'total_iva'       => (float) $fila->total_iva,
            'percepcion_iva'  => (float) $fila->percepcion_iva,
            'percepcion_iibb' => (float) $fila->percepcion_iibb,
        ];
    }

    /**
     * Alícuotas cuya factura no existe, o sin factura y viejas. Se arma de nuevo en cada uso (un
     * builder ya contado no se reusa para borrar).
     *
     * @param  \Carbon\Carbon  $limite
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function query_alicuotas_huerfanas($limite)
    {
        return ProviderOrderAfipTicketIva::where(function ($q) use ($limite) {

            $q->where(function ($q) {
                $q->whereNotNull('provider_order_afip_ticket_ivas.provider_order_afip_ticket_id')
                  ->whereNotExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('provider_order_afip_tickets')
                          ->whereColumn('provider_order_afip_tickets.id', 'provider_order_afip_ticket_ivas.provider_order_afip_ticket_id');
                  });
            })
            ->orWhere(function ($q) use ($limite) {
                $q->whereNull('provider_order_afip_ticket_ivas.provider_order_afip_ticket_id')
                  ->where(function ($q) use ($limite) {
                      $q->whereNull('provider_order_afip_ticket_ivas.created_at')
                        ->orWhere('provider_order_afip_ticket_ivas.created_at', '<', $limite);
                  });
            });
        });
    }

    /**
     * @param  \App\Models\User  $dueno
     * @return string
     */
    protected function nombre_del_dueno($dueno)
    {
        $nombre = trim((string) $dueno->company_name);

        if ($nombre === '') {
            $nombre = trim((string) $dueno->name);
        }

        return $nombre;
    }

    /**
     * @param  float  $monto
     * @return string
     */
    protected function plata($monto)
    {
        return '$'.number_format((float) $monto, 2, ',', '.');
    }
}
