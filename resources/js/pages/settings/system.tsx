import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

import { buttonClass } from '@/components/button';
import SettingsLayout from '@/layouts/settings-layout';
import { allowMicrophones, chosenMicrophone, listMicrophones, rememberMicrophone, type MicrophoneOption } from '@/voice/audio/microphone-choice';

type DisplaySize = {
    key: 'compact' | 'normal' | 'large';
    name: string;
    description: string;
    scale: number;
};

/** A miniature canvas at the size it would be shown. */
function Preview({ scale }: { scale: number }) {
    return (
        <div aria-hidden className="grid h-[92px] place-items-center overflow-hidden rounded-xl bg-soft">
            <div style={{ zoom: scale }} className="w-[150px] rounded-lg border border-line bg-surface px-2.5 py-2">
                <div className="flex items-center gap-1.5">
                    <span className="grid size-3.5 place-items-center rounded-full border border-line font-mono text-[8px] text-muted">1</span>
                    <span className="text-[10px] font-medium">Hoy, jueves</span>
                </div>
                <div className="mt-1.5 space-y-1">
                    <div className="rounded-[4px] bg-accent-soft px-1.5 py-0.5 text-[8.5px] text-accent">10:00 · Prepago con Marta</div>
                    <div className="rounded-[4px] bg-soft px-1.5 py-0.5 text-[8.5px] text-muted">12:00 · 1:1</div>
                </div>
            </div>
        </div>
    );
}

type Voice = { name: string; tone: string; sample_url: string };

type Playback = { voice: string; status: 'loading' | 'playing' | 'failed' } | null;

/**
 * One voice sample at a time: play, pause, or switch to another. The first
 * play of a voice is made on the server (a few seconds), then it is instant.
 */
function useSamplePlayer() {
    const audio = useRef<HTMLAudioElement | null>(null);
    const [playback, setPlayback] = useState<Playback>(null);

    useEffect(() => () => audio.current?.pause(), []);

    const toggle = (voice: Voice) => {
        if (playback?.voice === voice.name && playback.status !== 'failed') {
            audio.current?.pause();
            setPlayback(null);

            return;
        }

        audio.current?.pause();
        const player = new Audio(voice.sample_url);
        audio.current = player;
        setPlayback({ voice: voice.name, status: 'loading' });

        const isCurrent = () => audio.current === player;
        player.onplaying = () => isCurrent() && setPlayback({ voice: voice.name, status: 'playing' });
        player.onended = () => isCurrent() && setPlayback(null);
        player.onerror = () => isCurrent() && setPlayback({ voice: voice.name, status: 'failed' });
        player.play().catch(() => isCurrent() && setPlayback({ voice: voice.name, status: 'failed' }));
    };

    return { playback, toggle };
}

function PlayButton({ voice, playback, onToggle }: { voice: Voice; playback: Playback; onToggle: (voice: Voice) => void }) {
    const status = playback?.voice === voice.name ? playback.status : null;
    const label = status === 'playing' ? `Pausar ${voice.name}` : status === 'loading' ? `Cargando ${voice.name}` : `Escuchar ${voice.name}`;

    return (
        <button
            type="button"
            aria-label={label}
            title={status === 'failed' ? 'Ahora no se puede escuchar esta voz' : label}
            onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onToggle(voice);
            }}
            className={`grid size-7 flex-none place-items-center rounded-full border transition-colors ${
                status === 'playing' || status === 'loading' ? 'border-accent bg-accent text-accent-ink' : status === 'failed' ? 'border-line text-faint' : 'border-line text-muted hover:border-accent hover:text-accent'
            }`}
        >
            {status === 'loading' ? (
                <i aria-hidden className="size-[7px] animate-pulse rounded-full bg-accent-ink" />
            ) : status === 'playing' ? (
                <svg aria-hidden viewBox="0 0 10 10" className="size-[9px] fill-current">
                    <rect x="1.5" y="1" width="2.5" height="8" rx="0.6" />
                    <rect x="6" y="1" width="2.5" height="8" rx="0.6" />
                </svg>
            ) : status === 'failed' ? (
                <span aria-hidden className="text-[11px]">!</span>
            ) : (
                <svg aria-hidden viewBox="0 0 10 10" className="ml-px size-[9px] fill-current">
                    <path d="M2 1.2v7.6a.5.5 0 0 0 .76.43l6.2-3.8a.5.5 0 0 0 0-.86L2.76.77A.5.5 0 0 0 2 1.2Z" />
                </svg>
            )}
        </button>
    );
}

type SystemProps = {
    sizes: DisplaySize[];
    current: DisplaySize['key'];
    voices: Voice[];
    voice: string;
    default_voice: string;
    keys: {
        gemini: KeyChoice;
        deepseek: KeyChoice;
    };
};

type KeyChoice = { using: 'system' | 'own'; system_ready: boolean };

const fieldClass = 'w-full rounded-xl border border-line bg-surface px-3 py-2 font-mono text-[13px] text-ink outline-none focus:border-accent';

