<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\PdfColumnProfileWhatsappDefaultHelper;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Define perfiles PDF predeterminados para WhatsApp en owners existentes.
 */
class set_pdf_column_profile_whatsapp_defaults extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pdf-column-profiles:set-whatsapp-defaults
                            {--user-id= : Solo procesar este owner (users.id con owner_id null)}
                            {--dry-run : Mostrar qué se asignaría sin guardar}';

    /**
     * @var string
     */
    protected $description = 'Asigna perfil WhatsApp remito (venta con precios) y factura ARCA por owner';

    /**
     * @return int
     */
    public function handle()
    {
        $dry_run = (bool) $this->option('dry-run');
        $only_user_id = $this->option('user-id');

        if ($dry_run) {
            $this->warn('Modo dry-run: no se guardan cambios.');
        }

        $owners_query = User::query()
            ->whereNull('owner_id')
            ->select('id')
            ->orderBy('id');

        if ($only_user_id) {
            $owners_query->where('id', (int) $only_user_id);
        }

        $owners = $owners_query->get();

        if ($owners->isEmpty()) {
            $this->error('No hay owners para procesar.');
            return 1;
        }

        $rows = [];
        $remito_ok = 0;
        $factura_ok = 0;
        $skipped_owners = 0;

        foreach ($owners as $owner) {
            $result = PdfColumnProfileWhatsappDefaultHelper::apply_whatsapp_defaults_for_owner(
                $owner->id,
                $dry_run
            );

            if ($result['remito_applied']) {
                $remito_ok++;
            }
            if ($result['factura_applied']) {
                $factura_ok++;
            }
            if (! $result['remito_applied'] && ! $result['factura_applied']) {
                $skipped_owners++;
            }

            $rows[] = [
                $result['user_id'],
                $result['remito_profile_name'] ?: ($result['skipped_remito_reason'] ?: '-'),
                $result['factura_profile_name'] ?: ($result['skipped_factura_reason'] ?: '-'),
                $result['remito_applied'] ? 'si' : 'no',
                $result['factura_applied'] ? 'si' : 'no',
            ];
        }

        $this->table(
            ['owner_id', 'perfil_remito', 'perfil_factura', 'whatsapp_remito', 'whatsapp_factura'],
            $rows
        );

        $action = $dry_run ? 'Simulación' : 'Aplicado';
        $this->info(sprintf(
            '%s: %d owner(s). Remito WhatsApp: %d. Factura WhatsApp: %d. Sin ningún perfil: %d.',
            $action,
            $owners->count(),
            $remito_ok,
            $factura_ok,
            $skipped_owners
        ));

        return 0;
    }
}
