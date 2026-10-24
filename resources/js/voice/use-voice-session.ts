import { useCallback, useEffect, useReducer, useRef, useState } from 'react';

import type { CanvasDirective } from '@/canvas/types';
import { activityFor } from '@/voice/activity';
import { playHalo } from '@/voice/audio/chimes';
import { Microphone } from '@/voice/audio/microphone';
import { AudioPlayer } from '@/voice/audio/player';
import { requestGrant, VoiceGrantError } from '@/voice/request-grant';
import { runTool } from '@/voice/run-tool';
import { ConversationMemory, resumeNote } from '@/voice/memory';
import { emptyTranscript, transcriptReducer } from '@/voice/transcript';
import { createTransport } from '@/voice/transports';
import type { ToolCall, VoiceStatus, VoiceTransport } from '@/voice/types';

export type VoiceMode = 'voice' | 'text';

type Resources = {
    mode: VoiceMode;
    player: AudioPlayer;
    microphone?: Microphone;
    transport?: VoiceTransport;
};

/** A typed conversation closes itself after this long without use. */
const TEXT_IDLE_CLOSE = 5 * 60_000;

/** A spoken conversation that drops is opened again this many times before giving up. */
const MAX_RECONNECTS = 3;

type VoiceSessionOptions = {
    /** The assistant asked to put something on screen. */
    onCanvas?: (canvas: CanvasDirective) => void;
    /** The person finished saying something (used to recognise "sí", "cancela", "deshaz"). */
    onUtterance?: (text: string) => Promise<void> | void;
    /** What is on screen now, for a conversation that starts or picks up again. */
    screen?: () => string | null;
};

export type VoiceSession = {
    status: VoiceStatus;
    /** "voice" while talking, "text" while typing, null when closed. */
    mode: VoiceMode | null;
    /** What the person is saying now. */
    heard: string;
    /** What the assistant is answering. */
    reply: string;
    error: string | null;
    /** A spoken conversation is open. */
    active: boolean;
    /** What a running tool is doing, shown instead of a generic wait. */
    activity: string | null;
    /** The spoken conversation dropped and is being opened again. */
    reconnecting: boolean;
    /** Resolves to whether the conversation is open. A typed one has no microphone and no audio. */
    start: (mode?: VoiceMode) => Promise<boolean>;
    stop: () => void;
    /**
     * Tell the assistant something it did not hear. With `respond`, it answers
     * now, opening the conversation first if needed; otherwise it is only
     * context for later, and is dropped if no conversation is open.
     */
    tell: (text: string, respond: boolean) => Promise<void>;
    /** Say something by typing: the answer comes back as text only. */
    ask: (text: string) => Promise<void>;
};

/**
 * Ephemeral speech-to-speech conversation: nothing is stored, and closing it
 * forgets everything that was said.
 */
