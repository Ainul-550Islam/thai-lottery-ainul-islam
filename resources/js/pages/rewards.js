import './public-next-pages.js';

function base64UrlToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i += 1) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function initReferralCopy() {
    const copyBtn = document.querySelector('[data-copy-referral]');
    const urlInput = document.querySelector('[data-referral-url]');
    const statusEl = document.querySelector('[data-copy-status]');

    if (!copyBtn || !urlInput) {
        return;
    }

    copyBtn.addEventListener('click', async () => {
        const value = urlInput.value.trim();
        if (!value) {
            return;
        }

        try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                await navigator.clipboard.writeText(value);
            } else {
                urlInput.select();
                document.execCommand('copy');
            }

            if (statusEl) {
                statusEl.textContent = copyBtn.dataset.copiedText || 'Copied.';
            }
        } catch {
            urlInput.select();
        }
    });
}

function initWebPushOptIn() {
    const container = document.querySelector('[data-push-endpoint]');
    const enableBtn = document.querySelector('[data-enable-push]');
    const statusEl = document.querySelector('[data-push-status]');

    if (!container || !enableBtn) {
        return;
    }

    enableBtn.addEventListener('click', async () => {
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            if (statusEl) {
                statusEl.textContent = 'Browser notifications are not supported on this device.';
            }
            return;
        }

        try {
            const permission = await window.Notification.requestPermission();
            if (permission !== 'granted') {
                if (statusEl) {
                    statusEl.textContent = 'Notification permission was not granted.';
                }
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const vapidKey = container.dataset.vapidKey || '';
            const subscribeOptions = vapidKey
                ? { userVisibleOnly: true, applicationServerKey: base64UrlToUint8Array(vapidKey) }
                : { userVisibleOnly: true };

            const subscription = await registration.pushManager.subscribe(subscribeOptions);
            const serialized = JSON.stringify(subscription);

            const response = await fetch(container.dataset.pushEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': container.dataset.csrf || '',
                },
                body: JSON.stringify({
                    token: serialized,
                    platform: 'web',
                }),
            });

            if (response.ok) {
                window.location.reload();
            }
        } catch (error) {
            if (statusEl) {
                statusEl.textContent = error instanceof Error ? error.message : 'Unable to register browser alerts.';
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initReferralCopy();
    initWebPushOptIn();
});
