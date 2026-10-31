<?php

/*
|--------------------------------------------------------------------------
| Agents
|--------------------------------------------------------------------------
|
| The brain behind the voice. Gemini Live hears, shows and speaks; what
| reasons, reads services or writes runs here, as an orchestrator and atomic
| agents. Each agent is configured on its own: change one's model, effort or
| queue and the rest stay as they are.
|
| Model "weights" per agent: with reasoning on, DeepSeek ignores the
| temperature, so what tunes it is the effort (low, high, max); the
| temperature only counts for an agent without reasoning.
|
| Every write is a proposal that waits for the person's "sí", whichever agent
| proposes it. Prices (USD per million tokens) are per model and optional: a
| budget is only enforced where the price is known.
|
*/

$tools = [
    'comunicacion' => [
        'list_alerts', 'review_alert', 'show_slack_unread', 'show_slack_conversation', 'search_slack_messages',
        'find_slack_conversations', 'find_slack_people', 'open_slack_file', 'search_emails', 'show_email_results',
        'open_email', 'open_email_attachment', 'propose_slack_message', 'propose_slack_reaction', 'propose_new_email',
        'propose_email_reply', 'propose_email_forward', 'propose_meeting_action', 'search_knowledge', 'read_knowledge', 'use_sense_skill',
    ],
    'agenda' => [
        'find_calendar_events', 'show_calendar', 'propose_new_event', 'propose_event_invite', 'propose_event_summary',
        'propose_event_response', 'propose_event_update', 'propose_event_cancellation', 'schedule_task', 'cancel_scheduled_task',
        'search_emails', 'open_email', 'find_slack_people',
    ],
    'investigacion' => [
        'search_knowledge', 'read_knowledge', 'search_jira', 'open_jira_issue', 'search_notion', 'open_notion_page',
        'search_documents', 'show_document_results', 'find_files', 'open_document', 'list_sense_files', 'read_sense_file',
        'search_emails', 'open_email', 'search_slack_messages', 'show_slack_conversation', 'find_calendar_events', 'use_sense_skill',
    ],
    'redaccion' => [
        'open_document', 'open_notion_page', 'open_jira_issue', 'search_knowledge', 'read_knowledge', 'read_sense_file',
        'list_sense_files', 'propose_document_edit', 'propose_document_comment', 'propose_comment_reply', 'propose_notion_page',
        'propose_notion_edit', 'propose_notion_comment', 'propose_jira_issue', 'propose_jira_update', 'propose_jira_comment',
        'propose_jira_dependency', 'propose_sense_file', 'propose_sense_delete', 'use_sense_skill',
    ],
    'integraciones' => ['mcp_tools', 'mcp_call', 'analytics_report'],
    'vistas' => ['view_data_guide', 'compose_view'],
];

$agent = fn (string $name, array $values): array => [
    'provider' => env('AGENT_'.strtoupper($name).'_PROVIDER'),
    'model' => env('AGENT_'.strtoupper($name).'_MODEL'),
    'fallback_models' => array_values(array_filter(explode(',', (string) env('AGENT_'.strtoupper($name).'_FALLBACK', '')))),
    'max_steps' => 8,
    'timeout' => 90,
    'max_tokens' => 4096,
    'temperature' => 0.2,
    'concurrency' => 1,
    'budget_run' => null,
    'budget_day' => null,
    'parallel' => false,
    ...$values,
];

