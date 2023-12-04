@component('mail::message')

# Buenos dias {{ $nombre_negocio }}

<h2>En ComercioCity ayudamos a empresas de venta mayorista (fabricante, importador o distribuidor), a automatizar y organizar sus procesos en menos de 2 meses con nuestro software de Transformación Digital.</h2>

# Nuestro objetivo es que en menos de 2 meses tengas todas tus operaciones comerciales integradas en una única plataforma.


Tu empresa no necesita solo un sistema informático, necesita <strong>UNA SOLUCIÓN</strong> que englobe todos los procesos de manejo de información de tu negocio, y también necesitas la ayuda para implementar y poner en marcha este tipo de tecnología.

# Nuestra mision es:

@component('mail::panel')
Comprender exactamente lo que tu empresa necesita, para entregarles un software personalizado y adaptado a sus demandas.
@endcomponent

@component('mail::panel')
Que todo tus empleados comprendan como utilizar la plataforma, por lo que nos ocupamos de su capacitación.
@endcomponent

@component('mail::panel')
Que cuentes con las herramientas para tener presencia en este nuevo mundo digital, por lo que desarrollamos y mantenemos tu PROPIO e-commerce "todo-contructor.com". Somos la ÚNICA empresa que te brinda la solución de tener tu propia pagina web completamente integrada y sincronizada con tu sistema de gestión, para que puedas brindarles a tus clientes la posibilidad de ver tus productos, las compras que han realizado, y toda la información <strong>actualizada segundo a segundo</strong> que tu negocio necesite facilitarles. Esta web, al igual que el sistema, es 100% adaptada a tu modelo de negocios, por ejemplo, podemos tener en cuenta si utilizas distintos precios para tus clientes dependiendo su perfil de compra, basicamente cualquier funcionalidad que necesites. 
@endcomponent

<h2>En caso de estar interesado, podemos agendarle una llamada con nuestro equipo de ventas, para que le expliquen adecuadamente en que consiste nuestra solución para su negocio.</h2>


<h2>Tenemos un precio personalizado acorde al tamaño de su empresa, por lo que somos el sistema mas competitivo en base a lo que ofrecemos y lo que cobramos.</h2>


@component('mail::panel')
Experimenta el rendimiento y comodidad que un buen sistema de automatización tiene para ofrece.
@endcomponent


@component('mail::button', ['color' => 'success', 'url' => 'https://api.whatsapp.com/send?phone=3444622139'])
Agendar una llamada para despejar dudas
@endcomponent

<h2>Te dejamos un pequeño resumen de lo que nuestro sistema tiene para ofrecerte</h2>

@component('mail::panel')
<h2>INVENTARIO</h2>
<p>Lleva el control del stock de tus artículos, con la posibilidad de dividirlo en múltiple depósitos.</p>
@endcomponent


@component('mail::panel')
<h2>VENTAS</h2>
<p>Realiza ventas, anónimas o a clientes de tu sistema, para que impacte en sus cuentas corrientes.</p>
<p>Consulta las ventas realizadas durante cualquier periodo de tiempo.</p>
@endcomponent


@component('mail::panel')
<h2>PAGINA WEB / E-COMMERECE</h2>
<p>Ofrece a tus clientes una tienda online para que puedan realizar sus pedidos de una forma sencilla y practica.</p>
<p>Disfruta la ventaja de tener presencia en internet, y sin tener que gastar tiempo y esfuerzo en mantener una pagina web. ¡Te lo damos todo resuelto!</p>
@endcomponent


@component('mail::panel')
<h2>MODULO DE PRODUCCION</h2>
<p>En caso de que tu empresa realice procesos productivos, contamos con un modulo especializado para que puedas hacer el seguimiento de tus procesos de fabricación y todo lo que eso implica:</p>
<p>Descuento de stock en los insumos.</p>
<p>Registro de los avances en cada etapa del proceso productivo.</p>
<p>Obtención de costos de producción.</p>
@endcomponent


<h2>Cualquier duda que tengas no dudes en comunicarte, respondiendo a este mail, o mediante el WhatsApp que te dejamos a continuación</h2>


@component('mail::button', ['color' => 'success', 'url' => 'https://api.whatsapp.com/send?phone=3444622139'])
Ir a WhatsApp
@endcomponent


<img src="https://comerciocity.com/img/logo.cc4cb183.png" class="comerciocity-logo" alt="Logo">
<div class="footer">
<p>Equipo de ventas de ComercioCity</p>
</div> 
@endcomponent
