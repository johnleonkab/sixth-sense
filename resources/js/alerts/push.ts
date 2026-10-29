import { deleteJson, postJson } from '@/lib/http';

function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(normalized);
    const output = new Uint8Array(raw.length);

    for (let index = 0; index < raw.length; index++) {
        output[index] = raw.charCodeAt(index);
    }

    return output;
}

/**
 * Whether this browser is already registered for system notifications.
 */
export async function deviceSubscribed(): Promise<boolean> {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return false;
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();

    return subscription !== null;
}

/**
 * Ask for permission from the click that called this, then register the browser.
 */
export async function enableDevicePush(publicKey: string): Promise<void> {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        throw new Error('denied');
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey),
    });
    const json = subscription.toJSON();

    await postJson('/push-subscriptions', {
        endpoint: json.endpoint,
        keys: json.keys,
        contentEncoding: 'aes128gcm',
    });
}

/**
 * Stop system notifications on this browser and forget its registration.
 */
export async function disableDevicePush(): Promise<void> {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();

    if (!subscription) {
        return;
    }

    const endpoint = subscription.endpoint;

    await subscription.unsubscribe();
    await deleteJson('/push-subscriptions', { endpoint });
}
