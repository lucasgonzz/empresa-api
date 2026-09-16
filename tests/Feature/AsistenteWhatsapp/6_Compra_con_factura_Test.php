<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraConFacturaIaHelper;
use App\Jobs\RunProviderOrderScanJob;
use App\Models\Address;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Misión asistente-por-whatsapp — la compra con la factura (§3.6 del plan).
 *
 * Es lo que Lucas dictó junto con la misión: el dueño manda la foto de la factura diciendo "esto es
 * la compra de tal proveedor", y el asistente da de alta la compra si todavía no hay ninguna y le
 * cuelga la foto para que la IA la lea.
 *
 * 🔴 Los dos tests que sostienen el criterio conservador del reuso: se reusa SOLO una compra vacía,
 * y NO se reusa una que ya tiene artículos. Colgarle una factura a una compra ya cargada duplica
 * stock y deuda, y eso no se ve hasta que el dueño mira el saldo del proveedor.
 */
class Compra_con_factura_Test extends AsistenteWhatsappTestCase
{
    /** @var \App\Models\Provider */
    protected $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);
        $this->dar_extension(self::SLUG_ESCANEO);

        Queue::fake();
        Storage::fake('local');

        $this->proveedor = $this->crear_proveedor('Distribuidora Sur');
    }

    /**
     * Un proveedor como el que deja la pantalla: con sus dos cuentas corrientes (pesos y dólares).
     *
     * 🔴 Las cuentas NO son decoración del fixture. La compra nace con `generate_current_acount`
     * en 1, igual que el formulario de la SPA, y `NewProviderOrderHelper::set_current_acount()`
     * lee `credit_account->id` sin guarda: un proveedor sin cuentas revienta el alta, la haga el
     * asistente o la pantalla. Es el mismo camino para los dos, que es justamente lo que esta
     * suite tiene que comprobar.
     *
     * @param  string  $nombre
     * @param  \App\Models\User|null  $duenio
     * @return \App\Models\Provider
     */
    protected function crear_proveedor($nombre, $duenio = null)
    {
        $duenio = is_null($duenio) ? $this->comercio : $duenio;

        $proveedor = Provider::create([
            'user_id' => $duenio->id,
            'name'    => $nombre,
        ]);

        CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $duenio->id);

        return $proveedor;
    }

    /**
     * La conversación con una foto sin gestionar, lista para que la herramienta la enganche.
     *
     * @param  int  $cuantas
     * @return array  [AiConversation, AiMessage $assistant_pendiente, AiMessageImagen[]]
     */
    protected function escena($cuantas = 1)
    {
        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Mirá esta factura']);

        $imagenes = [];

        for ($i = 1; $i <= $cuantas; $i++) {
            $imagenes[] = $this->guardar_foto($del_dueno, $i);
        }

        // El turno siguiente: "es de Distribuidora Sur".
        $this->mensaje($conversation, 'assistant', 'listo', ['contenido' => '¿De qué proveedor es?']);
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'De Distribuidora Sur']);

        $generandose = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return [$conversation, $generandose, $imagenes];
    }

    /**
     * @param  \App\Models\AiMessage  $mensaje
     * @param  int  $orden
     * @return \App\Models\AiMessageImagen
     */
    protected function guardar_foto(AiMessage $mensaje, $orden = 1)
    {
        $recurso = imagecreatetruecolor(40, 40);

        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/' . $orden . '.webp';

        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => $orden,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * Cierra el mensaje que propuso la tarjeta, como hace el job al terminar de generar la
     * respuesta, y devuelve el assistant del turno siguiente (el que contesta el "sí").
     *
     * 🔴 Existe porque una tarjeta de un mensaje todavía 'pendiente' NO se puede confirmar
     * (EjecutorAccionesIaHelper::verificar_que_siga_propuesta): mientras la respuesta se está
     * escribiendo, para quien la mire esa tarjeta todavía no existe. Saltear este paso en un test
     * sería probar un flujo que en producción no ocurre.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $propuso
     * @return \App\Models\AiMessage
     */
    protected function cerrar_turno($conversation, AiMessage $propuso)
    {
        $propuso->estado = 'listo';
        $propuso->contenido = 'Te doy de alta la compra de Distribuidora Sur con esa factura, ¿la registro?';
        $propuso->save();

        $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Sí, dale']);

        return $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);
    }

    /**
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $mensaje
     * @param  array  $input
     * @return array
     */
    protected function proponer($conversation, $mensaje, array $input = [])
    {
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        return PropuestaCompraConFacturaIaHelper::proponer(
            $contexto,
            $mensaje,
            array_merge(['proveedor' => 'Distribuidora Sur'], $input)
        );
    }

    /**
     * 🔴 El permiso real de compras, verificado contra el seeder y contra el botón "Nuevo" de la
     * vista: el plan proponía `provider_order.create`, que NO existe en ningún lado.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_permiso_de_compras_es_el_mismo_que_pide_el_boton_nuevo()
    {
        $this->assertEquals('provider_order.store', PermisosIaHelper::COMPRAS);

        $seeder = file_get_contents(database_path('seeders/PermissionsTableSeeder.php'));

        $this->assertStringContainsString(
            "'slug' => 'provider_order.store'",
            $seeder,
            'El slug tiene que existir de verdad: uno inventado le niega la carga a todo empleado.'
        );
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function propone_la_compra_con_la_foto_de_la_conversacion_y_dice_que_la_crea()
    {
        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertTrue($respuesta['ok'], 'Motivo: ' . json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(AiMessageAction::TIPO_COMPRA_CON_FACTURA, $tarjeta->tipo);
        $this->assertEquals(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());

        $renglones = json_encode($tarjeta->presentacion);

        $this->assertStringContainsString('Distribuidora Sur', $renglones);
        $this->assertStringContainsString('1 foto', $renglones);
        $this->assertStringContainsString('Se crea una compra nueva', $renglones, 'La tarjeta dice cuál de las dos cosas va a pasar.');
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function al_confirmar_crea_la_compra_el_escaneo_y_sella_las_fotos()
    {
        list($conversation, $generandose, $imagenes) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $contestando = $this->cerrar_turno($conversation, $generandose);

        $resultado = ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $respuesta['tarjeta_id']);

        $this->assertTrue($resultado['ok'], 'Motivo: ' . json_encode($resultado));

        $compra = ProviderOrder::where('user_id', $this->comercio->id)
            ->where('provider_id', $this->proveedor->id)
            ->first();

        $this->assertNotNull($compra, 'Tiene que haber quedado la compra.');

        $this->assertEquals(
            $this->comercio->id,
            (int) $compra->user_id,
            'La compra es del dueño, igual que la creada desde la pantalla.'
        );

        $this->assertEquals(0, (int) $compra->update_stock, 'Nace sin tocar stock: los artículos los carga el dueño al revisar el escaneo.');
        $this->assertEquals(0, (int) $compra->update_prices);
        $this->assertEquals(1, (int) $compra->generate_current_acount);

        $scan = ProviderOrderScan::where('provider_order_id', $compra->id)->first();

        $this->assertNotNull($scan, 'Tiene que haber quedado el escaneo.');
        $this->assertEquals('pendiente', $scan->estado);
        $this->assertEquals($this->comercio->id, (int) $scan->user_id);

        $this->assertEquals(
            1,
            ProviderOrderScanImage::where('provider_order_scan_id', $scan->id)->count(),
            'La foto de la conversación tiene que haber quedado como página de la factura.'
        );

        Queue::assertPushed(RunProviderOrderScanJob::class);

        $this->assertNotNull(
            AiMessageImagen::find($imagenes[0]->id)->gestionada_at,
            'La foto queda sellada: no se puede colgar de dos compras.'
        );

        $this->assertStringContainsString('Compra N° ' . $compra->num, $resultado['resultado']);
        $this->assertStringContainsString('Compras', $resultado['resultado']);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function reusa_una_compra_vacia_y_reciente_del_mismo_proveedor()
    {
        $vacia = ProviderOrder::create([
            'user_id'     => $this->comercio->id,
            'provider_id' => $this->proveedor->id,
            'num'         => 77,
        ]);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertTrue($respuesta['ok'], 'Motivo: ' . json_encode($respuesta));

        $this->assertStringContainsString(
            'Se usa la compra N° 77',
            $this->que_se_hace($respuesta['tarjeta_id']),
            'La tarjeta tiene que decir el número de la compra que reusa.'
        );

        $contestando = $this->cerrar_turno($conversation, $generandose);

        ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $respuesta['tarjeta_id']);

        $this->assertEquals(
            1,
            ProviderOrder::where('user_id', $this->comercio->id)->where('provider_id', $this->proveedor->id)->count(),
            'No se puede haber creado una compra nueva teniendo una vacía.'
        );

        $this->assertEquals(
            1,
            ProviderOrderScan::where('provider_order_id', $vacia->id)->count()
        );
    }

    /**
     * 🔴 Una compra con artículos ya cargados NO se reusa: agregarle una factura duplica stock y
     * deuda.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function no_reusa_una_compra_que_ya_tiene_articulos()
    {
        $cargada = ProviderOrder::create([
            'user_id'     => $this->comercio->id,
            'provider_id' => $this->proveedor->id,
            'num'         => 88,
        ]);

        $articulo = Article::create([
            'user_id' => $this->comercio->id,
            'name'    => 'Tornillo del test',
        ]);

        $cargada->articles()->attach($articulo->id, ['amount' => 5]);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertStringContainsString('Se crea una compra nueva', $this->que_se_hace($respuesta['tarjeta_id']));

        $contestando = $this->cerrar_turno($conversation, $generandose);

        ConfirmacionPorTextoIaHelper::confirmar($conversation, $contestando, $respuesta['tarjeta_id']);

        $this->assertEquals(
            2,
            ProviderOrder::where('user_id', $this->comercio->id)->where('provider_id', $this->proveedor->id)->count(),
            'La compra cargada queda intacta y la factura va a una nueva.'
        );

        $this->assertEquals(
            0,
            ProviderOrderScan::where('provider_order_id', $cargada->id)->count(),
            'A la compra con artículos no se le cuelga nada.'
        );
    }

    /**
     * Una compra vacía pero vieja tampoco se reusa: es una compra que quedó abierta de otra cosa.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function no_reusa_una_compra_vacia_de_hace_mas_de_una_semana()
    {
        $vieja = ProviderOrder::create([
            'user_id'     => $this->comercio->id,
            'provider_id' => $this->proveedor->id,
            'num'         => 99,
        ]);

        $vieja->created_at = Carbon::now()->subDays(PropuestaCompraConFacturaIaHelper::DIAS_DE_REUSO + 1);
        $vieja->save();

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertStringContainsString('Se crea una compra nueva', $this->que_se_hace($respuesta['tarjeta_id']));
    }

    /**
     * El renglón "Qué se hace" de una tarjeta: es lo que le dice al dueño, ANTES de confirmar, si
     * la compra se crea o se reusa.
     *
     * @param  int  $tarjeta_id
     * @return string
     */
    protected function que_se_hace($tarjeta_id)
    {
        $presentacion = AiMessageAction::find($tarjeta_id)->presentacion;

        foreach ($presentacion['renglones'] as $renglon) {
            if ($renglon['etiqueta'] === 'Qué se hace') {
                return (string) $renglon['valor'];
            }
        }

        return '';
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function sin_la_extension_del_escaneo_no_propone_y_lo_dice()
    {
        $this->comercio->extencions()->detach(
            \App\Models\ExtencionEmpresa::where('slug', self::SLUG_ESCANEO)->first()->id
        );
        $this->comercio->load('extencions');

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('lectura de facturas de compra', $respuesta['error']);

        $this->assertEquals(
            0,
            AiMessageAction::where('ai_conversation_id', $conversation->id)->count(),
            'Sin la extensión no se deja ninguna tarjeta.'
        );
    }

    /**
     * 🔴 Nunca crea un proveedor: uno nuevo arrastra cuenta corriente, bonificaciones y condición
     * fiscal.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_proveedor_que_no_existe_no_se_crea_y_se_avisa()
    {
        list($conversation, $generandose) = $this->escena();

        $antes = Provider::where('user_id', $this->comercio->id)->count();

        $respuesta = $this->proponer($conversation, $generandose, ['proveedor' => 'Mayorista Inexistente']);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No puedo crear proveedores', $respuesta['error']);

        $this->assertEquals($antes, Provider::where('user_id', $this->comercio->id)->count());
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function con_dos_proveedores_parecidos_pregunta_cual_es()
    {
        Provider::create([
            'user_id' => $this->comercio->id,
            'name'    => 'Distribuidora Sur SRL',
        ]);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose, ['proveedor' => 'Distribuidora']);

        $this->assertFalse($respuesta['ok']);
        $this->assertContains('cuál de estos proveedores es', $respuesta['faltan']);
        $this->assertNotEmpty($respuesta['opciones']['proveedores']);
    }

    /**
     * El nombre escrito completo gana sobre los parciales: "Distribuidora Sur" no es ambiguo aunque
     * exista "Distribuidora Sur SRL".
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_nombre_exacto_gana_sobre_los_parciales()
    {
        Provider::create([
            'user_id' => $this->comercio->id,
            'name'    => 'Distribuidora Sur SRL',
        ]);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose, ['proveedor' => 'Distribuidora Sur']);

        $this->assertTrue($respuesta['ok'], 'Motivo: ' . json_encode($respuesta));
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function sin_fotos_sin_gestionar_no_propone_y_pide_la_foto()
    {
        $conversation = $this->conversacion_whatsapp();

        $generandose = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('foto de factura sin usar', $respuesta['error']);
    }

    /**
     * Una foto que ya se usó en otra compra no se vuelve a enganchar.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_foto_ya_gestionada_no_se_vuelve_a_enganchar()
    {
        list($conversation, $generandose, $imagenes) = $this->escena();

        $imagenes[0]->gestionada_at = Carbon::now();
        $imagenes[0]->save();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('foto de factura sin usar', $respuesta['error']);
    }

    /**
     * Con una sola sucursal se usa esa sin preguntar; con varias y sin decir cuál, se pregunta.
     * `address_id` es obligatorio en el formulario de la SPA solo si la cuenta tiene sucursales.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_una_sola_sucursal_no_pregunta_y_con_varias_si()
    {
        $unica = Address::create([
            'user_id' => $this->comercio->id,
            'street'  => 'Av. Siempreviva',
        ]);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose);

        $this->assertTrue($respuesta['ok'], 'Con una sola sucursal no hay nada que preguntar.');

        $datos = AiMessageAction::find($respuesta['tarjeta_id'])->datos;

        $this->assertEquals((int) $unica->id, (int) $datos['address_id']);

        // Y ahora con dos.
        Address::create([
            'user_id' => $this->comercio->id,
            'street'  => 'Belgrano',
        ]);

        list($otra_conversacion, $otro_mensaje) = $this->escena();

        $con_dos = $this->proponer($otra_conversacion, $otro_mensaje);

        $this->assertFalse($con_dos['ok']);
        $this->assertContains('a qué sucursal entra la mercadería', $con_dos['faltan']);
        $this->assertNotEmpty($con_dos['opciones']['sucursales']);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function con_varias_sucursales_y_el_nombre_dicho_no_pregunta()
    {
        Address::create(['user_id' => $this->comercio->id, 'street' => 'Av. Siempreviva']);
        $belgrano = Address::create(['user_id' => $this->comercio->id, 'street' => 'Belgrano']);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose, ['sucursal' => 'Belgrano']);

        $this->assertTrue($respuesta['ok'], 'Motivo: ' . json_encode($respuesta));

        $datos = AiMessageAction::find($respuesta['tarjeta_id'])->datos;

        $this->assertEquals((int) $belgrano->id, (int) $datos['address_id']);
    }

    /**
     * El proveedor de otro comercio no se puede usar: la tenencia va en todas las consultas.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_proveedor_de_otro_comercio_no_se_encuentra()
    {
        $otro = $this->otro_dueno();

        Provider::create(['user_id' => $otro->id, 'name' => 'Mayorista del Otro']);

        list($conversation, $generandose) = $this->escena();

        $respuesta = $this->proponer($conversation, $generandose, ['proveedor' => 'Mayorista del Otro']);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No puedo crear proveedores', $respuesta['error']);
    }
}
