<?php

namespace App\Connectors\Jira;

use Illuminate\Support\Str;

/**
 * How a team works in its Jira, written by the person in markdown, one
 * document per connection: which projects, how epics, stories and subtasks
 * are written, what each priority means, how dependencies are marked, how
 * to comment. The assistant reads all of it; the app takes a few things
 * from it directly (projects, base JQL, link type).
 */
final class JiraRules
{
    public const MAX_LENGTH = 20000;

    private const DEFAULT_LINK_TYPE = 'Blocks';

    public function __construct(public readonly string $text) {}

    /**
     * The template a new connection starts with.
     */
    public static function template(): string
    {
        return <<<'MD'
            # Reglas de Jira

            ## Proyectos y filtros
            <!-- Qué proyectos usas. sixth-sense solo vigila las menciones en estos. -->
            - Proyectos: CPD
            - JQL base: project = CPD AND statusCategory != Done ORDER BY updated DESC
            - Mis tareas: assignee = currentUser() AND statusCategory != Done

            ## Épicas
            - Título: el resultado que se busca, en una frase ("Pagar la reserva por adelantado").
            - Descripción: Contexto, Objetivo, Alcance, Fuera de alcance y Métricas de éxito.

            ## User stories
            - Título: "Como <rol>, quiero <acción> para <beneficio>".
            - Descripción, con estas secciones: Intro, Objective, Context, Delta, Proposal, Design, Acceptance criteria, Amplitude events.
            - Idioma: inglés.
            - Siempre dentro de una épica.

            ## Subtareas
            - Una por cada pieza técnica independiente; título con un verbo ("Añadir el endpoint de prepago").

            ## Prioridades
            - Highest: bloquea a clientes o a otro equipo ahora.
            - High: compromete el sprint.
            - Medium: lo normal.
            - Low: mejora sin fecha.

            ## Dependencias
            - Tipo de enlace: Blocks
            - Una tarea bloquea a otra cuando la segunda no puede empezar sin la primera terminada.

            ## Comentarios
            - En el idioma del issue, cortos, mencionando a quien tiene que actuar.
            MD;
    }

    /**
     * Project keys the person works in ("- Proyectos: CPD, MKT").
     *
     * @return list<string>
     */
    public function projects(): array
    {
        if (! preg_match('/^\s*[-*]?\s*proyectos?\s*:\s*(.+)$/imu', $this->text, $match)) {
            return [];
        }

        preg_match_all('/\b[A-Z][A-Z0-9_]{1,9}\b/', $match[1], $keys);

        return array_values(array_unique($keys[0]));
    }

    /**
     * The JQL to start from when nothing else is said ("- JQL base: …").
     */
    public function baseJql(): ?string
    {
        return preg_match('/^\s*[-*]?\s*jql base\s*:\s*(.+)$/imu', $this->text, $match) ? trim($match[1], " `\t") : null;
    }

    /**
     * The issue link type for dependencies ("- Tipo de enlace: Blocks").
     */
    public function linkType(): string
    {
        return preg_match('/^\s*[-*]?\s*tipo de enlace\s*:\s*(.+)$/imu', $this->text, $match) ? trim($match[1], " `\t") : self::DEFAULT_LINK_TYPE;
    }

    /**
     * The section titles it has, to show what was understood.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        preg_match_all('/^##\s+(.+)$/mu', $this->text, $matches);

        return array_map('trim', $matches[1]);
    }

    /**
     * For the assistant: the rules without comments meant for the person.
     */
    public function forAssistant(): string
    {
        return Str::limit(trim((string) preg_replace('/<!--.*?-->/s', '', $this->text)), 6000);
    }
}
