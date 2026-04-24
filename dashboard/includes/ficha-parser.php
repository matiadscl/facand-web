<?php
declare(strict_types=1);

function getClientesDir(): string {
    return '/home/coder/facand/agentes/shared/clients/';
}

function listClientesFichas(): array {
    $dir = getClientesDir();
    $files = glob($dir . '*.md');
    $result = [];
    foreach ($files as $f) {
        $slug = basename($f, '.md');
        $content = file_get_contents($f);
        $nombre = '';
        if (preg_match('/^#\s+(.+?)(?:\s*—|-)\s*/m', $content, $m)) {
            $nombre = trim($m[1]);
        }
        $result[$slug] = ['slug' => $slug, 'nombre' => $nombre, 'path' => $f];
    }
    return $result;
}

function parseAllFichas(): array {
    $fichas = listClientesFichas();
    $result = [];
    foreach ($fichas as $slug => $meta) {
        $ficha = parseClienteFicha($slug);
        if ($ficha) $result[$slug] = $ficha;
    }
    return $result;
}

function parseClienteFicha(string $slug): ?array {
    $path = getClientesDir() . $slug . '.md';
    if (!file_exists($path)) return null;
    $content = file_get_contents($path);

    $data = [
        'slug' => $slug,
        'nombre' => '',
        'ultima_actualizacion' => '',
        'info' => [],
        'estado_pagos' => '',
        'etapa' => '',
        'plan' => '',
        'fee' => '',
        'servicios' => [],
        'presupuesto' => [],
        'herramientas' => [],
        'pendientes' => [],
        'pendientes_done' => [],
        'equipo' => [],
    ];

    // Title
    if (preg_match('/^#\s+(.+?)(?:\s*—|-)\s*/m', $content, $m)) {
        $data['nombre'] = trim($m[1]);
    }

    // Last update
    if (preg_match('/\*\*Última actualización:\*\*\s*(.+)/i', $content, $m)) {
        $data['ultima_actualizacion'] = trim($m[1]);
    }

    // Info General table - only match tables within "## Info General" section
    $infoSection = extractSection($content, '## Info General');
    if ($infoSection) {
        if (preg_match_all('/\|\s*(.+?)\s*\|\s*(.+?)\s*\|/m', $infoSection, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $row) {
                $key = trim($row[1]);
                $val = trim($row[2]);
                if ($key === 'Campo' || str_starts_with($key, '-')) continue;
                $data['info'][$key] = preg_replace('/\*\*(.+?)\*\*/', '$1', $val);
            }
        }
    }

    // Estado de pagos
    if (preg_match('/## Estado de pagos\s*\n(.+)/m', $content, $m)) {
        $data['estado_pagos'] = trim(preg_replace('/[-*]/', '', $m[1]));
    }

    // Etapa
    if (preg_match('/## Etapa:\s*(.+)/i', $content, $m)) {
        $data['etapa'] = trim($m[1]);
    }

    // Plan & Fee from info table
    $data['plan'] = $data['info']['Plan'] ?? $data['info']['Plan actual'] ?? '';
    $data['fee'] = $data['info']['Fee'] ?? $data['info']['Fee actual'] ?? '';

    // Servicios contratados
    $srvSection = extractSection($content, '### Servicios contratados');
    if (!$srvSection) $srvSection = extractSection($content, '### Servicios activos');
    if ($srvSection) {
        $data['servicios'] = extractListItems($srvSection);
    }

    // Presupuesto
    $presSection = extractSection($content, '### Presupuesto publicitario');
    if (!$presSection) $presSection = extractSection($content, '### Presupuesto');
    if ($presSection) {
        $data['presupuesto'] = extractListItems($presSection);
    }

    // Herramientas
    $herrSection = extractSection($content, '### Herramientas de gestión');
    if ($herrSection) {
        $data['herramientas'] = extractListItems($herrSection);
    }

    // Pendientes
    $pendSection = extractSection($content, '## Pendientes');
    if ($pendSection) {
        foreach (explode("\n", $pendSection) as $line) {
            $line = trim($line);
            if ($line === '' || !preg_match('/^[-*]\s/', $line)) continue;
            $text = trim(preg_replace('/^[-*]\s*\[.\]\s*/', '', $line));
            $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text);
            if (str_contains($line, '[x]') || str_contains($line, '[X]')) {
                $data['pendientes_done'][] = $text;
            } else {
                $data['pendientes'][] = $text;
            }
        }
    }

    // Equipo table
    $equipoSection = extractSection($content, '## Equipo');
    if ($equipoSection) {
        if (preg_match_all('/\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|/m', $equipoSection, $eq, PREG_SET_ORDER)) {
            foreach ($eq as $row) {
                $persona = trim($row[1]);
                if ($persona === 'Persona' || str_starts_with($persona, '-')) continue;
                $data['equipo'][] = [
                    'persona' => $persona,
                    'rol' => trim($row[2]),
                    'acceso' => trim($row[3]),
                ];
            }
        }
    }

    return $data;
}

/**
 * Extract content of a markdown section (from header to next header of same or higher level)
 */
function extractSection(string $content, string $header): ?string {
    $level = substr_count(explode(' ', $header)[0], '#');
    $pattern = '/^' . str_repeat('#', $level) . '\s+' . preg_quote(trim(ltrim($header, '# ')), '/') . '.*?\n(.*?)(?=\n#{1,' . $level . '}\s|\z)/si';
    if (preg_match($pattern, $content, $m)) {
        return trim($m[1]);
    }
    // Fallback: simpler approach
    $pos = stripos($content, $header);
    if ($pos === false) return null;
    $start = strpos($content, "\n", $pos);
    if ($start === false) return null;
    $start++;
    // Find next header of same or higher level
    $headerPrefix = str_repeat('#', $level);
    $remaining = substr($content, $start);
    $endPattern = '/\n#{1,' . $level . '}\s/';
    if (preg_match($endPattern, $remaining, $m, PREG_OFFSET_CAPTURE)) {
        return trim(substr($remaining, 0, $m[0][1]));
    }
    return trim($remaining);
}

/**
 * Extract list items (lines starting with - or *) from a text block
 */
function extractListItems(string $text): array {
    $items = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '' || !preg_match('/^[-*]\s/', $line)) continue;
        $clean = preg_replace('/^[-*]\s+/', '', $line);
        $clean = preg_replace('/\*\*(.+?)\*\*/', '$1', $clean);
        $items[] = $clean;
    }
    return $items;
}
