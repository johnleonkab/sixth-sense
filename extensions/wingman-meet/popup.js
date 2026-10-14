/**
 * Wingman's window: the link with sixth-sense, what the Meet tab has read
 * and sent so far, and exports to check it. Also tries option B (the tab's
 * audio) for comparison.
 */
const $ = (id) => document.getElementById(id);

async function meetTab() {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });

    return tab?.url?.startsWith('https://meet.google.com/') ? tab : null;
}

async function ask(message) {
    const tab = await meetTab();

    if (!tab) {
        return null;
    }

    try {
        return await chrome.tabs.sendMessage(tab.id, message);
    } catch {
        // The tab was open before the extension: it needs a reload.
        return { unreachable: true };
    }
}

function download(name, content, type) {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([content], { type }));
    link.download = name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

function render(status) {
    const eyebrow = $('call-eyebrow');

    if (!status || status.unreachable) {
        eyebrow.textContent = 'Llamada';
        $('call').textContent = status?.unreachable ? 'Recarga la pestaña de Meet' : 'Abre una llamada de Meet';
        $('sync').textContent = status?.unreachable ? 'La extensión se actualizó después de abrirla.' : 'Wingman empieza solo cuando entras en una.';
        return;
    }

    if (!status.inCall) {
        eyebrow.textContent = 'Meet';
        $('call').textContent = 'Fuera de la llamada';
        $('sync').textContent = 'Wingman empezará en cuanto entres.';
    } else {
        // What sixth-sense knows of the call, said the way the bubble says it.
        const state = status.lastError ? 'Sin conexión' : !status.meetingId ? 'Avisando a sixth-sense…' : status.paused ? 'En pausa' : 'Escuchando';
        eyebrow.innerHTML = `<i class="dot ${status.lastError ? 'bad' : status.meetingId && !status.paused ? 'on' : ''}" style="display:inline-block;margin-right:6px;vertical-align:1px"></i>${state}`;
        $('call').textContent = status.meetingTitle ?? `Llamada ${status.code ?? ''}`.trim();
        $('sync').innerHTML = status.lastError
            ? `<span class="bad">${escape(status.lastError)}</span>`
            : status.paused
              ? 'No se guarda nada de lo que se dice. Reanúdalo desde la burbuja de sixth-sense.'
              : status.meetingId
                ? `${status.sent} frases enviadas a sixth-sense.`
                : 'Enlazando con tu calendario…';
    }

    $('captions').innerHTML = status.captionsFound
        ? `<span class="ok">Leyendo</span>${status.hidden ? ' · ocultos para ti' : ' · visibles'}`
        : status.inCall
          ? '<span class="warn">Activándolos…</span>'
          : '—';
    $('count').textContent = status.segments.length ? `${status.segments.length} (${status.segments.filter((segment) => segment.final).length} cerradas)` : '—';
    $('speakers').textContent = status.speakers.join(', ') || '—';
    $('toggle-hidden').textContent = status.hidden ? 'Mostrar subtítulos' : 'Ocultar subtítulos';
    $('toggle-hidden').dataset.hidden = String(status.hidden);

    const lines = status.segments.slice(-6);
    $('lines').innerHTML = lines.length
        ? lines.map((segment) => `<p><b class="${segment.speaker === 'Tú' || segment.speaker === 'You' ? 'me' : ''}">${escape(segment.speaker)}:</b> ${escape(segment.text)}</p>`).join('')
        : `<p>${status.inCall ? 'Todavía nada. En cuanto alguien hable, aparecerá aquí.' : 'Aquí verás lo último que se dice.'}</p>`;
    $('lines').scrollTop = $('lines').scrollHeight;
}

