<?php

namespace App\Services\ProductFacts;

/**
 * El dueño de una demo ya usó sus TopeSerperDemo::TOPE búsquedas de Serper (misión tope-serper-demos,
 * 23/10/2026). La tira TopeSerperDemo::ejecutar() ANTES de llamar: no se salió a la red.
 *
 * Extiende ProductFactsException a propósito: su mensaje está escrito para la persona y, en la ficha
 * por código de barras, viaja tal cual hasta la tarea `failed` (ProcessProductEnrichmentJob solo deja
 * pasar al navegador los mensajes de esa clase). El buscador de imágenes la ataja aparte y la convierte
 * en un fallo con `tope_demo => true` (SerperImageSearchProvider::buscar()).
 */
class TopeSerperDemoAgotado extends ProductFactsException
{
}
