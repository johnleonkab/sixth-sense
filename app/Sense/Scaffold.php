<?php

namespace App\Sense;

/**
 * The files every work repository starts with. The same list is what an
 * existing repository is checked against: nothing here is decided by a model.
 */
class Scaffold
{
    /**
     * @return array<string, string> Path to file contents.
     */
    public static function files(): array
    {
        return [
            'AGENTS.md' => <<<'MD'
                # Cómo se trabaja en este archivo

                Cada documento con tipo lleva una cabecera YAML entre `---` y obedece `types/{tipo}.yml`: las claves de `required`, la carpeta de `where` y el nombre de `name`.

                - `{project}` es la carpeta del proyecto.
                - `{date}` es AAAA-MM-DD.
                - `{slug}` es el nombre en minúsculas y guiones.

                Un proyecto nuevo copia `projects/_template`. Un agente nuevo copia `agents/_template` y se sincroniza con sixth-sense. El agente `keeper` mantiene este archivo: crea, actualiza y borra. Nada se escribe sin que la persona diga que sí.

                `now.md` es el estado vivo. `map.md` dice dónde está cada cosa. `vertical` en el proyecto dice si es finance, product, delivery u operations.
                MD,
            'now.md' => "# Ahora\n\nResumen de lo que está en marcha.\n\n## Frentes\n\n## Personas\n\n## Cabos sueltos\n",
            'profile.md' => "# Perfil\n\nQuién usa este archivo y cómo quiere que se le hable.\n",
            'people.md' => "# Personas\n\nQuienes importan en el trabajo, y dónde aparecen.\n",
            'map.md' => <<<'MD'
                # Mapa

                - `types/` reglas de cada tipo de documento.
                - `projects/` un proyecto por carpeta.
                - `agents/` agentes. `keeper` mantiene el archivo.
                - `inbox/` notas que todavía no tienen sitio.
                - `archive/` lo que ya no está vivo.
                MD,
            'types/project.yml' => <<<'YML'
                type: project
                where: projects/{project}
                name: project.md
                required:
                  author: Quién lo lleva
                  why: Para qué es el proyecto
                  outcome: Qué se considera terminado
                optional:
                  vertical: finance, product, delivery u operations
                YML,
            'types/prd.yml' => <<<'YML'
                type: prd
                where: projects/{project}/docs
                name: "{date}-{slug}.md"
                required:
                  author: Quién lo pide
                  why: Por qué hace falta
                  outcome: Qué se espera al terminar
                optional:
                  status: draft, decided o dropped
                  until: Fecha límite
                YML,
            'types/decision.yml' => <<<'YML'
                type: decision
                where: projects/{project}
                name: decisions.md
                required:
                  author: Quién decide
                  why: Qué se está decidiendo
                  outcome: Qué queda decidido
                YML,
            'types/list.yml' => <<<'YML'
                type: list
                where: projects/{project}
                name: open.md
                required:
                  author: Quién la lleva
                YML,
            'types/log.yml' => <<<'YML'
                type: log
                where: projects/{project}
                name: log.md
                required:
                  author: Quién lo escribe
                YML,
            'types/note.yml' => <<<'YML'
                type: note
                where: inbox
                name: "{date}-{slug}.md"
                required:
                  author: Quién lo anota
                YML,
            'types/agent.yml' => <<<'YML'
                type: agent
                where: agents/{slug}
                name: agent.md
                required:
                  name: Cómo se llama
                  purpose: Para qué existe
                  when: Cuándo usarlo
                YML,
            'projects/_template/project.md' => <<<'MD'
                ---
                type: project
                author: Pendiente
                why: Pendiente
                outcome: Pendiente
                vertical: product
                ---

                # Proyecto

                Qué es y en qué vertical vive.
                MD,
            'projects/_template/docs/2026-01-01-brief.md' => <<<'MD'
                ---
                type: prd
                author: Pendiente
                why: Pendiente
                outcome: Pendiente
                status: draft
                ---

                # Brief
                MD,
            'projects/_template/decisions.md' => <<<'MD'
                ---
                type: decision
                author: Pendiente
                why: Pendiente
                outcome: Pendiente
                ---

                # Decisiones
                MD,
            'projects/_template/open.md' => <<<'MD'
                ---
                type: list
                author: Pendiente
                ---

                # Abierto
                MD,
            'projects/_template/log.md' => <<<'MD'
                ---
                type: log
                author: Pendiente
                ---

                # Diario
                MD,
            'agents/_template/agent.md' => <<<'MD'
                ---
                type: agent
                name: Nombre
                purpose: Para qué existe este agente.
                when: Cuándo debe usarlo el orquestador.
                ---

                Instrucciones que sigue cuando está en uso.
                MD,
            'agents/keeper/agent.md' => <<<'MD'
                ---
                type: agent
                name: Archivo
                purpose: Mantiene el archivo de trabajo. Crea documentos que cumplen las reglas, y crea otros agentes.
                when: Cuando haya que guardar, cambiar o borrar algo del archivo, o crear un agente.
                ---

                Lee `types/` antes de crear un documento y cumple `where`, `name` y `required`.
                Para escribir o actualizar usa propose_sense_file. Para borrar, propose_sense_delete.
                Un agente nuevo es `agents/{slug}/agent.md` copiado de `agents/_template/agent.md`, con name, purpose y when.
                Después de crearlo o cambiarlo, llama a sync_sense_agents.
                No inventes un tipo: si no existe en `types/`, proponlo primero como `types/{tipo}.yml` con type, where, name y required.
                MD,
            'inbox/.gitkeep' => '',
            'archive/.gitkeep' => '',
        ];
    }

    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        return array_keys(self::files());
    }

    /**
     * @return list<string>
     */
    public static function typePaths(): array
    {
        return array_values(array_filter(self::paths(), fn (string $path): bool => str_starts_with($path, 'types/')));
    }
}
