const STORAGE_KEY = 'sixth-sense.microphone';

export type MicrophoneOption = {
    id: string;
    label: string;
};

/**
 * The microphone this browser should use. Empty means the system default.
 * Device ids belong to this machine, so they stay in the browser.
 */
export function chosenMicrophone(): string {
    try {
        return localStorage.getItem(STORAGE_KEY) ?? '';
    } catch {
        return '';
    }
}

export function rememberMicrophone(deviceId: string): void {
    try {
        if (deviceId === '') {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, deviceId);
        }
    } catch {
        // Private mode can refuse storage. The choice still applies until reload.
    }
}

/**
 * Audio inputs this browser can see. Names stay blank until the page is allowed to use the microphone.
 */
export async function listMicrophones(): Promise<{ devices: MicrophoneOption[]; named: boolean }> {
    if (navigator.mediaDevices?.enumerateDevices === undefined) {
        return { devices: [], named: false };
    }

    const found = (await navigator.mediaDevices.enumerateDevices()).filter(
        (device) => device.kind === 'audioinput' && device.deviceId !== '' && device.deviceId !== 'default' && device.deviceId !== 'communications',
    );

    return {
        devices: found.map((device, index) => ({
            id: device.deviceId,
            label: device.label !== '' ? device.label : `Micrófono ${index + 1}`,
        })),
        named: found.some((device) => device.label !== ''),
    };
}

/** Asks once, so the browser can show the real names, then releases the microphone. */
export async function allowMicrophones(): Promise<void> {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    stream.getTracks().forEach((track) => track.stop());
}
