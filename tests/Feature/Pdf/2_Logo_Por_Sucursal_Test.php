<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests del logo por sucursal en los comprobantes (tarea 17, 11/8/2026).
 *
 * Lo que se testea es el RESOLVEDOR, no el PDF renderizado: comparar bytes de un PDF es
 * fragil y no dice nada util. `AfipPdfHelper::resolve_logo_url()` es el unico lugar donde
 * se decide de donde sale el logo, asi que es tambien el unico lugar donde puede romperse
 * la regla.
 *
 * La regla: gana el logo de la sucursal; si la sucursal no tiene uno cargado, se usa el del
 * negocio, exactamente como antes de este cambio. Un negocio sin ninguna sucursal con logo
 * no ve ninguna diferencia en ningun comprobante.
 *
 * Los casos del resolvedor no tocan la base: los modelos se instancian sin guardar. El unico
 * que si la toca es el de persistencia de la columna, con DatabaseTransactions.
 *
 * @group pdf-logo-sucursal
 */
class Logo_Por_Sucursal_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario dueño de mentira, sin guardar: al resolvedor solo le importa image_url.
     *
     * @param string|null $image_url
     * @return \App\Models\User
     */
    protected function usuario_con_logo($image_url)
    {
        $user = new User();
        $user->image_url = $image_url;

        return $user;
    }

    /**
     * Sucursal de mentira, sin guardar.
     *
     * @param string|null $image_url
     * @return \App\Models\Address
     */
    protected function sucursal_con_logo($image_url)
    {
        $address = new Address();
        $address->street = 'zz Sucursal de test';
        $address->image_url = $image_url;

        return $address;
    }

    /**
     * Criterio 2: una venta desde una sucursal CON logo sale con el logo de la sucursal.
     *
     * @test
     */
    public function el_logo_de_la_sucursal_le_gana_al_del_negocio()
    {
        $resuelto = AfipPdfHelper::resolve_logo_url(
            $this->sucursal_con_logo('https://ejemplo/logo-sucursal.webp'),
            $this->usuario_con_logo('https://ejemplo/logo-negocio.png')
        );

        $this->assertEquals('https://ejemplo/logo-sucursal.webp', $resuelto);
    }

    /**
     * Criterio 3 y 6: una sucursal sin logo cargado se comporta como antes del cambio.
     * El null y la cadena vacia son el mismo caso: "no cargo nada".
     *
     * @test
     */
    public function la_sucursal_sin_logo_cae_al_logo_del_negocio()
    {
        $con_null = AfipPdfHelper::resolve_logo_url(
            $this->sucursal_con_logo(null),
            $this->usuario_con_logo('https://ejemplo/logo-negocio.png')
        );

        $con_vacio = AfipPdfHelper::resolve_logo_url(
            $this->sucursal_con_logo(''),
            $this->usuario_con_logo('https://ejemplo/logo-negocio.png')
        );

        $con_espacios = AfipPdfHelper::resolve_logo_url(
            $this->sucursal_con_logo('   '),
            $this->usuario_con_logo('https://ejemplo/logo-negocio.png')
        );

        $this->assertEquals('https://ejemplo/logo-negocio.png', $con_null);
        $this->assertEquals('https://ejemplo/logo-negocio.png', $con_vacio);
        $this->assertEquals('https://ejemplo/logo-negocio.png', $con_espacios);
    }

    /**
     * Criterio 3 y 4: una venta sin sucursal asignada, o el recibo de un cliente sin
     * sucursal (y toda orden de pago a proveedor, que nunca tiene), usa el del negocio.
     *
     * @test
     */
    public function sin_sucursal_usa_el_logo_del_negocio()
    {
        $resuelto = AfipPdfHelper::resolve_logo_url(
            null,
            $this->usuario_con_logo('https://ejemplo/logo-negocio.png')
        );

        $this->assertEquals('https://ejemplo/logo-negocio.png', $resuelto);
    }

    /**
     * Un negocio que nunca cargo ningun logo: el resolvedor devuelve null y cada punto de
     * impresion no imprime nada, igual que hoy. Si devolviera '' en vez de null, los
     * `!is_null($logo_url)` de los tickets darian true y FPDF recibiria una ruta vacia.
     *
     * @test
     */
    public function sin_ningun_logo_devuelve_null()
    {
        $sin_nada = AfipPdfHelper::resolve_logo_url(
            $this->sucursal_con_logo(null),
            $this->usuario_con_logo(null)
        );

        $usuario_vacio = AfipPdfHelper::resolve_logo_url(
            null,
            $this->usuario_con_logo('')
        );

        $this->assertNull($sin_nada);
        $this->assertNull($usuario_vacio);
    }

    /**
     * Criterio 1: la columna nueva existe y persiste. Es el otro extremo de la cadena —
     * el ABM guarda ahi via el endpoint generico set-image, y los PDFs leen de ahi.
     *
     * @test
     */
    public function la_sucursal_guarda_su_logo_en_la_base()
    {
        $user = User::find(500);
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $address = Address::create([
            'street' => 'zz Sucursal con logo',
            'user_id' => 500,
            'image_url' => 'https://ejemplo/storage/logo-sucursal.webp',
        ]);

        $desde_la_base = Address::find($address->id);

        $this->assertEquals('https://ejemplo/storage/logo-sucursal.webp', $desde_la_base->image_url);
    }

    /**
     * La sucursal de un cliente se pide por la relacion y NO por $client->address, que en
     * clients es la columna de domicilio (texto libre). Este test existe porque el nombre
     * colisiona y la forma obvia de escribirlo devuelve una cadena en vez de la sucursal:
     * asi el recibo de pago sacaria el logo de un string.
     *
     * @test
     */
    public function la_sucursal_del_cliente_se_resuelve_por_la_relacion_y_no_por_la_columna()
    {
        $user = User::find(500);
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $address = Address::create([
            'street' => 'zz Sucursal del cliente',
            'user_id' => 500,
            'image_url' => 'https://ejemplo/storage/logo-de-la-sucursal-del-cliente.webp',
        ]);

        $client = \App\Models\Client::create([
            'name' => 'zz Cliente con sucursal y domicilio',
            'user_id' => 500,
            'address' => 'Av. Siempreviva 742',
            'address_id' => $address->id,
        ]);

        $recargado = \App\Models\Client::find($client->id);

        // La forma obvia devuelve el domicilio, no la sucursal: por eso NewPagoPdf usa address()->first().
        $this->assertEquals('Av. Siempreviva 742', $recargado->address);

        $sucursal = $recargado->address()->first();

        $this->assertNotNull($sucursal);
        $this->assertEquals($address->id, $sucursal->id);

        $resuelto = AfipPdfHelper::resolve_logo_url($sucursal, $this->usuario_con_logo('https://ejemplo/logo-negocio.png'));

        $this->assertEquals('https://ejemplo/storage/logo-de-la-sucursal-del-cliente.webp', $resuelto);
    }

    /**
     * Criterio 8, y el test que mas va a durar: NINGUN comprobante puede resolver el logo
     * por su cuenta. La regla no es estetica — el dia que alguien escriba a mano
     * `$address->image_url ? ... : $user->image_url` en uno solo de los seis puntos de
     * impresion, esa copia se queda atras y el cliente ve el logo equivocado en un
     * comprobante y el correcto en otro, sin que nada falle.
     *
     * Se testea leyendo el codigo fuente porque los PDFs de este proyecto NO son
     * ejecutables desde PHPUnit: cada clase hace `require` de fpdf.php (sin _once) y FPDF
     * escribe binario directo a stdout, asi que en el mismo proceso da "Constant
     * FPDF_VERSION already defined" y en proceso separado el hijo muere. Medido el
     * 11/8/2026 con una sonda; ver el INFORME de la tarea 17.
     *
     * @test
     */
    public function ningun_comprobante_resuelve_el_logo_por_su_cuenta()
    {
        $comprobantes = [
            app_path('Http/Controllers/Pdf/SaleAfipTicketPdf.php'),
            app_path('Http/Controllers/Pdf/SaleTicketPdf.php'),
            app_path('Http/Controllers/Pdf/BudgetPdf.php'),
            app_path('Http/Controllers/Pdf/CurrentAcount/NewPagoPdf.php'),
        ];

        foreach ($comprobantes as $ruta) {
            $this->assertFileExists($ruta);

            $codigo = file_get_contents($ruta);

            $this->assertFalse(
                strpos($codigo, 'user->image_url') !== false,
                basename($ruta).' lee user->image_url directamente. El logo se resuelve SOLO con '
                    .'AfipPdfHelper::resolve_logo_url($address, $user).'
            );

            $this->assertTrue(
                strpos($codigo, 'resolve_logo_url') !== false,
                basename($ruta).' no llama a resolve_logo_url: si dejo de imprimir el logo, decilo '
                    .'en el INFORME; si lo imprime, tiene que salir del resolvedor unico.'
            );
        }

        /**
         * El helper es el unico que puede nombrar user->image_url, y solo adentro del
         * resolvedor. print_logo_image tiene que pedirselo a el, no leerlo por su cuenta.
         */
        $helper = file_get_contents(app_path('Http/Controllers/Pdf/Afip/AfipPdfHelper.php'));

        $this->assertTrue(
            strpos($helper, '$logo_url = self::resolve_logo_url($address, $user);') !== false,
            'print_logo_image tiene que resolver el logo con resolve_logo_url, no leer user->image_url.'
        );
    }
}
