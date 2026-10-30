<?php

/*
 * Integraciones: MCP servers and Google Analytics. Reading tools run at once;
 * one that could change something ends as a proposal.
 */

$reads = ['mcp_tools', 'mcp_call', 'analytics_report'];

return [
    ['ask' => '¿Qué herramientas tiene el servidor MCP de Linear?', 'any_tool' => ['mcp_tools'], 'proposes' => false],
    ['ask' => 'Busca en Linear los issues abiertos del prepago.', 'any_tool' => ['mcp_call'], 'proposes' => null],
    ['ask' => 'Crea en Linear un issue para el bug del checkout.', 'any_tool' => ['mcp_call'], 'proposes' => true],
    ['ask' => '¿Cuántos usuarios tuvo la web la semana pasada?', 'any_tool' => ['analytics_report'], 'proposes' => false],
    ['ask' => 'Enséñame las sesiones por canal de este mes.', 'any_tool' => ['analytics_report'], 'proposes' => false],
    ['ask' => '¿Qué páginas son las más vistas en los últimos 7 días?', 'any_tool' => ['analytics_report'], 'proposes' => false],
    ['ask' => 'Compara usuarios de este mes con el anterior en Analytics.', 'any_tool' => ['analytics_report'], 'proposes' => false],
    ['ask' => '¿De qué países vienen las visitas?', 'any_tool' => ['analytics_report'], 'proposes' => false],
    ['ask' => '¿Qué tal convierte la página de precios?', 'any_tool' => ['analytics_report'], 'proposes' => false],
    ['ask' => 'Mira en el servidor MCP si hay incidencias abiertas.', 'any_tool' => $reads, 'proposes' => null],
    ['ask' => 'Cierra en Linear el issue del checkout.', 'any_tool' => ['mcp_call'], 'proposes' => true],
    ['ask' => 'Asigna a Pablo el issue del checkout en Linear.', 'any_tool' => ['mcp_call'], 'proposes' => true],
    ['ask' => 'Comenta en Linear que el fix sale el jueves.', 'any_tool' => ['mcp_call'], 'proposes' => true],
    ['ask' => '¿Qué servidores MCP tengo conectados y qué pueden hacer?', 'any_tool' => ['mcp_tools'], 'proposes' => false],
    ['ask' => '¿Cuántas sesiones vinieron de búsqueda orgánica ayer?', 'any_tool' => ['analytics_report'], 'proposes' => false],
    ['ask' => 'Dame la tasa de rebote de la home esta semana.', 'any_tool' => ['analytics_report'], 'proposes' => false],
    ['ask' => '¿Ha subido el tráfico desde el lanzamiento?', 'any_tool' => ['analytics_report'], 'proposes' => false],
    ['ask' => 'Lista los proyectos de Linear.', 'any_tool' => ['mcp_call', 'mcp_tools'], 'proposes' => null],
    ['ask' => '¿Qué eventos de conversión hubo el último mes?', 'any_tool' => ['analytics_report'], 'proposes' => false],
    ['ask' => 'Busca en el servidor MCP los clientes con más incidencias.', 'any_tool' => $reads, 'proposes' => null],
];
