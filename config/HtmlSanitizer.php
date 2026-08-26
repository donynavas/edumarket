<?php
/**
 * Sanitizador de HTML compartido para todo el proyecto -- extraído de
 * config/ManualConvivenciaHelper.php (donde se creó originalmente para el
 * editor de texto enriquecido del Manual de Convivencia) para poder
 * reutilizarse tal cual en cualquier otro campo con barra de herramientas
 * TinyMCE (p.ej. modules/profesor/impartir_clase.php). Es la ÚNICA
 * implementación -- ManualConvivenciaHelper::sanitizarHtml() ahora es un
 * alias delgado hacia HtmlSanitizer::limpiar() para no romper el código ya
 * existente que lo llama.
 */
class HtmlSanitizer
{
    /**
     * Limpia el HTML que produce la barra de herramientas de un editor de
     * texto enriquecido (negrita, cursiva, subrayado, alineación,
     * tamaño/tipo de fuente y color vía estilo en línea, tablas) antes de
     * guardarlo -- el editor corre en el navegador del usuario, así que el
     * servidor nunca debe confiar en el HTML que llega por POST tal cual.
     * Se usa una lista blanca de etiquetas (vía strip_tags) y luego se
     * recorre el DOM para quitar cualquier atributo peligroso (onclick,
     * onerror, etc.) de las etiquetas que sí se permiten.
     */
    public static function limpiar(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        $etiquetasPermitidas = '<p><br><strong><b><em><i><u><span><div><ul><ol><li><table><thead><tbody><tfoot><tr><td><th>';
        $limpio = strip_tags($html, $etiquetasPermitidas);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $limpio . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $atributosPermitidos = ['style', 'colspan', 'rowspan'];
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//*') as $nodo) {
            if (!($nodo instanceof DOMElement)) {
                continue;
            }
            $aQuitar = [];
            foreach (iterator_to_array($nodo->attributes) as $attr) {
                $nombre = strtolower($attr->name);
                $valor = $attr->value;
                $peligroso = str_starts_with($nombre, 'on')
                    || !in_array($nombre, $atributosPermitidos, true)
                    || ($nombre === 'style' && (stripos($valor, 'expression(') !== false || stripos($valor, 'javascript:') !== false));
                if ($peligroso) {
                    $aQuitar[] = $attr->name;
                }
            }
            foreach ($aQuitar as $nombreAttr) {
                $nodo->removeAttribute($nombreAttr);
            }
        }

        $contenedor = $doc->getElementsByTagName('div')->item(0);
        $resultado = '';
        if ($contenedor) {
            foreach ($contenedor->childNodes as $child) {
                $resultado .= $doc->saveHTML($child);
            }
        }
        return $resultado;
    }
}