return [

    // Who runs the brain when an agent names no provider: deepseek or gemini (config/ai.php).
    'provider' => env('AGENTS_PROVIDER', env('AI_TEXT_PROVIDER', 'deepseek')),

    // Which model an agent of each tier uses when it names none.
    'tiers' => [
        'deepseek' => [
            'light' => env('DEEPSEEK_LIGHT_MODEL', 'deepseek-flash'),
            'capable' => env('DEEPSEEK_MODEL', 'deepseek-v4-pro'),
        ],
        'gemini' => [
            'light' => env('ALERTS_GEMINI_JUDGE_MODEL', 'gemini-3.5-flash-lite'),
            'capable' => env('ALERTS_GEMINI_MODEL', 'gemini-3.8-flash'),
        ],
    ],

    // "fake" in tests: a scripted model, no network.
    'driver' => env('AGENTS_DRIVER', 'llm'),

    'agents' => [

        'orquestador' => $agent('orquestador', [
            'label' => 'Orquestador',
            'description' => 'Reparte un encargo entre los agentes y junta la respuesta.',
            'tier' => 'light',
            'reasoning' => false,
            'temperature' => 0,
            'queue' => 'agents-light',
            'concurrency' => 2,
            'tools' => [],
        ]),

        'comunicacion' => $agent('comunicacion', [
            'label' => 'Comunicación',
            'description' => 'Slack y Gmail: entiende un hilo o un email y redacta respuestas, reacciones y mensajes para pasar algo a otra persona.',
            'instructions' => 'Lee lo necesario del hilo o del email antes de redactar. Escribe como la persona: breve, directo, en el idioma del mensaje. Cuando basta con un «ok», en Slack propón una reacción. Si te piden pasar algo a otra persona, prepara ese mensaje con lo que diga la conversación.',
            'tier' => 'capable',
            'reasoning' => true,
            'effort' => 'low',
            'queue' => 'agents-heavy',
            'tools' => $tools['comunicacion'],
            'routes' => ['slack', 'email', 'correo', 'mensaje', 'responde', 'contesta', 'dile', 'díselo', 'avisa', 'reenvía', 'escríbele', 'reacciona'],
        ]),

        'agenda' => $agent('agenda', [
            'label' => 'Agenda',
            'description' => 'Calendario: huecos, conflictos, convocar, mover, cancelar o responder reuniones, y agendar tareas.',
            'instructions' => 'Mira el calendario antes de proponer una hora y evita los conflictos. Una reunión con otras personas siempre se propone; nunca se convoca sin su sí.',
            'tier' => 'light',
            'reasoning' => false,
            'temperature' => 0,
            'queue' => 'agents-light',
            'tools' => $tools['agenda'],
            'routes' => ['calendario', 'reunión', 'reunion', 'hueco', 'agenda', 'convoca', 'mueve', 'cancela la', 'cita', 'evento'],
        ]),

        'investigacion' => $agent('investigacion', [
            'label' => 'Investigación',
            'description' => 'Reúne contexto en el índice de conocimiento, Jira, Notion, Docs, el Archivo, el correo y Slack, y devuelve hechos con su fuente. Solo lee.',
            'instructions' => 'Busca, lee lo que importa y devuelve hechos concretos, cada uno con de dónde sale. Si no encuentras algo, dilo: nunca lo supongas.',
            'tier' => 'capable',
            'reasoning' => true,
            'effort' => 'high',
            'queue' => 'agents-heavy',
            'parallel' => true,
            'tools' => $tools['investigacion'],
            'routes' => ['busca', 'qué sabemos', 'que sabemos', 'contexto', 'investiga', 'averigua', 'quién', 'dónde está', 'de qué va'],
        ]),

        'integraciones' => $agent('integraciones', [
            'label' => 'Integraciones',
            'description' => 'Servidores MCP conectados y Google Analytics. Lo que pueda cambiar algo se propone.',
            'instructions' => 'Mira primero qué herramientas tiene el servidor (mcp_tools) y usa la que encaje.',
            'tier' => 'capable',
            'reasoning' => true,
            'effort' => 'low',
            'queue' => 'agents-heavy',
            'tools' => $tools['integraciones'],
            'routes' => ['mcp', 'analytics', 'analítica', 'analitica', 'analíticas', 'analiticas', 'métricas', 'metricas', 'linear', 'servidor', 'tráfico', 'trafico', 'sesiones', 'usuarios activos', 'conversión', 'conversion'],
        ]),

        'redaccion' => $agent('redaccion', [
            'label' => 'Redacción',
            'description' => 'Documentos largos, páginas de Notion, issues de Jira y archivos del Archivo: los redacta o los edita como propuesta.',
            'instructions' => 'Investiga lo justo: como mucho tres búsquedas. Si te falta un dato, no lo sigas buscando: escribe con lo que tienes y di en say qué falta. Si ya hay datos de otro agente en el encargo, úsalos. Redacta con estructura clara y sin relleno. Lee antes lo que vas a editar y cambia solo lo pedido.',
            'tier' => 'capable',
            'reasoning' => true,
            'effort' => 'high',
            'temperature' => 0.5,
            'queue' => 'agents-heavy',
            'timeout' => 150,
            'tools' => $tools['redaccion'],
            'routes' => ['documento', 'redacta', 'escribe un', 'página de notion', 'pagina de notion', 'historia', 'épica', 'epica', 'issue', 'prd'],
        ]),

        'vistas' => $agent('vistas', [
            'label' => 'Vistas',
            'description' => 'Compone vistas OpenUI (gráficos, cifras, tablas, comparativas) y las valida antes de entregarlas.',
            'instructions' => 'Lee primero view_data_guide. Compón la vista con compose_view: solo datos reales, de un dataset con Query o de lo que te pasen; nunca cifras inventadas.',
            'tier' => 'light',
            'reasoning' => false,
            'temperature' => 0,
            'queue' => 'agents-light',
            'tools' => $tools['vistas'],
            'routes' => ['gráfico', 'grafico', 'gráfica', 'grafica', 'vista', 'tabla de', 'cifras', 'dashboard', 'compara'],
        ]),

    ],

    // What every agent of the Archive may use, on top of its own instructions and skills.
    'archive' => [
        'tools' => [...$tools['investigacion'], 'propose_sense_file'],
        'queue' => 'agents-heavy',
    ],

    // The pipelines: fixed workflows, one model call each, no tools. In the same
    // registry so their model is chosen the same way; they behave as before.
    'pipelines' => [
        'relevance_judge' => ['tier' => 'light'],
        'intent_classifier' => ['tier' => 'light'],
        'reply_planner' => ['tier' => 'capable'],
        'action_classifier' => ['tier' => 'capable'],
        'task_assessor' => ['tier' => 'capable'],
        'document_summarizer' => ['tier' => 'light'],
        'term_judge' => ['tier' => 'light'],
        'meeting_advisor' => ['tier' => 'light'],
        'context_summarizer' => ['tier' => 'capable', 'timeout' => (int) env('WORK_CONTEXT_TIMEOUT', 120)],
        'note_taker' => ['tier' => 'capable', 'model' => env('WINGMAN_NOTES_MODEL')],
        'detector' => ['tier' => 'capable', 'model' => env('WINGMAN_AGENT_MODEL')],
        'follow_up_planner' => ['tier' => 'capable', 'model' => env('WINGMAN_FOLLOW_UPS_MODEL')],
    ],

    // What the voice may call itself: reads with ids the server issued, the
    // person's own choices, delegate and present. Everything else goes through an agent.
    'voice_tools' => [
        'find_calendar_events', 'show_calendar', 'search_emails', 'show_email_results', 'open_email', 'open_email_attachment',
        'search_documents', 'show_document_results', 'find_files', 'open_document', 'show_slack_unread', 'show_slack_digest',
        'show_slack_conversation', 'search_slack_messages', 'find_slack_conversations', 'find_slack_people', 'open_slack_file',
        'search_jira', 'open_jira_issue', 'search_notion', 'open_notion_page', 'list_alerts', 'show_alert_history', 'review_alert',
        'resolve_alert', 'settle_alert_action', 'review_meeting', 'dismiss_meeting_actions', 'search_knowledge', 'read_knowledge', 'answer_question',
        'list_sense_files', 'read_sense_file', 'sync_sense_agents', 'show_slack_autonomy', 'propose_slack_autonomy',
        'lower_slack_autonomy', 'correct_slack_triage', 'answer_slack_attention', 'get_action_status', 'control_viewer',
        'save_view', 'open_view', 'forget_view', 'delegate', 'present',
    ],

    // Optional prices per model, USD per million tokens: ['deepseek-v4-pro' => ['input' => 0.0, 'output' => 0.0]].
    'prices' => [],

    // Each case set lives in database/evals/{agent}.php: about twenty real asks, scored by outcome.
    'evals_path' => database_path('evals'),

];
