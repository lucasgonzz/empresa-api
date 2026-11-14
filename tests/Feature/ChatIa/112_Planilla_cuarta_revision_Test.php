<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AnclasDePlanillaIaHelper as Anclas;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContenidoDeFotosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper as Roles;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiPlanilla;
use App\Models\Client;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Misión agente-venta-por-planilla: la cuarta revisión independiente y la prueba real sobre 38b3b048 (02/11/2026). Cada test se
 * escribió antes que su arreglo, y falló.
 *
 * Todo inventado: nombres, documentos, códigos y operaciones.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Planilla_cuarta_revision_Test extends PlanillaTestCase
{
    /**
     * @param  array  $filas
     * @return string
     */
    protected function como_texto(array $filas)
    {
        return implode("\n", array_map(function ($fila) {
            return implode("\t", $fila);
        }, $filas));
    }

    /**
     * @param  AiMessage  $assistant
     * @return void
     */
    protected function cerrar(AiMessage $assistant)
    {
        $assistant->contenido = 'x';
        $assistant->estado = 'listo';
        $assistant->save();
    }

    /**
     * Una corrección, en un mensaje nuevo del dueño.
     *
     * @param  AiConversation  $conversation
     * @param  string  $mensaje
     * @param  array  $input
     * @return array
     */
    protected function corregir_con(AiConversation $conversation, $mensaje, array $input)
    {
        $this->del_dueno($conversation, $mensaje);

        $assistant = $this->assistant($conversation);

        $respuesta = $this->herramienta('corregir_renglon_de_planilla', array_merge(['lo_que_dijo_el_dueno' => $mensaje], $input), $conversation, $assistant);

        $this->cerrar($assistant);

        return $respuesta;
    }

    /**
     * Un definir_planilla, en un mensaje nuevo del dueño.
     *
     * @param  AiConversation  $conversation
     * @param  string  $mensaje
     * @param  array  $input
     * @return array
     */
    protected function definir_con(AiConversation $conversation, $mensaje, array $input)
    {
        $this->del_dueno($conversation, $mensaje);

        $assistant = $this->assistant($conversation);

        $respuesta = $this->herramienta('definir_planilla', $input, $conversation, $assistant);

        $this->cerrar($assistant);

        return $respuesta;
    }

    /**
     * Lo que dice el asistente, antes del próximo mensaje del dueño.
     *
     * @param  AiConversation  $conversation
     * @param  string  $texto
     * @return void
     */
    protected function dice_el_asistente(AiConversation $conversation, $texto)
    {
        $assistant = $this->assistant($conversation);

        $assistant->contenido = $texto;
        $assistant->estado = 'listo';
        $assistant->save();
    }

    /**
     * La propuesta, en un mensaje nuevo del dueño que ya dijo todo lo que hace falta.
     *
     * @param  AiConversation  $conversation
     * @return array
     */
    protected function proponer(AiConversation $conversation)
    {
        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);

        $assistant = $this->assistant($conversation);

        $this->herramienta('definir_planilla', ['importe_es' => 'total', 'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO, 'caja' => TestingFerreteriaSeeder::CAJA_EFECTIVO], $conversation, $assistant);

        $respuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);

        $this->cerrar($assistant);

        return $respuesta;
    }

    /**
     * Una charla sin ninguna planilla en curso: la planilla activa se comparte entre las charlas del dueño.
     *
     * @return AiConversation
     */
    protected function charla_sin_planilla()
    {
        AiPlanilla::where('user_id', $this->dueno->id)->update(['estado' => AiPlanilla::ESTADO_REEMPLAZADA]);

        return $this->conversacion();
    }

    // ---------------------------------------------------------------------
    // U1. Corregir el cliente cambia a quién se le vende
    // ---------------------------------------------------------------------

    /**
     * 🔴 «lo de Bruno es de Zulma» con Bruno ya cargado: la tarjeta decía Zulma y revisar() volvía a Bruno por su documento: la venta le iba
     * a Bruno.
     *
     * @test
     */
    public function corregir_el_cliente_no_vuelve_al_de_antes_por_su_documento()
    {
        $bruno = Client::create(['name' => 'Bruno Prueba Dos', 'dni' => '14000002', 'user_id' => $this->dueno->id, 'num' => 911201]);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertSame((int) $bruno->id, (int) PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['cliente_id']);

        $bueno = $this->corregir_con($conversation, 'lo de Bruno es de Zulma', ['renglon' => 2, 'campo' => 'cliente', 'valor' => 'Zulma']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 2);

        $this->assertEmpty($fila['cliente_id'] ?? null, 'revisar() no puede volver a Bruno.');
        $this->assertTrue($fila['cliente_nuevo']);
        $this->assertSame('Zulma', $fila['valores'][Roles::NOMBRE]);
        $this->assertArrayNotHasKey(Roles::DOCUMENTO, $fila['valores'], 'Sin el documento viejo.');

        $propuesta = $this->proponer($conversation);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta, JSON_UNESCAPED_UNICODE));

        $texto = AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto'];

        $this->assertStringContainsString('Zulma (nuevo): ', $texto);
        $this->assertStringContainsString('cliente Bruno Prueba Dos → Zulma (nuevo, sin documento)', $texto);

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $zulma = Client::where('user_id', $this->dueno->id)->where('name', 'Zulma')->first();

        $this->assertNotNull($zulma);
        $this->assertEmpty($zulma->dni, 'No se le pone el DNI de otro.');

        $venta = $this->ventas_de($planilla)->filter(function ($venta) use ($bruno) {
            return (int) $venta->client_id === (int) $bruno->id;
        });

        $this->assertCount(0, $venta, 'A Bruno no le queda ninguna venta.');
    }

    /**
     * Sin Bruno cargado, creaba «Zulma» con el DNI de Bruno.
     *
     * @test
     */
    public function corregir_el_cliente_no_crea_al_nuevo_con_el_documento_del_viejo()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertTrue($this->corregir_con($conversation, 'lo de Bruno es de Zulma', ['renglon' => 2, 'campo' => 'cliente', 'valor' => 'Zulma'])['ok']);

        $this->assertArrayNotHasKey(Roles::DOCUMENTO, PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores']);
        $this->assertArrayNotHasKey(Roles::TIPO_DOCUMENTO, PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores']);

        $this->assertTrue($this->proponer($conversation)['ok']);

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $this->assertSame(0, Client::where('user_id', $this->dueno->id)->where('dni', '14000002')->count());
        $this->assertEmpty(Client::where('user_id', $this->dueno->id)->where('name', 'Zulma')->first()->dni);
    }

    /**
     * Por nombre exacto o por id, va al que existe; y ni el nombre ni el documento vuelven con una foto o una tabla nueva.
     *
     * @test
     */
    public function corregir_el_cliente_busca_al_nuevo_y_una_tabla_nueva_no_lo_pisa()
    {
        $bruno = Client::create(['name' => 'Bruno Prueba Dos', 'dni' => '14000002', 'user_id' => $this->dueno->id, 'num' => 911202]);
        $zulma = Client::create(['name' => 'Zulma', 'dni' => '12000009', 'user_id' => $this->dueno->id, 'num' => 911203]);
        $yanina = Client::create(['name' => 'Yanina', 'dni' => '12000008', 'user_id' => $this->dueno->id, 'num' => 911204]);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->assertTrue($this->corregir_con($conversation, 'lo de Bruno es de Zulma', ['renglon' => 2, 'campo' => 'cliente', 'valor' => 'Zulma'])['ok']);

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 2);

        $this->assertSame((int) $zulma->id, (int) $fila['cliente_id']);
        $this->assertFalse($fila['cliente_nuevo']);
        $this->assertArrayNotHasKey(Roles::DOCUMENTO, $fila['valores']);

        $this->assertTrue($this->corregir_con($conversation, 'lo de Ana es de Yanina', ['renglon' => 1, 'campo' => 'cliente', 'valor' => (string) $yanina->id])['ok']);
        $this->assertSame((int) $yanina->id, (int) PlanillaIaHelper::fila_numero($planilla->fresh(), 1)['cliente_id']);

        // La misma tabla otra vez: cruza por su operación, y lo que corrigió el dueño queda.
        $this->planilla_pegada($conversation);

        $planilla = $planilla->fresh();

        $this->assertSame((int) $zulma->id, (int) PlanillaIaHelper::fila_numero($planilla, 2)['cliente_id']);
        $this->assertSame((int) $yanina->id, (int) PlanillaIaHelper::fila_numero($planilla, 1)['cliente_id']);
        $this->assertArrayNotHasKey(Roles::DOCUMENTO, PlanillaIaHelper::fila_numero($planilla, 2)['valores']);
        $this->assertNotSame((int) $bruno->id, (int) PlanillaIaHelper::fila_numero($planilla, 2)['cliente_id']);
    }

    // ---------------------------------------------------------------------
    // U2. SIN_DECIDIR tiene salida
    // ---------------------------------------------------------------------

    /**
     * 🔴 Reenviada con títulos no cruzaba, porque el renglón viejo no tenía código, y seguía pidiendo «mandá la tabla».
     *
     * @test
     */
    public function un_renglon_sin_decidir_se_reemplaza_por_la_tabla_con_titulos()
    {
        foreach ([
            'con código' => [
                ['DETALLE', 'CODIGO', 'CANTIDAD', 'TOTAL', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
                ['Bomba', 'SKU 70101', '2', '1.500,00', 'DNI', '17000001', 'Ana Prueba Uno'],
                ['Bomba', 'SKU 70102', '3', '12.500,00', 'DNI', '14000002', 'Bruno Prueba Dos'],
            ],
            'sin código' => [
                ['DETALLE', 'CANTIDAD', 'TOTAL', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
                ['Bomba', '2', '1.500,00', 'DNI', '17000001', 'Ana Prueba Uno'],
                ['Bomba', '3', '12.500,00', 'DNI', '14000002', 'Bruno Prueba Dos'],
            ],
        ] as $caso => $con_titulos) {

            $conversation = $this->charla_sin_planilla();

            $planilla = $this->planilla_pegada($conversation, $this->como_texto([
                ['Bomba', '500,00', '1.500', 'DNI', '17000001', 'Ana Prueba Uno'],
                ['Bomba', '800,00', '12.500', 'DNI', '14000002', 'Bruno Prueba Dos'],
            ]));

            $this->assertStringContainsString('fila de títulos', implode(' | ', $this->proponer($conversation)['faltan']), $caso);

            $this->planilla_pegada($conversation, $this->como_texto($con_titulos));

            $planilla = $planilla->fresh();

            $this->assertCount(2, $planilla->filas, $caso . ': se reemplazan, no se suman.');

            $fila = PlanillaIaHelper::fila_numero($planilla, 2);

            $this->assertSame('12.500,00', $fila['valores'][Roles::IMPORTE], $caso);
            $this->assertSame(3.0, Roles::cantidad_de($fila), $caso);
            $this->assertArrayNotHasKey(Roles::SIN_DECIDIR, $fila['valores'], $caso);

            $falta = implode(' | ', $this->proponer($conversation)['faltan'] ?? []);

            $this->assertStringNotContainsString('fila de títulos', $falta, $caso);
        }
    }

    /**
     * El documento tiene que ser el mismo, y el nombre no puede ser otro. Lo que no cubre la tabla nueva sigue pidiendo.
     *
     * @test
     */
    public function un_renglon_sin_decidir_solo_se_reemplaza_con_su_documento_y_su_nombre()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['Bomba', '500,00', '1.500', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['Bomba', '800,00', '12.500', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $this->planilla_pegada($conversation, $this->como_texto([
            ['DETALLE', 'CANTIDAD', 'TOTAL', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['Bomba', '2', '1.500,00', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['Bomba', '3', '12.500,00', 'DNI', '14000002', 'Otra Persona'],
        ]));

        $planilla = $planilla->fresh();

        $this->assertArrayNotHasKey(Roles::SIN_DECIDIR, PlanillaIaHelper::fila_numero($planilla, 1)['valores']);
        $this->assertArrayHasKey(Roles::SIN_DECIDIR, PlanillaIaHelper::fila_numero($planilla, 2)['valores'], 'Con otro nombre no es la misma venta.');
        $this->assertCount(3, $planilla->filas);
    }

    // ---------------------------------------------------------------------
    // U3. Las palabras del importe no nombran un renglón
    // ---------------------------------------------------------------------

    /**
     * 🔴 Con «Amoladora Total» en la planilla, «ES EL TOTAL» solo definía ese renglón, y los otros seguían preguntando: un bucle.
     *
     * @test
     */
    public function es_el_total_no_nombra_a_la_amoladora_total()
    {
        $this->articulo_de_prueba('zz-c112 Amoladora Total', ['bar_code' => '70110']);
        $this->articulo_de_prueba('zz-c112 Precio Unidad Cada', ['bar_code' => '70111']);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['C FINAL', '1.000,00', 'SKU 70110', 'Amoladora Total', '#2000000000000001', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['C FINAL', '2.000,00', 'SKU 70102', 'Bomba Beta', '#2000000000000002', '2', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $bueno = $this->definir_con($conversation, 'ES EL TOTAL', ['importe_es' => 'total']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));

        $planilla = $planilla->fresh();

        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, PlanillaIaHelper::fila_numero($planilla, 1)['importe_es']);
        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, PlanillaIaHelper::fila_numero($planilla, 2)['importe_es'], 'El renglón de la bomba también.');

        // Para corregir tampoco la nombran. Sí la nombra su otra palabra.
        foreach (['el total es 5.000', 'el precio es 5.000', 'cada una 5.000', 'por unidad 5.000'] as $mensaje) {

            $this->assertFalse($this->corregir_con($conversation, $mensaje, ['renglon' => 1, 'campo' => 'importe', 'valor' => 5000])['ok'], $mensaje);
        }

        $this->assertTrue($this->corregir_con($conversation, 'el total de la amoladora es 5.000', ['renglon' => 1, 'campo' => 'importe', 'valor' => 5000])['ok']);

        // Y no hacen de otro renglón: «el total de Bruno» habla solo de Bruno.
        $this->assertTrue($this->corregir_con($conversation, 'el total de Bruno es 6.000', ['renglon' => 2, 'campo' => 'importe', 'valor' => 6000])['ok']);
    }

    // ---------------------------------------------------------------------
    // U4. Descartar deja rastro y exige un pedido
    // ---------------------------------------------------------------------

    /**
     * 🔴 «TODAS CON FECHA 31/10» con renglon=todos descartaba la planilla entera, y «Bruno lleva 3 y Ana lleva 2» con descartar se llevaba el renglón.
     *
     * @test
     */
    public function descartar_exige_un_pedido_explicito_de_sacar()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $rechazo = $this->corregir_con($conversation, 'TODAS CON FECHA 31/10', ['renglon' => 'todos', 'campo' => 'descartar']);

        $this->assertFalse($rechazo['ok'], json_encode($rechazo, JSON_UNESCAPED_UNICODE));
        $this->assertSame(AiPlanilla::ESTADO_ACTIVA, $planilla->fresh()->estado);

        foreach (['Bruno lleva 3 y Ana lleva 2', 'lo de Bruno está bien', 'Bruno ya pagó', 'no saques a Bruno'] as $mensaje) {

            $rechazo = $this->corregir_con($conversation, $mensaje, ['renglon' => 2, 'campo' => 'descartar']);

            $this->assertFalse($rechazo['ok'], $mensaje . ': ' . json_encode($rechazo, JSON_UNESCAPED_UNICODE));
            $this->assertSame('sin_ancla', $rechazo['codigo'], $mensaje);
            $this->assertSame(AiPlanilla::FILA_PENDIENTE, PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['estado'], $mensaje);
        }

        foreach ([
            'sacá lo de Bruno', 'saca lo de Bruno', 'sacala a Bruno', 'lo de Bruno no la cargues', 'lo de Bruno no va', 'borrá lo de Bruno', 'borra lo de Bruno',
            'quitá lo de Bruno', 'quita lo de Bruno', 'descartá lo de Bruno', 'descarta lo de Bruno', 'anulá lo de Bruno', 'anula lo de Bruno', 'no cargues lo de Bruno',
        ] as $mensaje) {

            $otra = $this->charla_sin_planilla();

            $de_la_otra = $this->planilla_pegada($otra);

            $bueno = $this->corregir_con($otra, $mensaje, ['renglon' => 2, 'campo' => 'descartar']);

            $this->assertTrue($bueno['ok'], $mensaje . ': ' . json_encode($bueno, JSON_UNESCAPED_UNICODE));
            $this->assertSame(AiPlanilla::FILA_DESCARTADA, PlanillaIaHelper::fila_numero($de_la_otra->fresh(), 2)['estado'], $mensaje);
        }

        // La planilla entera, igual.
        foreach (['no cargues ninguna de la planilla', 'sacá todas', 'No es una planilla para cargar', 'no eran ventas, no cargues nada'] as $mensaje) {

            $otra = $this->charla_sin_planilla();

            $de_la_otra = $this->planilla_pegada($otra);

            $bueno = $this->corregir_con($otra, $mensaje, ['renglon' => 'todos', 'campo' => 'descartar']);

            $this->assertTrue($bueno['ok'], $mensaje . ': ' . json_encode($bueno, JSON_UNESCAPED_UNICODE));
            $this->assertSame(AiPlanilla::ESTADO_REEMPLAZADA, $de_la_otra->fresh()->estado, $mensaje);
        }
    }

    /**
     * Un descarte siempre va a «Cambios»: el renglón ya no está en la lista, y sin esto el dueño confirma sin ver que falta una venta.
     *
     * @test
     */
    public function un_descarte_va_a_cambios()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->assertTrue($this->corregir_con($conversation, 'el renglón 4 no lo cargues', ['renglon' => 4, 'campo' => 'descartar'])['ok']);

        $propuesta = $this->proponer($conversation);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta, JSON_UNESCAPED_UNICODE));

        $tarjeta = AiMessageAction::find($propuesta['tarjeta_id']);

        $this->assertStringContainsString('no se carga el renglón 4 (Carla Prueba Tres', $tarjeta->datos['texto']);
        $this->assertStringContainsString('no se carga el renglón 4 (Carla Prueba Tres', $tarjeta->presentacion['aviso']);
    }

    // ---------------------------------------------------------------------
    // U5. Un número no es un id porque sí
    // ---------------------------------------------------------------------

    /**
     * 🔴 «Bruno lleva 2» con campo=articulo y valor=2 le ponía el artículo de id 2.
     *
     * @test
     */
    public function un_numero_solo_vale_como_id_si_se_le_ofrecio_al_dueno()
    {
        $otro = $this->articulo_de_prueba('zz-c112 Martillo');

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $rechazo = $this->corregir_con($conversation, 'Bruno lleva ' . $otro->id, ['renglon' => 2, 'campo' => 'articulo', 'valor' => (string) $otro->id]);

        $this->assertFalse($rechazo['ok'], json_encode($rechazo, JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('articulo_elegido_id', PlanillaIaHelper::fila_numero($planilla->fresh(), 2));

        // Es un código: se busca como código.
        $con_ese_codigo = $this->articulo_de_prueba('zz-c112 Pinza', ['bar_code' => (string) $otro->id]);

        $bueno = $this->corregir_con($conversation, 'a Bruno ponele el ' . $otro->id, ['renglon' => 2, 'campo' => 'articulo', 'valor' => (string) $otro->id]);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));
        $this->assertSame((int) $con_ese_codigo->id, (int) PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['articulo_elegido_id']);
    }

    /**
     * Si el sistema se lo ofreció para ese renglón, el número es el id.
     *
     * @test
     */
    public function el_id_que_se_le_ofrecio_para_ese_renglon_vale()
    {
        $uno = $this->articulo_de_prueba('zz-c112 Amoladora chica', ['bar_code' => '70120']);
        $dos = $this->articulo_de_prueba('zz-c112 Amoladora grande', ['provider_code' => '70120']);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70120', '1', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $this->assertEqualsCanonicalizing([(int) $uno->id, (int) $dos->id], array_column(PlanillaIaHelper::fila_numero($planilla, 1)['articulo_opciones'], 'articulo_id'));

        $bueno = $this->corregir_con($conversation, 'es el ' . $dos->id, ['renglon' => 1, 'campo' => 'articulo', 'valor' => (string) $dos->id]);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));
        $this->assertSame((int) $dos->id, (int) PlanillaIaHelper::fila_numero($planilla->fresh(), 1)['articulo_elegido_id']);
    }

    // ---------------------------------------------------------------------
    // U6. El alias en los dos sentidos
    // ---------------------------------------------------------------------

    /**
     * 🔴 Con el método «MercadoPago» o «MP», «MERCADO PAGO» se rechazaba siempre.
     *
     * @test
     */
    public function el_nombre_de_un_metodo_se_compara_sin_espacios_en_los_dos_sentidos()
    {
        foreach (['MercadoPago', 'MP', 'Mercado Pago', 'Cuenta MP'] as $metodo) {

            foreach (['MERCADO PAGO', 'mercadopago', 'por mp', 'Mercado  Pago'] as $mensaje) {

                $this->assertTrue(Anclas::valor_en_mensajes($metodo, [$mensaje]), $metodo . ' / ' . $mensaje);
            }
        }

        $this->assertTrue(Anclas::valor_en_mensajes('Mercado Libre', ['mercadolibre']));
        $this->assertTrue(Anclas::valor_en_mensajes('MercadoLibre', ['por mercado libre']));
        $this->assertTrue(Anclas::valor_en_mensajes('Transferencia', ['por transfe']));
        $this->assertTrue(Anclas::valor_en_mensajes('Transfe', ['fue transferencia']));

        $this->assertFalse(Anclas::valor_en_mensajes('MP', ['una compra']));
        $this->assertFalse(Anclas::valor_en_mensajes('MP', ['efectivo']));
        $this->assertFalse(Anclas::valor_en_mensajes('Mercado Pago', ['efectivo']));
    }

    // ---------------------------------------------------------------------
    // U8. El arranque cuenta la espera en la cola
    // ---------------------------------------------------------------------

    /**
     * 🔴 Con cola, más de 20 s + 100 s de lectura + 60 s de la vuelta pasaba los 180 s del admin. Por WhatsApp se cuenta desde el alta.
     *
     * @test
     */
    public function por_whatsapp_la_lectura_del_arranque_cuenta_la_espera_en_la_cola()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $foto = $this->foto($this->del_dueno($conversation, 'Cargá', AiMessage::CANAL_WHATSAPP));

        $assistant = $this->assistant($conversation, AiMessage::CANAL_WHATSAPP);

        AiMessage::where('id', $assistant->id)->update(['created_at' => Carbon::now()->subSeconds(96)]);

        $assistant = $assistant->fresh();

        $this->vueltas = [$this->texto_del_modelo('Dale.')];

        $this->responder($conversation, $assistant);

        $this->assertCount(0, $this->lecturas_de('claude-sonnet-5-5'), 'Ya no da el tiempo: no arranca ninguna lectura.');
        $this->assertSame(0, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
        $this->assertNull($foto->fresh()->gestionada_at, 'La foto queda para el próximo mensaje.');
    }

    /**
     * Sin espera, o por el sistema, lee como siempre.
     *
     * @test
     */
    public function sin_espera_o_por_el_sistema_la_lectura_del_arranque_no_cambia()
    {
        $this->todos_leen($this->filas_de_prueba());
        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->foto($this->del_dueno($conversation, 'Cargá'));

        $assistant = $this->assistant($conversation);

        AiMessage::where('id', $assistant->id)->update(['created_at' => Carbon::now()->subSeconds(96)]);

        $this->vueltas = [$this->texto_del_modelo('Dale.')];

        $this->responder($conversation, $assistant->fresh());

        $this->assertCount(1, $this->lecturas_de('claude-sonnet-5-5'));
        $this->assertSame(1, AiPlanilla::where('ai_conversation_id', $conversation->id)->count());
    }

    // ---------------------------------------------------------------------
    // U9. PHP 8.4
    // ---------------------------------------------------------------------

    /**
     * `array $fila = null` es Deprecated en 8.4: un tipo con null implícito. En los archivos nuevos de esta misión no hay ninguno.
     *
     * @test
     */
    public function en_los_archivos_nuevos_no_hay_tipos_con_null_implicito()
    {
        foreach ([
            'AnclasDePlanillaIaHelper', 'ContenidoDeFotosIaHelper', 'EjecucionDePlanillaIaHelper', 'LecturaDePlanillaIaHelper',
            'PlanillaDeTextoIaHelper', 'PlanillaIaHelper', 'RolesDePlanillaIaHelper', 'TextoDePlanillaIaHelper',
        ] as $archivo) {

            $codigo = file_get_contents(app_path('Http/Controllers/Helpers/asistente_ia/' . $archivo . '.php'));

            $this->assertSame(0, preg_match('/[(,]\s*[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s+\$[a-z_]+\s*=\s*null\b/', $codigo), $archivo);
        }
    }

    // ---------------------------------------------------------------------
    // U10. «SI ES ESTE»
    // ---------------------------------------------------------------------

    /**
     * 🔴 El dueño contesta «SI ES ESTE» a lo que acaba de preguntar el asistente, y se rechazaba.
     *
     * @test
     */
    public function si_es_este_contesta_una_duda_como_un_si()
    {
        foreach (['SI ES ESTE', 'si, esa', 'Sí, ese', 'si es esa', 'ok, es este'] as $mensaje) {

            $conversation = $this->charla_sin_planilla();

            $planilla = $this->planilla_pegada($conversation, $this->como_texto([
                ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
                ['1.000,00', '70199', '1', 'DNI', '17000001', 'Ana Prueba Uno'],
            ]));

            $this->dice_el_asistente($conversation, '¿El artículo del renglón 1 es el 70102?');

            $bueno = $this->corregir_con($conversation, $mensaje, ['renglon' => 1, 'campo' => 'articulo', 'valor' => '70102']);

            $this->assertTrue($bueno['ok'], $mensaje . ': ' . json_encode($bueno, JSON_UNESCAPED_UNICODE));
            $this->assertNotEmpty(PlanillaIaHelper::fila_numero($planilla->fresh(), 1)['articulo_id'], $mensaje);
        }

        foreach (['¿si es este?', 'es este', 'no es este', 'SI ES OTRO'] as $mensaje) {

            $this->assertFalse(Anclas::es_si_a_una_duda($mensaje), $mensaje);
        }
    }

    /**
     * Nunca para cambiar algo resuelto ni para confirmar una carga.
     *
     * @test
     */
    public function si_es_este_no_cambia_lo_resuelto_ni_confirma_una_carga()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        $this->dice_el_asistente($conversation, '¿El renglón 2 son 7?');

        $rechazo = $this->corregir_con($conversation, 'SI ES ESTE', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 7]);

        $this->assertFalse($rechazo['ok']);
        $this->assertSame('un_si_no_corrige', $rechazo['codigo']);
        $this->assertSame('2', PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['valores'][Roles::CANTIDAD]);

        foreach (['SI ES ESTE', 'si, esa', 'Sí, ese'] as $mensaje) {

            $this->assertFalse(ConfirmacionDeterministaIaHelper::es_afirmacion($mensaje), $mensaje . ' no confirma una tarjeta.');
        }

        // Y tampoco define nada de la planilla.
        $this->assertFalse($this->definir_con($conversation, 'SI ES ESTE', ['metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO])['ok']);
    }

    // ---------------------------------------------------------------------
    // U11. El importe leído
    // ---------------------------------------------------------------------

    /**
     * 🔴 Sin saber si es total o por unidad, `total` no iba y DeepSeek le decía al dueño que la tabla no tenía importe, con la foto mostrándolo.
     *
     * @test
     */
    public function mientras_no_se_sabe_que_es_el_importe_el_modelo_ve_el_importe_leido()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $this->del_dueno($conversation, 'Mostrame');

        $consulta = $this->herramienta('consultar_planilla', [], $conversation, $this->assistant($conversation));

        $sin_definir = $consulta['renglones'][1];

        $this->assertSame('88.400,00', $sin_definir['importe_leido']);
        $this->assertSame('saber si es total o por unidad', $sin_definir['falta']);
        $this->assertArrayNotHasKey('total', $sin_definir);

        // Con cantidad 1 no falta nada: va el total.
        $this->assertArrayHasKey('total', $consulta['renglones'][0]);
        $this->assertArrayNotHasKey('importe_leido', $consulta['renglones'][0]);
        $this->assertArrayNotHasKey('falta', $consulta['renglones'][0]);
    }

    // ---------------------------------------------------------------------
    // U12. La fecha
    // ---------------------------------------------------------------------

    /**
     * 🔴 «TODAS CON FECHA 31/10», el agente contestaba «la fecha anotada» y la venta salía con la de hoy. La tarjeta lo dice SIEMPRE, por código.
     *
     * @test
     */
    public function la_tarjeta_siempre_dice_con_que_fecha_se_cargan()
    {
        $hoy = Carbon::today()->format('d/m');

        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $tarjeta = AiMessageAction::find($this->proponer($conversation)['tarjeta_id']);

        $linea = 'Se cargan con la fecha de hoy (' . $hoy . '); la de la planilla queda en observaciones.';

        $this->assertStringContainsString($linea, $tarjeta->datos['texto']);
        $this->assertStringContainsString($linea, $tarjeta->presentacion['aviso']);

        // Sin fechas en la planilla, también.
        $otra = $this->charla_sin_planilla();

        $this->planilla_pegada($otra, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70101', '1', 'DNI', '17000001', 'Ana Prueba Uno'],
        ]));

        $sin_fechas = AiMessageAction::find($this->proponer($otra)['tarjeta_id']);

        $this->assertStringContainsString('Se cargan con la fecha de hoy (' . $hoy . ').', $sin_fechas->datos['texto']);
        $this->assertStringNotContainsString('observaciones', $sin_fechas->datos['texto']);
    }

    /**
     * Las herramientas le dicen al modelo que la fecha es siempre la de hoy, y que nunca diga que anotó otra.
     *
     * @test
     */
    public function las_herramientas_dicen_que_la_fecha_es_la_de_hoy()
    {
        foreach (HerramientasDeCarga::definiciones() as $definicion) {

            if (in_array($definicion['name'], ['definir_planilla', 'proponer_ventas_de_planilla'], true)) {

                $this->assertStringContainsString('La fecha de la venta es siempre la de hoy', $definicion['description'], $definicion['name']);
                $this->assertStringContainsString('nunca digas que la anotaste', $definicion['description'], $definicion['name']);
            }
        }
    }

    // ---------------------------------------------------------------------
    // U13. «Con esas palabras»
    // ---------------------------------------------------------------------

    /**
     * 🔴 El agente le repetía al dueño «confirmame con esas palabras»: jerga nuestra, sacada de la respuesta de rechazo.
     *
     * @test
     */
    public function los_rechazos_piden_preguntar_de_forma_natural()
    {
        $conversation = $this->conversacion();

        $this->planilla_pegada($conversation);

        $rechazos = [
            $this->definir_con($conversation, 'SI', ['importe_es' => 'total']),
            $this->definir_con($conversation, 'Cargalas', ['cobro' => 'cuenta_corriente']),
            $this->definir_con($conversation, 'Cargalas', ['cobro' => 'contado']),
            $this->corregir_con($conversation, 'son 3', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3]),
            $this->corregir_con($conversation, 'SI', ['renglon' => 2, 'campo' => 'cantidad', 'valor' => 3]),
            $this->corregir_con($conversation, 'SI', ['renglon' => 'todos', 'campo' => 'descartar']),
        ];

        foreach ($rechazos as $rechazo) {

            $this->assertFalse($rechazo['ok'], json_encode($rechazo, JSON_UNESCAPED_UNICODE));

            foreach (['con esas palabras', 'con estas palabras', 'con sus palabras', 'con la palabra'] as $jerga) {

                $this->assertStringNotContainsString($jerga, $rechazo['error']);
            }
        }

        $this->assertStringContainsString('de forma natural', $rechazos[0]['error']);
        $this->assertStringContainsString('de forma natural', $rechazos[1]['error']);
        $this->assertStringContainsString('de forma natural', $rechazos[3]['error']);

        foreach (HerramientasDeCarga::definiciones() as $definicion) {

            if ($definicion['name'] === 'definir_planilla') {

                $this->assertStringNotContainsString('con esas palabras', $definicion['description']);
                $this->assertStringContainsString('de forma natural', $definicion['description']);
            }
        }
    }

    // ---------------------------------------------------------------------
    // U14. Una pregunta sobre lo que dice la foto
    // ---------------------------------------------------------------------

    /**
     * «CUANTO STOCK HAY DEL DESTORNILLADOR?» con una foto de una tabla: buscó en el sistema, no lo encontró y no dijo lo que trae la foto.
     *
     * @test
     */
    public function si_el_dato_esta_en_la_foto_contesta_primero_lo_que_dice_la_foto()
    {
        $frase = 'Si te preguntan un dato que está en una de esas transcripciones, contestá primero lo que dice la foto, aclarando que es de la foto, y después lo que diga el sistema.';

        $this->assertStringContainsString($frase, ContenidoDeFotosIaHelper::nota_del_turno(true));
        $this->assertStringContainsString($frase, ContenidoDeFotosIaHelper::nota_del_turno(false));

        config(['services.fotos_del_asistente.activa' => true, 'services.fotos_del_asistente.modelo' => 'claude-haiku-5-5']);

        $this->dueno->agente_proveedor = 'deepseek';
        $this->dueno->save();

        $this->clasificador = false;

        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->foto($this->del_dueno($conversation, 'CUANTO STOCK HAY DEL DESTORNILLADOR?'));

        $this->vueltas = [$this->texto_del_modelo('La foto dice 84.')];

        $this->responder($conversation, $this->assistant($conversation));

        $pedidos = $this->requests_del_modelo_que_conversa();

        $this->assertStringContainsString($frase, json_encode($pedidos[count($pedidos) - 1]['messages'], JSON_UNESCAPED_UNICODE));
    }

    // ---------------------------------------------------------------------
    // Parte 7. V1: corregir el cliente de un renglón sin nombre
    // ---------------------------------------------------------------------

    /**
     * 🔴 Una tabla sin columna de nombre, con el documento de un cliente cargado: entraba por «le faltaba el nombre», conservaba el documento, y
     * «Cambios» decía Bruno → Zulma mientras la venta le iba a Bruno.
     *
     * @test
     */
    public function corregir_el_cliente_de_un_renglon_sin_nombre_no_vuelve_al_del_documento()
    {
        $bruno = Client::create(['name' => 'Bruno Prueba Dos', 'dni' => '14000002', 'user_id' => $this->dueno->id, 'num' => 911205]);

        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['DOCUMENTO', 'CODIGO', 'CANTIDAD', 'TOTAL'],
            ['14000002', '70102', '2', '1.000,00'],
        ]));

        $this->assertSame((int) $bruno->id, (int) PlanillaIaHelper::fila_numero($planilla, 1)['cliente_id']);

        $bueno = $this->corregir_con($conversation, 'lo de Bruno es de Zulma', ['renglon' => 1, 'campo' => 'cliente', 'valor' => 'Zulma']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 1);

        $this->assertEmpty($fila['cliente_id'] ?? null, 'revisar() no puede volver a Bruno.');
        $this->assertTrue($fila['cliente_nuevo']);
        $this->assertArrayNotHasKey(Roles::DOCUMENTO, $fila['valores']);

        $this->assertTrue($this->proponer($conversation)['ok']);

        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));

        $zulma = Client::where('user_id', $this->dueno->id)->where('name', 'Zulma')->first();

        $this->assertNotNull($zulma);
        $this->assertSame([(int) $zulma->id], $this->ventas_de($planilla)->pluck('client_id')->map(function ($id) {
            return (int) $id;
        })->all());
    }

    /**
     * Si el documento no resolvió a nadie, el dueño está contestando cómo se llama: el documento sigue siendo de ese cliente.
     *
     * @test
     */
    public function sin_cliente_para_ese_documento_el_nombre_lo_completa_y_lo_conserva()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['DOCUMENTO', 'CODIGO', 'CANTIDAD', 'TOTAL'],
            ['14000002', '70102', '2', '1.000,00'],
        ]));

        $bueno = $this->corregir_con($conversation, 'se llama Zulma', ['renglon' => 1, 'campo' => 'cliente', 'valor' => 'Zulma']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 1);

        $this->assertSame('14000002', $fila['valores'][Roles::DOCUMENTO]);
        $this->assertSame('Zulma', $fila['valores'][Roles::NOMBRE]);
    }

    // ---------------------------------------------------------------------
    // V2: corregir hacia un cliente nuevo que está en la misma planilla
    // ---------------------------------------------------------------------

    /**
     * 🔴 Renglón de Bruno y renglón de Zulma con DNI, sin cliente en el sistema. «lo de Bruno es de Zulma» creaba dos Zulma si Bruno iba
     * primero, la tarjeta decía «Clientes nuevos: 1» y la deuda quedaba partida. En cualquier orden: un cliente, y dos ventas suyas.
     *
     * @test
     */
    public function corregir_hacia_un_cliente_nuevo_de_la_misma_planilla_crea_uno_solo()
    {
        $de_bruno = ['1.000,00', '70102', '2', 'DNI', '14000002', 'Bruno Prueba Dos'];
        $titulos = ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'];

        foreach ([
            'el nuevo después' => ['Zulma', '12000009', true],
            'el nuevo primero' => ['Yanina', '12000008', false],
        ] as $caso => $datos) {

            $nombre = $datos[0];
            $documento = $datos[1];

            $del_nuevo = ['2.000,00', '70101', '1', 'DNI', $documento, $nombre];

            $renglon = $datos[2] ? 1 : 2;

            $conversation = $this->charla_sin_planilla();

            $planilla = $this->planilla_pegada($conversation, $this->como_texto($datos[2] ? [$titulos, $de_bruno, $del_nuevo] : [$titulos, $del_nuevo, $de_bruno]));

            $bueno = $this->corregir_con($conversation, 'lo de Bruno es de ' . $nombre, ['renglon' => $renglon, 'campo' => 'cliente', 'valor' => $nombre]);

            $this->assertTrue($bueno['ok'], $caso . ': ' . json_encode($bueno, JSON_UNESCAPED_UNICODE));
            $this->assertSame($documento, PlanillaIaHelper::fila_numero($planilla->fresh(), $renglon)['valores'][Roles::DOCUMENTO], $caso);

            $propuesta = $this->proponer($conversation);

            $this->assertTrue($propuesta['ok'], $caso . ': ' . json_encode($propuesta, JSON_UNESCAPED_UNICODE));

            $texto = AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto'];

            $this->assertStringContainsString('Clientes nuevos que se crean: 1.', $texto, $caso);
            $this->assertStringContainsString('cliente Bruno Prueba Dos → ' . $nombre . ' (nuevo)', $texto, $caso);
            $this->assertStringNotContainsString('sin documento', $texto, $caso);

            $this->del_dueno($conversation, 'Sí');
            $this->responder($conversation, $this->assistant($conversation));

            $zulmas = Client::where('user_id', $this->dueno->id)->where('name', $nombre)->get();

            $this->assertCount(1, $zulmas, $caso);
            $this->assertSame($documento, (string) $zulmas[0]->dni, $caso);

            $ventas = $this->ventas_de($planilla);

            $this->assertCount(2, $ventas, $caso);

            foreach ($ventas as $venta) {

                $this->assertSame((int) $zulmas[0]->id, (int) $venta->client_id, $caso);
            }
        }
    }

    /**
     * Si en la planilla hay dos con ese nombre y documentos distintos, no se elige.
     *
     * @test
     */
    public function dos_renglones_con_el_mismo_nombre_y_otro_documento_no_se_eligen()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['TOTAL', 'CODIGO', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['1.000,00', '70102', '2', 'DNI', '14000002', 'Bruno Prueba Dos'],
            ['2.000,00', '70101', '1', 'DNI', '12000009', 'Zulma'],
            ['3.000,00', '70101', '1', 'DNI', '12000008', 'Zulma'],
        ]));

        $rechazo = $this->corregir_con($conversation, 'lo de Bruno es de Zulma', ['renglon' => 1, 'campo' => 'cliente', 'valor' => 'Zulma']);

        $this->assertFalse($rechazo['ok'], json_encode($rechazo, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('documento', $rechazo['error']);
        $this->assertSame('14000002', PlanillaIaHelper::fila_numero($planilla->fresh(), 1)['valores'][Roles::DOCUMENTO]);
    }

    // ---------------------------------------------------------------------
    // V3: la fila de totales
    // ---------------------------------------------------------------------

    /**
     * 🔴 Medido el 03/11/2026: por los dos caminos la fila entraba como un renglón más y se preguntaba por ella. En una tabla sin títulos le sacaba
     * el rol a la columna del comprobante, y en una foto «ES EL TOTAL» definía SOLO ese renglón.
     *
     * @test
     */
    public function una_fila_de_totales_pegada_no_entra_a_la_planilla()
    {
        $con_operacion = function ($comprobante, $importe, $codigo, $operacion, $cantidad, $documento, $nombre) {
            return [$comprobante, $importe, $codigo, 'Bomba', $operacion, $cantidad, 'DNI', $documento, $nombre];
        };

        $sin_titulos = [
            $con_operacion('C FINAL', '1.000,00', 'SKU 70101', '#2000000000000001', '2', '17000001', 'Ana Prueba Uno'),
            $con_operacion('C FINAL', '2.000,00', 'SKU 70102', '#2000000000000002', '3', '14000002', 'Bruno Prueba Dos'),
        ];

        $con_titulos = [
            ['DETALLE', 'CODIGO', 'CANTIDAD', 'IMPORTE', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['Bomba', '70101', '2', '1.000,00', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['Bomba', '70102', '3', '2.000,00', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ];

        foreach ([
            'alineada, en la primera columna' => array_merge($sin_titulos, [['TOTAL', '3.000,00', '', '', '', '5', '', '', '']]),
            'en la columna de al lado'        => array_merge($con_titulos, [['', '', 'TOTAL', '3.000,00', '', '', '']]),
            'una línea corta'                 => array_merge($sin_titulos, [['TOTAL', '3.000,00']]),
            'con dos puntos'                  => array_merge($sin_titulos, [['Total:', '3.000,00', '', '', '', '', '', '', '']]),
            'totales'                         => array_merge($sin_titulos, [['TOTALES', '3.000,00', '', '', '', '', '', '', '']]),
            'subtotal'                        => array_merge($sin_titulos, [['Subtotal', '3.000,00', '', '', '', '', '', '', '']]),
            'suma'                            => array_merge($sin_titulos, [['SUMA', '3.000,00', '', '', '', '', '', '', '']]),
            'una suma de más de un millón'    => array_merge($con_titulos, [['TOTAL', '', '', '1.234.567', '', '', '']]),
        ] as $caso => $filas) {

            $conversation = $this->charla_sin_planilla();

            $planilla = $this->planilla_pegada($conversation, $this->como_texto($filas));

            $this->assertNotNull($planilla, $caso);
            $this->assertCount(2, $planilla->filas, $caso);

            $bueno = $this->definir_con($conversation, 'ES EL TOTAL', ['importe_es' => 'total']);

            $this->assertTrue($bueno['ok'], $caso . ': ' . json_encode($bueno, JSON_UNESCAPED_UNICODE));

            foreach ($planilla->fresh()->filas as $fila) {

                $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $fila['importe_es'], $caso . ': al primer mensaje.');
            }

            $propuesta = $this->proponer($conversation);

            $this->assertTrue($propuesta['ok'], $caso . ': ' . json_encode($propuesta, JSON_UNESCAPED_UNICODE));
            $this->assertStringContainsString('Total: $ 3.000', AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto'], $caso);
        }
    }

    /**
     * Sin la fila, la columna del comprobante vuelve a ser la del comprobante: es lo que decide el IVA del cliente nuevo.
     *
     * @test
     */
    public function la_fila_de_totales_no_le_cambia_el_rol_a_una_columna()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['C FINAL', '1.000,00', 'SKU 70101', 'Bomba', '#2000000000000001', '2', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['C FINAL', '2.000,00', 'SKU 70102', 'Bomba', '#2000000000000002', '3', 'DNI', '14000002', 'Bruno Prueba Dos'],
            ['TOTAL', '3.000,00', '', '', '', '5', '', '', ''],
        ]));

        $this->assertSame('C FINAL', PlanillaIaHelper::fila_numero($planilla, 1)['valores'][Roles::COMPROBANTE]);
    }

    /**
     * Por una foto, igual: se saca de cada lectura antes de compararlas, y si una la leyó y otra no, siguen siendo la misma tabla.
     *
     * @test
     */
    public function una_fila_de_totales_en_una_foto_no_entra_a_la_planilla()
    {
        $encabezado = ['DETALLE', 'CODIGO', 'CANTIDAD', 'IMPORTE', 'TIPO', 'DOCUMENTO', 'NOMBRE'];

        $filas = [
            ['Bomba', '70101', '2', '1.000,00', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['Bomba', '70102', '3', '2.000,00', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ];

        $con_total = array_merge($filas, [['TOTAL', '', '', '3.000,00', '', '', '']]);

        $this->lecturas = [
            'claude-sonnet-5-5' => $this->lectura($con_total, $encabezado),
            'claude-haiku-5-5'  => $this->lectura($filas, $encabezado),
            'claude-opus-5-5'   => $this->lectura($con_total, $encabezado),
        ];

        $this->falsear_ia();

        $conversation = $this->conversacion();

        $this->foto($this->del_dueno($conversation, ''));

        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));

        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($planilla);
        $this->assertCount(2, $planilla->filas);
        $this->assertCount(0, $this->lecturas_de('claude-opus-5-5'), 'Las dos lecturas dicen lo mismo: no hace falta el desempate.');

        $bueno = $this->definir_con($conversation, 'ES EL TOTAL', ['importe_es' => 'total']);

        $this->assertTrue($bueno['ok'], json_encode($bueno, JSON_UNESCAPED_UNICODE));

        foreach ($planilla->fresh()->filas as $fila) {

            $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $fila['importe_es'], 'Al primer mensaje.');
        }

        $this->assertTrue($this->proponer($conversation)['ok']);
    }

    /**
     * Con documento o código es una venta, aunque el texto diga «Total».
     *
     * @test
     */
    public function una_fila_con_documento_o_codigo_no_es_una_de_totales()
    {
        $titulos = ['DETALLE', 'CODIGO', 'CANTIDAD', 'IMPORTE', 'TIPO', 'DOCUMENTO', 'NOMBRE'];

        $ana = ['Bomba', '70101', '2', '1.000,00', 'DNI', '17000001', 'Ana Prueba Uno'];
        $con_codigo = ['Total', '70102', '', '3.000,00', '', '', ''];
        $con_documento = ['Total', '', '', '3.000,00', '', '14000002', ''];

        $this->assertCount(2, Roles::sin_filas_de_totales($titulos, [$ana, $con_codigo]));
        $this->assertCount(2, Roles::sin_filas_de_totales($titulos, [$ana, $con_documento]));
        $this->assertCount(1, Roles::sin_filas_de_totales($titulos, [$ana, ['Total', '', '', '3.000,00', '', '', '']]));

        // Otro texto al lado, o la palabra adentro de otra, tampoco.
        $this->assertCount(2, Roles::sin_filas_de_totales($titulos, [$ana, ['Total', 'SKU 70102', '', '3.000,00', '', '', '']]));
        $this->assertCount(2, Roles::sin_filas_de_totales($titulos, [$ana, ['Total Energies', '', '', '3.000,00', '', '', '']]));
        $this->assertCount(2, Roles::sin_filas_de_totales($titulos, [$ana, ['Sumatoria', '', '', '3.000,00', '', '', '']]));
    }

    /**
     * «ES EL TOTAL» no nombra un renglón aunque su descripción sea «TOTAL»: una frase hecha solo de palabras del importe no nombra nada.
     *
     * @test
     */
    public function una_frase_hecha_solo_de_palabras_del_importe_no_nombra_nada()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['DETALLE', 'CODIGO', 'CANTIDAD', 'IMPORTE', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['TOTAL', '70101', '2', '1.000,00', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['Precio Unidad', '70102', '3', '2.000,00', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $primera = PlanillaIaHelper::fila_numero($planilla, 1);
        $segunda = PlanillaIaHelper::fila_numero($planilla, 2);

        $this->assertFalse(Anclas::nombra_el_renglon(['numero' => 1, 'valores' => [Roles::DESCRIPCION => 'TOTAL']], 'ES EL TOTAL'));
        $this->assertFalse(Anclas::nombra_el_renglon(['numero' => 2, 'valores' => [Roles::DESCRIPCION => 'Precio Unidad']], 'precio unidad'));

        // Los códigos y las descripciones cortas siguen nombrando.
        $this->assertTrue(Anclas::nombra_el_renglon($primera, 'el 70101 son 3'));
        $this->assertTrue(Anclas::nombra_el_renglon(['numero' => 3, 'valores' => [Roles::DESCRIPCION => 'Led 30w']], 'el led 30w son 3'));

        $this->assertTrue($this->definir_con($conversation, 'ES EL TOTAL', ['importe_es' => 'total'])['ok']);

        foreach ($planilla->fresh()->filas as $fila) {

            $this->assertSame(AiPlanilla::IMPORTE_TOTAL, $fila['importe_es']);
        }

        $this->assertNotEmpty($segunda);
    }

    // ---------------------------------------------------------------------
    // V4: un renglón sin decidir descartado no revive
    // ---------------------------------------------------------------------

    /**
     * 🔴 «lo de Bruno no va», y después la tabla con títulos: volvía a pendiente, y se vendía lo que pidió sacar.
     *
     * @test
     */
    public function un_renglon_sin_decidir_descartado_no_revive_con_la_tabla_con_titulos()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation, $this->como_texto([
            ['Bomba', '500,00', '1.500', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['Bomba', '800,00', '12.500', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $this->assertTrue($this->corregir_con($conversation, 'lo de Bruno no va', ['renglon' => 2, 'campo' => 'descartar'])['ok']);

        $this->planilla_pegada($conversation, $this->como_texto([
            ['DETALLE', 'CODIGO', 'CANTIDAD', 'TOTAL', 'TIPO', 'DOCUMENTO', 'NOMBRE'],
            ['Bomba', '70101', '2', '1.500,00', 'DNI', '17000001', 'Ana Prueba Uno'],
            ['Bomba', '70102', '3', '12.500,00', 'DNI', '14000002', 'Bruno Prueba Dos'],
        ]));

        $planilla = $planilla->fresh();

        $this->assertCount(2, $planilla->filas);
        $this->assertSame(AiPlanilla::FILA_PENDIENTE, PlanillaIaHelper::fila_numero($planilla, 1)['estado']);
        $this->assertSame(AiPlanilla::FILA_DESCARTADA, PlanillaIaHelper::fila_numero($planilla, 2)['estado'], 'Lo que pidió sacar sigue afuera.');

        $propuesta = $this->proponer($conversation);

        $this->assertTrue($propuesta['ok'], json_encode($propuesta, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('1 venta', AiMessageAction::find($propuesta['tarjeta_id'])->datos['texto']);
    }

    // ---------------------------------------------------------------------
    // V5: la repregunta natural
    // ---------------------------------------------------------------------

    /**
     * 🔴 Los rechazos le piden al modelo preguntar de forma natural, y esas preguntas dejaban sin ancla la respuesta correcta.
     *
     * @test
     */
    public function la_repregunta_natural_ancla_la_respuesta()
    {
        foreach (['¿el total o el precio de cada una?', '¿Es el total o cada uno por separado?'] as $pregunta) {

            $this->assertSame(AiPlanilla::IMPORTE_TOTAL, Anclas::lo_que_dice_del_importe('el total', $pregunta), $pregunta);
            $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, Anclas::lo_que_dice_del_importe('cada una', $pregunta), $pregunta);
            $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, Anclas::lo_que_dice_del_importe('por cada uno', $pregunta), $pregunta);
        }

        $por_las_dos = '¿por las dos unidades o por cada una?';

        $this->assertSame(AiPlanilla::IMPORTE_UNIDAD, Anclas::lo_que_dice_del_importe('por cada una', $por_las_dos));
        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, Anclas::lo_que_dice_del_importe('por las dos', $por_las_dos));
        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, Anclas::lo_que_dice_del_importe('las 2 unidades', $por_las_dos));
    }

    /**
     * Y «unidades» sola no ancla nada, ni en la respuesta ni para decir que el mensaje nombra las dos cosas.
     *
     * @test
     */
    public function unidades_suelto_no_ancla_nada()
    {
        foreach (['¿el total o el precio de cada una?', '¿el total o por unidad?', '¿por las dos unidades o por cada una?'] as $pregunta) {

            $this->assertNull(Anclas::lo_que_dice_del_importe('unidades', $pregunta), $pregunta);
            $this->assertNull(Anclas::lo_que_dice_del_importe('las unidades', $pregunta), $pregunta);
        }

        $this->assertNull(Anclas::lo_que_dice_del_importe('son dos unidades', '¿el total o por unidad?'));
        $this->assertNull(Anclas::lo_que_dice_del_importe('dos', '¿el total o por unidad?'));
        $this->assertNull(Anclas::lo_que_dice_del_importe('dos', '¿cuántas unidades lleva cada una?'));

        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, Anclas::lo_que_dice_del_importe('SON DOS UNIDADES ES EL TOTAL'));
        $this->assertSame(AiPlanilla::IMPORTE_TOTAL, Anclas::lo_que_dice_del_importe('es el total, cada una con su factura'));
        $this->assertNull(Anclas::lo_que_dice_del_importe('En total son 11 ventas, cada una con su factura'));
    }

    // ---------------------------------------------------------------------
    // V6: «Sacá todas menos la de Bruno»
    // ---------------------------------------------------------------------

    /**
     * 🔴 Pasaba con renglon=todos y descartaba también a Bruno.
     *
     * @test
     */
    public function sacar_todas_menos_una_no_descarta_la_planilla()
    {
        $conversation = $this->conversacion();

        $planilla = $this->planilla_pegada($conversation);

        foreach (['Sacá todas menos la de Bruno', 'no cargues ninguna salvo la de Ana', 'borrá todas excepto la de Carla'] as $mensaje) {

            $rechazo = $this->corregir_con($conversation, $mensaje, ['renglon' => 'todos', 'campo' => 'descartar']);

            $this->assertFalse($rechazo['ok'], $mensaje . ': ' . json_encode($rechazo, JSON_UNESCAPED_UNICODE));
            $this->assertSame('sin_ancla', $rechazo['codigo'], $mensaje);
            $this->assertStringContainsString('de a un renglón', $rechazo['error'], $mensaje);
            $this->assertSame(AiPlanilla::ESTADO_ACTIVA, $planilla->fresh()->estado, $mensaje);
            $this->assertCount(4, PlanillaIaHelper::filas_por_cargar($planilla->fresh()), $mensaje);
        }

        // De a un renglón, sí.
        $this->assertTrue($this->corregir_con($conversation, 'sacá lo de Ana', ['renglon' => 1, 'campo' => 'descartar'])['ok']);
        $this->assertCount(3, PlanillaIaHelper::filas_por_cargar($planilla->fresh()));
    }
}
