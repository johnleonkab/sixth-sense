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
    if (!status) {
        $('call').textContent = 'Abre una pestaña de Meet';
        return;
    }

    if (status.unreachable) {
        $('call').innerHTML = '<span class="bad">Recarga la pestaña de Meet</span>';
        return;
    }

    $('call').innerHTML = status.inCall ? `<span class="ok">Dentro · ${status.code ?? 'sin código'}</span>` : 'Fuera de la llamada';
    $('captions').innerHTML = status.captionsFound
        ? `<span class="ok">Leyendo</span>${status.hidden ? ' · ocultos' : ' · visibles'}`
        : status.inCall
          ? '<span class="bad">No los encuentro</span>'
          : '—';
    $('count').textContent = `${status.segments.length} (${status.segments.filter((segment) => segment.final).length} cerradas)`;
    $('speakers').textContent = status.speakers.join(', ') || '—';
    $('sync').innerHTML = !status.inCall
        ? '—'
        : status.lastError
          ? `<span class="bad">${escape(status.lastError)}</span>`
          : status.meetingId
            ? `<span class="ok">${escape(status.meetingTitle ?? 'Llamada')}</span> · ${status.paused ? 'en pausa desde sixth-sense' : `${status.sent} enviadas`}`
            : 'Avisando…';
    $('toggle-hidden').textContent = status.hidden ? 'Mostrar subtítulos' : 'Ocultar subtítulos';
    $('toggle-hidden').dataset.hidden = String(status.hidden);

    const lines = status.segments.slice(-6);
    $('lines').innerHTML = lines.length
        ? lines.map((segment) => `<p><b>${escape(segment.speaker)}:</b> ${escape(segment.text)}</p>`).join('')
        : '<p>Todavía nada. Habla o espera a que alguien hable.</p>';
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
async function showConnection() {
    const { server, token } = await chrome.storage.local.get(['server', 'token']);
    $('server').value = server ?? '';
    $('token').value = token ?? '';

    if (!server || !token) {
        $('connection').open = true;
        $('connection-summary').textContent = 'Conexión con sixth-sense · sin conectar';

        return;
    }

    const answer = await chrome.runtime.sendMessage({ type: 'ping' });
    $('connection-summary').textContent = answer?.ok ? `Conectado como ${answer.data.user}` : 'Conexión con sixth-sense · revisar';
    $('connection-status').innerHTML = answer?.ok ? '' : `<span class="bad">${escape(answer?.error ?? 'Sin respuesta')}</span>`;
    $('connection').open = !answer?.ok;
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