function escape(text) {
    return text.replace(/[&<>"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[character]);
}

async function refresh() {
    render(await ask({ type: 'status' }));
}

$('toggle-hidden').addEventListener('click', async () => {
    render(await ask({ type: 'set-hidden', hidden: $('toggle-hidden').dataset.hidden !== 'true' }));
});

$('export').addEventListener('click', async () => {
    const status = await ask({ type: 'status' });

    if (status && !status.unreachable) {
        download(`wingman-${status.code ?? 'meet'}-${Date.now()}.json`, JSON.stringify(status, null, 2), 'application/json');
    }
});

$('export-html').addEventListener('click', async () => {
    const result = await ask({ type: 'captions-html' });
    download(`wingman-captions-${Date.now()}.html`, result?.html ?? '<!-- No caption region found -->', 'text/html');
});

/**
 * Option B: capture the tab's audio for a few seconds and measure it. Chrome
 * mutes a captured tab, so it is played back to keep the call audible.
 */
$('audio-test').addEventListener('click', () => {
    $('audio').textContent = 'Capturando…';

    chrome.tabCapture.capture({ audio: true, video: false }, (stream) => {
        if (!stream) {
            $('audio').textContent = `No se pudo: ${chrome.runtime.lastError?.message ?? 'sin permiso'}`;
            return;
        }

        const context = new AudioContext();
        const source = context.createMediaStreamSource(stream);
        const analyser = context.createAnalyser();
        source.connect(analyser);
        source.connect(context.destination);

        const samples = new Float32Array(analyser.fftSize);
        let peak = 0;
        let total = 0;
        let count = 0;

        const timer = setInterval(() => {
            analyser.getFloatTimeDomainData(samples);
            const rms = Math.sqrt(samples.reduce((sum, value) => sum + value * value, 0) / samples.length);
            peak = Math.max(peak, rms);
            total += rms;
            count++;
        }, 100);

        setTimeout(() => {
            clearInterval(timer);
            stream.getTracks().forEach((track) => track.stop());
            void context.close();
            const average = total / Math.max(count, 1);
            $('audio').textContent = average > 0.005
                ? `Funciona: nivel medio ${average.toFixed(3)}, pico ${peak.toFixed(3)}. Se oye a los demás.`
                : `Capturado, pero casi en silencio (nivel ${average.toFixed(3)}). ¿Hablaba alguien?`;
        }, 6000);
    });
});

/** Connect with sixth-sense: its address and the token made in Configuración → Wingman. */
/** The header's pill: whether this extension talks to sixth-sense. */
function link(state, label) {
    $('link-dot').className = `dot ${state}`;
    $('link-label').textContent = label;
}

async function showConnection() {
    const { server, token } = await chrome.storage.local.get(['server', 'token']);
    $('server').value = server ?? '';
    $('token').value = token ?? '';

    if (!server || !token) {
        $('connection').open = true;
        $('connection-summary').textContent = 'Conecta con tu sixth-sense';
        link('', 'Sin conectar');

        return;
    }

    const answer = await chrome.runtime.sendMessage({ type: 'ping' });
    $('connection-summary').textContent = answer?.ok ? `Conectado como ${answer.data.user}` : 'Conexión con sixth-sense · revisar';
    $('connection-status').innerHTML = answer?.ok ? `<span>${escape(server)}</span>` : `<span class="bad">${escape(answer?.error ?? 'Sin respuesta')}</span>`;
    $('connection').open = !answer?.ok;
    link(answer?.ok ? 'ok' : 'bad', answer?.ok ? 'Conectado' : 'Revisar');
}

$('connect').addEventListener('click', async () => {
    let origin;

    try {
        origin = new URL($('server').value.trim()).origin;
    } catch {
        $('connection-status').innerHTML = '<span class="bad">El servidor no es una dirección válida.</span>';
        return;
    }

    // Permission to talk to that server only (asked once).
    const granted = await chrome.permissions.request({ origins: [`${origin}/*`] });

    if (!granted) {
        $('connection-status').innerHTML = '<span class="bad">Sin permiso para hablar con ese servidor.</span>';
        return;
    }

    await chrome.storage.local.set({ server: origin, token: $('token').value.trim() });
    $('connection-status').textContent = 'Comprobando…';
    await showConnection();
});

void showConnection();
void refresh();
setInterval(refresh, 1000);