const selectClass = 'w-full max-w-xl rounded-xl border border-line bg-surface px-3 py-2 text-[14px] text-ink outline-none focus:border-accent';

/**
 * Which microphone this browser uses, from the next time listening starts.
 * The id stays on this machine: another computer has other devices.
 */
function MicrophoneChoice() {
    const [devices, setDevices] = useState<MicrophoneOption[]>([]);
    const [selected, setSelected] = useState(chosenMicrophone);
    const [named, setNamed] = useState(false);
    const [unavailable, setUnavailable] = useState(false);
    const [blocked, setBlocked] = useState(false);

    const refresh = async () => {
        if (navigator.mediaDevices?.enumerateDevices === undefined) {
            setUnavailable(true);

            return;
        }

        const found = await listMicrophones();
        setDevices(found.devices);
        setNamed(found.named);
        setUnavailable(false);
    };

    useEffect(() => {
        void refresh();

        const media = navigator.mediaDevices;

        if (media === undefined) {
            return;
        }

        const onChange = () => void refresh();
        media.addEventListener('devicechange', onChange);

        return () => media.removeEventListener('devicechange', onChange);
    }, []);

    const choose = (deviceId: string) => {
        setSelected(deviceId);
        rememberMicrophone(deviceId);
    };

    const allow = async () => {
        try {
            await allowMicrophones();
            setBlocked(false);
            await refresh();
        } catch {
            setBlocked(true);
        }
    };

    const missing = selected !== '' && !devices.some((device) => device.id === selected);

    return (
        <fieldset className="mt-10">
            <legend className="mb-1 text-[13px] text-muted">Micrófono</legend>
            <p className="mb-3 max-w-xl text-[12.5px] text-faint">El de este navegador. Se usa desde la próxima vez que actives la escucha.</p>
            {unavailable ? (
                <p className="text-[13px] text-muted">Este navegador no deja elegir el micrófono.</p>
            ) : (
                <>
                    <select aria-label="Micrófono" value={selected} onChange={(event) => choose(event.target.value)} className={selectClass}>
                        <option value="">El del sistema</option>
                        {missing && <option value={selected}>El que elegiste (ahora no está conectado)</option>}
                        {devices.map((device) => (
                            <option key={device.id} value={device.id}>
                                {device.label}
                            </option>
                        ))}
                    </select>
                    {!named && (
                        <button type="button" onClick={() => void allow()} className="mt-3 text-[13px] font-medium text-accent hover:underline">
                            Mostrar los nombres
                        </button>
                    )}
                    {blocked && <p className="mt-2 text-[12.5px] text-crit">El navegador ha bloqueado el micrófono. Permítelo para ver los nombres.</p>}
                    {missing && <p className="mt-2 text-[12.5px] text-muted">Hasta que vuelva, la escucha usa el del sistema.</p>}
                </>
            )}
        </fieldset>
    );
}

/**
 * Gemini and DeepSeek keys. The stored secret never comes back to the browser.
 */
function Keys({ keys }: { keys: SystemProps['keys'] }) {
    const form = useForm({
        gemini: keys.gemini.using,
        deepseek: keys.deepseek.using,
        gemini_api_key: '',
        deepseek_api_key: '',
    });

    useEffect(() => {
        form.setData('gemini', keys.gemini.using);
        form.setData('deepseek', keys.deepseek.using);
        // The stored key is not in props, so the fields stay empty after a save.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [keys.gemini.using, keys.deepseek.using]);

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            gemini_api_key: data.gemini === 'own' ? data.gemini_api_key : '',
            deepseek_api_key: data.deepseek === 'own' ? data.deepseek_api_key : '',
        }));
        form.put('/settings/system/keys', {
            preserveScroll: true,
            onSuccess: () => form.setData({ ...form.data, gemini_api_key: '', deepseek_api_key: '' }),
        });
    };

    return (
        <form onSubmit={save} className="mt-10">
            <fieldset>
                <legend className="mb-1 text-[13px] text-muted">Claves</legend>
                <p className="mb-4 max-w-xl text-[12.5px] text-faint">
                    Gemini es la voz. DeepSeek escribe. La tuya se guarda cifrada y no vuelve a mostrarse. Si vuelves a la del sistema, se borra de este servidor.
                </p>
                <div className="grid gap-4 lg:grid-cols-2">
                    <KeyField
                        title="Gemini"
                        choice={keys.gemini}
                        mode={form.data.gemini}
                        value={form.data.gemini_api_key}
                        error={form.errors.gemini_api_key}
                        onMode={(mode) => form.setData('gemini', mode)}
                        onValue={(value) => form.setData('gemini_api_key', value)}
                    />
                    <KeyField
                        title="DeepSeek"
                        choice={keys.deepseek}
                        mode={form.data.deepseek}
                        value={form.data.deepseek_api_key}
                        error={form.errors.deepseek_api_key}
                        onMode={(mode) => form.setData('deepseek', mode)}
                        onValue={(value) => form.setData('deepseek_api_key', value)}
                    />
                </div>
                <button type="submit" disabled={form.processing} className={`${buttonClass({ variant: 'primary' })} mt-4`}>
                    Guardar claves
                </button>
            </fieldset>
        </form>
    );
}

