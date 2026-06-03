<?php

namespace App\Console\Commands;

use App\Models\Address;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Asigna address_id de cada venta a sus article_purchases (backfill masivo por SQL).
 */
class set_article_purchases_address_id extends Command
{
    /**
     * @var string
     */
    protected $signature = 'set_article_purchases_address_id {user_id?} {sale_id?}';

    /**
     * @var string
     */
    protected $description = 'Copia sales.address_id a article_purchases.address_id por usuario.';

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Ejecuta un UPDATE masivo (join sales) en lugar de save() fila a fila.
     *
     * @return int
     */
    public function handle()
    {
        $user_id = $this->resolve_user_id();
        if ($user_id === null) {
            return 1;
        }

        $this->info('USER_ID: ' . $user_id);

        $has_addresses = Address::where('user_id', $user_id)->exists();
        if (! $has_addresses) {
            $this->info('No hay sucursales, comando terminado correctamente');

            return 0;
        }

        $from_sale_id = $this->argument('sale_id');
        if ($from_sale_id) {
            $this->comment('Desde venta id > ' . $from_sale_id);
        }

        $update_query = DB::table('article_purchases')
            ->join('sales', 'sales.id', '=', 'article_purchases.sale_id')
            ->where('sales.user_id', $user_id);

        if ($from_sale_id) {
            $update_query->where('sales.id', '>', (int) $from_sale_id);
        }

        // Un solo UPDATE con JOIN: evita cargar ventas y N saves en article_purchases.
        $updated_rows = $update_query->update([
            'article_purchases.address_id' => DB::raw('sales.address_id'),
        ]);

        $this->info('Filas actualizadas en article_purchases: ' . $updated_rows);
        $this->info('Termino');

        return 0;
    }

    /**
     * Resuelve user_id desde argumento o config(app.USER_ID).
     *
     * @return int|null
     */
    private function resolve_user_id()
    {
        $param_user_id = $this->argument('user_id');
        if ($param_user_id !== null && $param_user_id !== '') {
            return (int) $param_user_id;
        }

        $configured_user_id = config('app.USER_ID');
        if ($configured_user_id !== null && $configured_user_id !== '') {
            return (int) $configured_user_id;
        }

        $this->error('Falta user_id (parametro o config app.USER_ID).');

        return null;
    }
}
