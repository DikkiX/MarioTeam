<?php
/*
Het voegt target="_top" toe aan externe links als dit nog niet aanwezig is.
Het voegt een queryparameter chat=1 toe aan externe links, terwijl bestaande queryparameters behouden blijven.
*/
function perfectLink($string) {
	
    $dom = new DOMDocument();
    // Voorkom waarschuwingen bij het laden van HTML
    libxml_use_internal_errors(true);
    
        // Zorg ervoor dat de string correct wordt geconverteerd naar UTF-8
    $string = mb_convert_encoding($string, 'HTML-ENTITIES', 'UTF-8');
    
    //$dom->loadHTML($string, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    // Laad de HTML (forceer UTF-8 compatibiliteit)
    $dom->loadHTML('<?xml encoding="UTF-8">' . $string, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    
    libxml_clear_errors();

    $links = $dom->getElementsByTagName('a');

    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        $target = $link->getAttribute('target');

        // Controleer of het een externe link is (begint met http of https)
        if (preg_match('/^https?:\/\//i', $href)) {
            // Voeg target='_top' toe als het nog niet aanwezig is
            if (strtolower($target) !== '_top') {
                $link->setAttribute('target', '_top');
            }

            // Parse de URL om bestaande queryparameters te behouden
            $parsed_url = parse_url($href);

            // Initialiseer de nieuwe href
            $new_href = '';

            // Voeg schema en host toe
            if (isset($parsed_url['scheme']) && isset($parsed_url['host'])) {
                $new_href .= $parsed_url['scheme'] . '://' . $parsed_url['host'];
            } else {
                // Als schema of host ontbreekt, sla de link over
                continue;
            }

            // Voeg poort toe indien aanwezig
            if (isset($parsed_url['port'])) {
                $new_href .= ':' . $parsed_url['port'];
            }

            // Voeg pad toe indien aanwezig
            if (isset($parsed_url['path'])) {
                $new_href .= $parsed_url['path'];
            }

            // Verwerk de querystring
            $query_params = [];
            if (isset($parsed_url['query'])) {
                parse_str($parsed_url['query'], $query_params);
            }
            $query_params['chat'] = '1';
            $new_query = http_build_query($query_params);

            // Voeg de nieuwe querystring toe
            if (!empty($new_query)) {
                $new_href .= '?' . $new_query;
            }

            // Voeg fragment toe indien aanwezig
            if (isset($parsed_url['fragment'])) {
                $new_href .= '#' . $parsed_url['fragment'];
            }

            // Stel de nieuwe href in
            $link->setAttribute('href', $new_href);
        }
    }

    return $dom->saveHTML();
}

?>