function KeyField({
    title,
    choice,
    mode,
    value,
    error,
    onMode,
    onValue,
}: {
    title: string;
    choice: KeyChoice;
    mode: 'system' | 'own';
    value: string;
    error?: string;
    onMode: (mode: 'system' | 'own') => void;
    onValue: (value: string) => void;
}) {
    return (
        <div className="rounded-2xl border border-line bg-surface p-4">
            <p className="text-[14px] font-medium">{title}</p>
            <div className="mt-3 grid gap-2">
                {(['system', 'own'] as const).map((option) => (
                    <label key={option} className="flex cursor-pointer items-start gap-2 text-[13px]">
                        <input type="radio" name={title} value={option} checked={mode === option} onChange={() => onMode(option)} className="mt-0.5 accent-[var(--accent)]" />
                        <span>
                            <span className="font-medium">{option === 'system' ? 'La del sistema' : 'La mía'}</span>
                            <span className="mt-0.5 block text-[12.5px] text-muted">
                                {option === 'system'
                                    ? choice.system_ready
                                        ? 'La que está configurada en el servidor.'
                                        : 'En el servidor no hay clave.'
                                    : choice.using === 'own'
                                      ? 'Hay una guardada. Pega otra solo si quieres cambiarla.'
                                      : 'Pégala aquí. El navegador no la envía al proveedor: la usa el servidor.'}
                            </span>
                        </span>
                    </label>
                ))}
            </div>
            {mode === 'own' && (
                <input
                    type="password"
                    name={`${title.toLowerCase()}_api_key`}
                    value={value}
                    onChange={(event) => onValue(event.target.value)}
                    autoComplete="off"
                    autoCapitalize="off"
                    spellCheck={false}
                    placeholder={choice.using === 'own' ? 'En blanco se conserva la actual' : 'Pega la clave'}
                    className={`${fieldClass} mt-3`}
                />
            )}
            {error && <p className="mt-2 text-[12.5px] text-crit">{error}</p>}
        </div>
    );
}

export default function System({ sizes, current, voices, voice, default_voice: defaultVoice, keys }: SystemProps) {
    const choose = (key: DisplaySize['key']) => router.put('/settings/system/display', { display_size: key }, { preserveScroll: true });
    const chooseVoice = (name: string) => router.put('/settings/system/voice', { voice: name }, { preserveScroll: true });
    const { playback, toggle } = useSamplePlayer();

    return (
        <SettingsLayout title="Sistema" description="Cómo ves, cómo hablas, cómo oyes y con qué claves.">
            <fieldset>
                <legend className="mb-3 text-[13px] text-muted">Tamaño de visualización</legend>
                <div className="grid gap-3 sm:grid-cols-3">
                    {sizes.map((size) => (
                        <label
                            key={size.key}
                            className={`cursor-pointer rounded-2xl border bg-surface p-3 transition-colors ${size.key === current ? 'border-accent ring-1 ring-accent' : 'border-line hover:bg-soft'}`}
                        >
                            <input type="radio" name="display_size" value={size.key} checked={size.key === current} onChange={() => choose(size.key)} className="sr-only" />
                            <Preview scale={size.scale} />
                            <span className="mt-3 flex items-baseline justify-between gap-2">
                                <span className="text-[14px] font-medium">{size.name}</span>
                                <span className="font-mono text-[11px] text-faint">{Math.round(size.scale * 100)}%</span>
                            </span>
                            <span className="mt-0.5 block text-[12.5px] text-muted">{size.description}</span>
                        </label>
                    ))}
                </div>
            </fieldset>

            <MicrophoneChoice />

            <fieldset className="mt-10">
                <legend className="mb-1 text-[13px] text-muted">Voz del asistente</legend>
                <p className="mb-3 text-[12.5px] text-faint">Pulsa ▶︎ para escucharla. La que elijas se usa desde la próxima conversación.</p>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    {voices.map((option) => (
                        <label
                            key={option.name}
                            className={`cursor-pointer rounded-xl border bg-surface px-3 py-2 transition-colors ${option.name === voice ? 'border-accent ring-1 ring-accent' : 'border-line hover:bg-soft'}`}
                        >
                            <input type="radio" name="voice" value={option.name} checked={option.name === voice} onChange={() => chooseVoice(option.name)} className="sr-only" />
                            <span className="flex items-center gap-2.5">
                                <PlayButton voice={option} playback={playback} onToggle={toggle} />
                                <span className="min-w-0 flex-1">
                                    <span className="flex items-baseline justify-between gap-2">
                                        <span className="truncate text-[14px] font-medium">{option.name}</span>
                                        {option.name === defaultVoice && <span className="flex-none text-[11px] text-faint">por defecto</span>}
                                    </span>
                                    <span className="block text-[12.5px] text-muted">{option.tone}</span>
                                </span>
                            </span>
                        </label>
                    ))}
                </div>
            </fieldset>

            <Keys keys={keys} />
        </SettingsLayout>
    );
}
