import { toast } from '@/lib/toast';

const version = '11.6.0';

/**
 * Registers this browser with FCM when permission is already granted.
 * The web config is public; it is null until Firebase is configured.
 */
export async function registerStoredPush(config) {
    if (!config?.apiKey || typeof Notification === 'undefined' || Notification.permission !== 'granted') {
        return;
    }

    await registerToken(config);
}

export async function enablePush(config) {
    if (!config?.apiKey || typeof Notification === 'undefined') {
        throw new Error('Firebase is not configured.');
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        throw new Error('Permission was not granted.');
    }

    await registerToken(config);
}

async function registerToken(config) {
    const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
    const appModule = await import(/* @vite-ignore */ `https://www.gstatic.com/firebasejs/${version}/firebase-app.js`);
    const messagingModule = await import(/* @vite-ignore */ `https://www.gstatic.com/firebasejs/${version}/firebase-messaging.js`);
    const app = appModule.initializeApp({
        apiKey: config.apiKey,
        authDomain: config.authDomain,
        projectId: config.projectId,
        messagingSenderId: config.messagingSenderId,
        appId: config.appId,
    });
    const messaging = messagingModule.getMessaging(app);
    const token = await messagingModule.getToken(messaging, {
        vapidKey: config.vapidKey,
        serviceWorkerRegistration: registration,
    });
    if (!token) {
        return;
    }

    await window.axios.post(route('devices.store'), { token, platform: 'web' });
    messagingModule.onMessage(messaging, (payload) => {
        const title = payload.notification?.title;
        if (title) {
            toast.success(title);
        }
    });
}
