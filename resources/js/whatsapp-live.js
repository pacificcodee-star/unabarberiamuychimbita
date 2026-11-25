import Pusher from 'pusher-js';

export function connectWhatsAppLive(options, refresh, setConnected) {
    const socket = new Pusher(options.key, {
        cluster: '',
        wsHost: location.hostname,
        wsPath: '/whatsapp-live',
        wsPort: Number(location.port || 80),
        wssPort: Number(location.port || 443),
        forceTLS: location.protocol === 'https:',
        enabledTransports: ['ws', 'wss'],
        enableStats: false,
        channelAuthorization: {
            endpoint: options.authUrl,
            headers: { 'X-CSRF-TOKEN': options.csrfToken, Accept: 'application/json' },
        },
    });

    const channel = socket.subscribe(options.channel);
    channel.bind('chat.updated', refresh);
    channel.bind('pusher:subscription_succeeded', () => setConnected(true));
    channel.bind('pusher:subscription_error', () => setConnected(false));
    socket.connection.bind('state_change', ({ current }) => {
        if (current !== 'connected') setConnected(false);
    });

    return () => socket.disconnect();
}
