import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';

import type { ActionState } from '@/actions/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';
import { Button } from '@/components/button';
import { echoConnection } from '@/lib/echo';
import { getJson, postJson } from '@/lib/http';

type SceneCard = {
    key: string;
    title: string;
    state: 'empty' | 'ready' | 'failed';
    text: string;
    error: string | null;
    action: ActionState | null;
};

type SceneBoardProps = {
    scene_id: number;
    title: string;
    cards: SceneCard[];
};

type ScenePayload = {
    title: string;
    cards: SceneCard[];
};

/**
 * A compound request. It opens empty and each card fills as its piece arrives.
 */
const SceneBoard: CanvasComponent<SceneBoardProps> = ({ index, scene_id: sceneId, title, cards }) => {
    const { auth } = usePage().props;
    const { actions } = useCanvas();
    const [board, setBoard] = useState<ScenePayload>({ title, cards });
    const [confirming, setConfirming] = useState(false);

    const heard = useRef(false);

    useEffect(() => {
        let cancelled = false;
        heard.current = false;

        void getJson<ScenePayload>(`/scenes/${sceneId}`)
            .then((payload) => {
                if (!cancelled && !heard.current) {
                    setBoard({ title: payload.title, cards: payload.cards });
                }
            })
            .catch(() => undefined);

        const userId = auth.user?.id;

        if (!userId) {
            return () => {
                cancelled = true;
            };
        }

        const channel = echoConnection().private(`App.Models.User.${userId}`);
        const onScene = (event: { scene_id: number; cards: SceneCard[] }) => {
            if (event.scene_id === sceneId) {
                heard.current = true;
                setBoard((current) => ({ ...current, cards: event.cards }));
            }
        };

        channel.listen('.scene.updated', onScene);

        return () => {
            cancelled = true;
            channel.stopListening('.scene.updated', onScene);
        };
    }, [auth.user?.id, sceneId]);

    const track = actions.track;

    useEffect(() => {
        board.cards.forEach((card) => {
            if (card.action) {
                track(card.action);
            }
        });
    }, [board.cards, track]);

    const live = (card: SceneCard): ActionState | null => (card.action ? (actions.actions[card.action.id] ?? card.action) : null);

    const pending = board.cards.some((card) => live(card)?.status === 'pending');

    const confirmAll = () => {
        setConfirming(true);

        void postJson<{ actions: ActionState[] }>(`/scenes/${sceneId}/confirm`, {})
            .then(({ actions: states }) => Promise.all(states.map((state) => actions.confirm(state.id))))
            .finally(() => setConfirming(false));
    };

    return (
        <CanvasFrame index={index} title={board.title} source="escena">
            <ul className="grid gap-3">
                {board.cards.map((card) => {
                    const action = live(card);
                    const url = typeof action?.result?.url === 'string' ? action.result.url : null;

                    return (
                        <li key={card.key} className="rounded-xl border border-line px-4 py-3">
                            <p className="text-[13px] font-medium">{card.title}</p>
                            {card.state === 'empty' && <p className="mt-1 text-[13px] text-muted">Preparando…</p>}
                            {card.state === 'failed' && <p className="mt-1 text-[13px] text-crit">{card.error}</p>}
                            {card.state === 'ready' && card.text !== '' && (
                                <p className="mt-1 text-[13.5px] leading-relaxed whitespace-pre-wrap">{card.text}</p>
                            )}
                            {action?.status === 'confirmed' && <p className="mt-2 text-[12.5px] text-muted">Guardando…</p>}
                            {action?.status === 'executed' && (
                                <p className="mt-2 text-[12.5px] text-accent">
                                    En main
                                    {url && (
                                        <>
                                            {' · '}
                                            <a href={url} className="underline" target="_blank" rel="noreferrer">
                                                ver el commit
                                            </a>
                                        </>
                                    )}
                                </p>
                            )}
                            {action?.status === 'confirmed' && (
                                <Button variant="tertiary" size="small" className="mt-2" onClick={() => void actions.undo(action.id)}>
                                    Deshacer esta
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>
            {pending && (
                <Button variant="primary" disabled={confirming} onClick={confirmAll} className="mt-4">
                    Sí
                </Button>
            )}
        </CanvasFrame>
    );
};

export default SceneBoard;
