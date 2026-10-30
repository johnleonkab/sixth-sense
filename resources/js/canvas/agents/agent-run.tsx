import { useContext, useEffect, useRef, useState } from 'react';

import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { CanvasRendererContext } from '@/canvas/embedded';
import type { CanvasComponent, CanvasDirective } from '@/canvas/types';
import { getJson } from '@/lib/http';

type RunStatus = 'queued' | 'running' | 'done' | 'failed';

type AgentRunState = {
    status: RunStatus;
    say: string | null;
    error: string | null;
    agents: { agent: string; task: string; status: RunStatus }[];
    productions: { ref: string; kind: string; summary: string | null; canvas: CanvasDirective | null; beside: CanvasDirective | null }[];
};

const agentNames: Record<string, string> = {
    comunicacion: 'Comunicación',
    agenda: 'Agenda',
    investigacion: 'Investigación',
    redaccion: 'Redacción',
    integraciones: 'Integraciones',
    vistas: 'Vistas',
};

const agentName = (agent: string) => agentNames[agent] ?? agent.replace(/^archivo:/, 'Archivo · ');

const statusText: Record<RunStatus, string> = { queued: 'en cola', running: 'trabajando…', done: 'listo', failed: 'no ha podido' };

/**
 * An ask the voice handed to the agents: opens at once, shows each agent at
 * work, then what they made (views, drafts, proposals that wait for the "sí").
 * When they finish, the voice says what they said — only that.
 */
const AgentRun: CanvasComponent<{ run_id: number; task: string }> = ({ index, run_id: runId, task }) => {
    const { tell } = useCanvas();
    const render = useContext(CanvasRendererContext);
    const [run, setRun] = useState<AgentRunState | null>(null);
    const told = useRef(false);

    useEffect(() => {
        let stopped = false;
        let timer = 0;

        const poll = async () => {
            try {
                const next = await getJson<AgentRunState>(`/agents/runs/${runId}`);

                if (stopped) {
                    return;
                }

                setRun(next);

                if (next.status === 'done' || next.status === 'failed') {
                    return;
                }
            } catch (error) {
                console.error(error);
            }

            timer = window.setTimeout(() => void poll(), 1500);
        };

        void poll();

        return () => {
            stopped = true;
            window.clearTimeout(timer);
        };
    }, [runId]);

    // The voice speaks from what the agents said, never from its own guess.
    useEffect(() => {
        if (!run || told.current || (run.status !== 'done' && run.status !== 'failed')) {
            return;
        }

        told.current = true;
        const refs = run.productions.map((production) => production.ref).join(', ');

        tell?.(
            run.status === 'done'
                ? `[Resultado del encargo] Dilo tal cual, sin añadir datos: «${run.say ?? 'Listo.'}»${refs ? ` Está en pantalla (${refs}); para volver a mostrarlo, present con su referencia.` : ''}`
                : `[El encargo no ha salido] Díselo en una frase: ${run.error ?? 'no se ha podido hacer.'}`,
        );
    }, [run, tell]);

    const working = !run || run.status === 'queued' || run.status === 'running';

    return (
        <CanvasFrame index={index} title={task} source="Agentes">
            {run && run.agents.length > 0 && (
                <ul aria-label="Agentes" className="mb-3 flex flex-wrap gap-1.5">
                    {run.agents.map((agent, position) => (
                        <li
                            key={`${agent.agent}-${position}`}
                            title={agent.task}
                            className={`rounded-full px-2.5 py-0.5 text-[12px] ${agent.status === 'running' ? 'alerts-shimmer bg-accent-soft text-accent' : agent.status === 'failed' ? 'bg-crit-soft text-crit' : 'bg-soft text-muted'}`}
                        >
                            {agentName(agent.agent)} · {statusText[agent.status]}
                        </li>
                    ))}
                </ul>
            )}

            {working && (
                <div className="space-y-2" aria-label="Preparando">
                    <div className="alerts-shimmer h-4 w-3/5 rounded" />
                    <div className="alerts-shimmer h-24 rounded-xl" />
                </div>
            )}

            {run?.status === 'failed' && run.productions.length === 0 && <p className="text-[13px] text-crit">{run.error ?? 'No se ha podido hacer.'}</p>}

            {run && run.productions.length > 0 && render && (
                <div className="grid gap-3">
                    {run.productions.map((production) => (
                        <div key={production.ref} className="grid gap-3 md:grid-cols-[repeat(auto-fit,minmax(0,1fr))]">
                            {production.canvas && render(production.canvas)}
                            {production.beside && render(production.beside)}
                        </div>
                    ))}
                </div>
            )}

            {run?.status === 'done' && run.say && <p className="mt-3 text-[13px] text-muted">{run.say}</p>}
        </CanvasFrame>
    );
};

export default AgentRun;
