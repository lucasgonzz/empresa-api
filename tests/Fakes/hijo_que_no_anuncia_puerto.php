<?php

/*
 * Un "servidor" que NUNCA anuncia su puerto: duerme sin imprimir nada.
 *
 * Es solo para probar `ServidorSmtpFake::levantar()` ante un hijo colgado (un cuelgue de arranque): el padre tiene que dejar de esperar a los pocos
 * segundos que se le den y devolver null, en vez de quedarse esperando para siempre. Se lanza con los mismos argumentos que `servidor_smtp_fake.php`
 * (`<modo> <segundos de vida>`) y se apaga solo a los 60 segundos por si el test que lo lanzó muere sin poder bajarlo.
 */

sleep(60);
