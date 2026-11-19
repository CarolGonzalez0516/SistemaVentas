<?php

/*
| Configuración de la IA de reposición (HU de sugerencia de reposición).
| La llave del modelo de lenguaje se lee SOLO desde variables de entorno.
*/

return [
    // Días de historial de ventas que se usan para el promedio móvil
    'ventana_dias' => (int) env('IA_VENTANA_DIAS', 28),

    // Días de venta que debería cubrir el stock después de reponer
    'dias_cobertura_objetivo' => (int) env('IA_DIAS_COBERTURA', 14),

    // Por debajo de estos días de cobertura la sugerencia es urgente
    'dias_alerta' => (int) env('IA_DIAS_ALERTA', 5),

    'llm' => [
        // none | anthropic
        'driver' => env('IA_LLM_DRIVER', 'none'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-5-5'),
        'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1/messages'),
        'timeout' => (int) env('IA_LLM_TIMEOUT', 15),
        'max_tokens' => (int) env('IA_LLM_MAX_TOKENS', 400),
    ],
];
