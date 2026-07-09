const DEFAULT_IDLE_TIMEOUT_MS = Number(import.meta.env.VITE_NURSE_IDLE_TIMEOUT_MS ?? 15 * 60 * 1000);

export function createIdleWipeScheduler(
    onIdle: () => void | Promise<void>,
    timeoutMs: number = DEFAULT_IDLE_TIMEOUT_MS,
): { activity: () => void; stop: () => void } {
    let timer: number | undefined;

    const arm = () => {
        if (timer !== undefined) {
            window.clearTimeout(timer);
        }

        timer = window.setTimeout(() => {
            void onIdle();
        }, timeoutMs);
    };

    arm();

    return {
        activity: arm,
        stop: () => {
            if (timer !== undefined) {
                window.clearTimeout(timer);
            }
        },
    };
}

export function startIdleWipe(onIdle: () => void | Promise<void>, timeoutMs = DEFAULT_IDLE_TIMEOUT_MS): () => void {
    const scheduler = createIdleWipeScheduler(onIdle, timeoutMs);
    const events = ['click', 'keydown', 'touchstart', 'pointerdown'];

    events.forEach((event) => window.addEventListener(event, scheduler.activity, { passive: true }));

    return () => {
        events.forEach((event) => window.removeEventListener(event, scheduler.activity));
        scheduler.stop();
    };
}
