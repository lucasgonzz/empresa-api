@component('mail::message')

# Buenos dias {{ $nombre_negocio }}

Ayer nos comunicamos con Gonzalo y nos brindo este email para poder ponernos en contacto con su negocio.

Estamos en conocimiento de tus necesidades para organizar y gestionar fácilmente tu negocio.
Somos una empresa de tecnologia y hemos desarrollado el software hecho a medida para las pequeñas y medianas empresas argentinas.

Tu empresa no necesita solo un sistema informático, necesita <strong>UNA SOLUCIÓN</strong> que englobe todos los procesos de manejo de información de tu negocio, y también necesitas la ayuda para implementar y poner en marcha este tipo de tecnología.

# Nuestra mision es:

@component('mail::panel')
Ayudarte con los procesos de carga de tu información: artículos (inventario), clientes, metodos de pago, información de facturación, etc. Por lo que te brindamos el servicio de data entry, para que te despreocupes de la principal barrera de entrada para muchas empresas. Sabemos que no cuentan con el tiempo ni el conocimiento (al menos en un principio) para ponerse a ingresar toda su información, por lo que tenemos un equipo listo para trabajar en junto a vos.
@endcomponent

@component('mail::panel')
Que todo tus empleados comprendan como utilizar el sistema, por lo que nos ocupamos de la su capacitación.
@endcomponent

@component('mail::panel')
Que cuentes con las herramientas para tener presencia en este nuevo mundo digital, por lo que desarrollamos y mantenemos tu PROPIA tienda web "visual-grafica-profesional.com.ar". Somos la ÚNICA empresa que te brinda la solución de tener tu propia pagina web completamente integrada y sincronizada con tu sistema de gestión, por lo que la pagina web se mantiene practicamente por su cuenta.
@endcomponent

<h2>En caso de estar interesado, podemos agendarle una llamada con nuestro equipo de ventas, para que le expliquen adecuadamente en que consiste nuestra solución para su negocio.</h2>


<h2>Tenemos un precio personalizado acorde al tamaño de su empresa, por lo que somos el sistema mas competitivo en base a lo que ofrecemos y lo que cobramos.</h2>


@component('mail::panel')
Experimenta el rendimiento y comodidad que un buen sistema de gestión te ofrece.
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
<p>Realiza ventas <strong>en negro</strong> o <strong>en blanco</strong>, anónimas o a clientes de tu sistema, para que impacte en sus cuentas corrientes.</p>
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


<img src="https://comerciocity.com/img/logo.e0805ee3.png" class="comerciocity-logo" alt="Logo">
<div class="footer">
<p>Equipo de ventas de ComercioCity</p>
</div> 
@endcomponent