export function useVoiceSession({ onCanvas, onUtterance, screen }: VoiceSessionOptions = {}): VoiceSession {
    const [status, setStatus] = useState<VoiceStatus>('idle');
    const [activity, setActivity] = useState<string | null>(null);
    const [mode, setMode] = useState<VoiceMode | null>(null);
    const [reconnecting, setReconnecting] = useState(false);
    const idleTimer = useRef<number | undefined>(undefined);
    const reconnectTimer = useRef<number | undefined>(undefined);
    const reconnects = useRef(0);
    const memory = useRef(new ConversationMemory());
    const answer = useRef('');
    const screenRef = useRef(screen);
    screenRef.current = screen;
    const [transcript, dispatch] = useReducer(transcriptReducer, emptyTranscript);
    const [error, setError] = useState<string | null>(null);

    const resources = useRef<Resources | null>(null);
    const onCanvasRef = useRef(onCanvas);
    onCanvasRef.current = onCanvas;
    const onUtteranceRef = useRef(onUtterance);
    onUtteranceRef.current = onUtterance;
    const utterance = useRef('');

    /** The person stopped talking once the assistant answers or the turn ends. */
    const finishUtterance = useCallback(async (): Promise<void> => {
        const text = utterance.current.trim();
        utterance.current = '';

        if (text) {
            memory.current.add('user', text);
            await onUtteranceRef.current?.(text);
        }
    }, []);

    /** The assistant's answer, once complete, is remembered. */
    const finishAnswer = useCallback(() => {
        memory.current.add('assistant', answer.current);
        answer.current = '';
    }, []);

    const release = useCallback(() => {
        const current = resources.current;
        resources.current = null;
        window.clearTimeout(idleTimer.current);
        setMode(null);

        current?.microphone?.close();
        current?.transport?.close();
        current?.player.close();
    }, []);

    const stop = useCallback(() => {
        window.clearTimeout(reconnectTimer.current);
        reconnects.current = 0;
        setReconnecting(false);
        release();
        setActivity(null);
        setStatus('idle');
    }, [release]);

    const fail = useCallback(
        (message: string) => {
            window.clearTimeout(reconnectTimer.current);
            reconnects.current = 0;
            setReconnecting(false);
            release();
            setActivity(null);
            setError(message);
            setStatus('error');
        },
        [release],
    );

    const startRef = useRef<(mode?: VoiceMode, resuming?: boolean) => Promise<boolean>>(async () => false);

    /** A spoken conversation dropped: open it again, a few times, before giving up. */
    const lost = useCallback(() => {
        if (reconnects.current >= MAX_RECONNECTS) {
            fail('Se ha cortado la conexión con el servicio de voz. Pulsa «Hablar» para seguir donde lo dejasteis.');

            return;
        }

        reconnects.current++;
        release();
        setMode('voice');
        setReconnecting(true);
        setStatus('connecting');
        reconnectTimer.current = window.setTimeout(() => void startRef.current('voice', true), 600 * reconnects.current);
    }, [fail, release]);

    const start = useCallback(async (requested: VoiceMode = 'voice', resuming = false): Promise<boolean> => {
        if (resources.current) {
            // Talking takes over from typing; typing while talking just uses the open conversation.
            if (requested === 'voice' && resources.current.mode === 'text') {
                release();
            } else {
                return resources.current.transport !== undefined;
            }
        }

        // Created inside the click so the browser lets it play audio.
        const player = new AudioPlayer();
        const session: Resources = { mode: requested, player };
        const typed = requested === 'text';
        resources.current = session;
        const isCurrent = () => resources.current === session;
        const settled = (): VoiceStatus => (typed ? 'idle' : player.playing ? 'speaking' : 'listening');

        setMode(requested);
        setStatus(typed ? 'thinking' : 'connecting');
        setError(null);

        if (!typed) {
            dispatch({ type: 'reset' });
        }

        player.onPlayingChange = (playing) => {
            if (isCurrent()) {
                setStatus(playing ? 'speaking' : 'listening');
            }
        };

        try {
            const grant = await requestGrant();
            const transport = await createTransport(grant.provider);
            session.transport = transport;

            const microphone = typed ? null : await Microphone.open(transport.inputSampleRate);

            // Keep what is said while the connection opens, so the first words are not lost.
            const earlyAudio: Int16Array[] = [];

            if (microphone) {
                session.microphone = microphone;
                microphone.onChunk = (pcm) => earlyAudio.push(pcm);
            }

            if (!isCurrent()) {
                microphone?.close();

                return false;
            }

            const cancelledCalls = new Set<string>();

            // Tools run in Laravel; the assistant waits for every response before speaking again.
            const runToolCalls = async (calls: ToolCall[], answered: Promise<void>) => {
                // Whatever the person just answered ("sí", "deshaz") is recorded before any tool reads it.
                await answered.catch(() => undefined);
                const outcomes = await Promise.all(calls.map(async (call) => ({ call, outcome: await runTool(call) })));
                const pending = outcomes.filter(({ call }) => !cancelledCalls.has(call.id));

                if (!isCurrent() || pending.length === 0) {
                    return;
                }

                pending.forEach(({ outcome }) => outcome.canvas && onCanvasRef.current?.(outcome.canvas));
                transport.sendToolResponses(
                    pending.map(({ call, outcome }) => ({ id: call.id, name: call.name, output: outcome.output })),
                );
                setActivity(null);
                setStatus(typed ? 'thinking' : settled());
            };

            await transport.connect(grant, {
                onUserTranscript: (text) => {
                    utterance.current += text;
                    dispatch({ type: 'user', text });
                },
                onAssistantTranscript: (text) => {
                    void finishUtterance();
                    answer.current += text;
                    dispatch({ type: 'assistant', text });

                    if (typed) {
                        setStatus('speaking');
                    }
                },
                onAssistantAudio: (pcm, sampleRate) => {
                    void finishUtterance();

                    // Typing means not wanting to hear it: the answer is only shown.
                    if (!typed) {
                        player.enqueue(pcm, sampleRate);
                    }
                },
                onInterrupted: () => {
                    player.interrupt();
                    finishAnswer();
                    setActivity(null);
                    dispatch({ type: 'turn-ended' });
                },
                onTurnComplete: () => {
                    void finishUtterance();
                    finishAnswer();
                    dispatch({ type: 'turn-ended' });

                    if (typed && isCurrent()) {
                        setStatus('idle');
                        window.clearTimeout(idleTimer.current);
                        idleTimer.current = window.setTimeout(() => isCurrent() && release(), TEXT_IDLE_CLOSE);
                    }
                },
                onToolCalls: (calls) => {
                    // open_scene answers with the empty board and returns. Later cards
                    // arrive on the user's channel. Every other tool still waits here.
                    setActivity(activityFor(calls.map((call) => call.name)));
                    setStatus('thinking');
                    void runToolCalls(calls, finishUtterance());
                },
                onToolCallsCancelled: (ids) => ids.forEach((id) => cancelledCalls.add(id)),
                onClose: () => {
                    if (isCurrent()) {
                        if (typed) {
                            release();
                            setStatus('idle');
                        } else {
                            lost();
                        }
                    }
                },
                onError: () => {
                    if (isCurrent()) {
                        if (typed) {
                            fail('Se ha cortado la conexión con el servicio de voz.');
                        } else {
                            lost();
                        }
                    }
                },
            });

            if (!isCurrent()) {
                transport.close();

                return false;
            }

            // Pick up where things were: what was said lately and what is on screen.
            const note = resumeNote(memory.current.recent(), screenRef.current?.() ?? null);

            if (note) {
                transport.sendContext(note, false);
            }

            if (microphone) {
                earlyAudio.forEach((pcm) => transport.sendAudio(pcm));
                microphone.onChunk = (pcm) => transport.sendAudio(pcm);
                playHalo(player.context);
                setStatus('listening');
                reconnects.current = 0;
                setReconnecting(false);
            }

            return true;
        } catch (exception) {
            if (!isCurrent()) {
                return false;
            }

            // Still offline while picking up a dropped conversation: keep trying.
            if (resuming && !(exception instanceof DOMException) && !(exception instanceof VoiceGrantError && exception.permanent)) {
                console.error(exception);
                lost();

                return false;
            }

            if (exception instanceof DOMException && exception.name === 'NotAllowedError') {
                fail('Necesito permiso para usar el micrófono. Actívalo en el navegador y vuelve a intentarlo.');
            } else if (exception instanceof VoiceGrantError) {
                fail(exception.message);
            } else {
                fail('No he podido empezar la conversación. Inténtalo de nuevo.');
            }

            console.error(exception);

            return false;
        }
    }, [fail, finishAnswer, finishUtterance, lost, release]);

    startRef.current = start;

    const tell = useCallback(
        async (text: string, respond: boolean) => {
            const open = resources.current?.transport ? true : respond ? await start() : false;

            if (open) {
                resources.current?.transport?.sendContext(text, respond);
            }
        },
        [start],
    );

    const ask = useCallback(
        async (text: string) => {
            const typed = text.trim();

            if (!typed) {
                return;
            }

            window.clearTimeout(idleTimer.current);
            dispatch({ type: 'turn-ended' });
            dispatch({ type: 'user', text: typed });
            dispatch({ type: 'turn-ended' });

            // Typed "sí", "cancela" or "deshaz" answer a proposal, exactly as said ones do.
            await onUtteranceRef.current?.(typed);

            const open = resources.current?.transport ? true : await start('text');

            // Remembered once the conversation is open, so its opening note does not repeat it.
            memory.current.add('user', typed);

            if (open) {
                setStatus((current) => (resources.current?.mode === 'text' ? 'thinking' : current));
                resources.current?.transport?.sendContext(typed, true);
            }
        },
        [start],
    );

    useEffect(
        () => () => {
            window.clearTimeout(reconnectTimer.current);
            release();
        },
        [release],
    );

    return {
        status,
        mode,
        heard: transcript.heard,
        reply: transcript.reply,
        error,
        active: mode === 'voice' && status !== 'idle' && status !== 'error',
        activity,
        reconnecting,
        start,
        stop,
        tell,
        ask,
    };
}
