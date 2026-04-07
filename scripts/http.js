const DEFAULT_TIMEOUT_MS = 15000;
const DEFAULT_RETRIES = 1;
const DEFAULT_RETRY_DELAY_MS = 250;

function wait(ms) {
    return new Promise((resolve) => {
        setTimeout(resolve, ms);
    });
}

function toErrorMessage(payload, status) {
    return payload?.error?.message || `Request failed (${status})`;
}

export async function requestApi(url, options = {}, config = {}) {
    const timeoutMs = Number.isFinite(config.timeoutMs) ? Math.max(0, config.timeoutMs) : DEFAULT_TIMEOUT_MS;
    const retries = Number.isFinite(config.retries) ? Math.max(0, config.retries) : DEFAULT_RETRIES;
    const retryDelayMs = Number.isFinite(config.retryDelayMs) ? Math.max(0, config.retryDelayMs) : DEFAULT_RETRY_DELAY_MS;

    for (let attempt = 0; attempt <= retries; attempt += 1) {
        const controller = new AbortController();
        const hasUserSignal = !!options.signal;
        const requestOptions = { ...options };
        const method = String(requestOptions.method || 'GET').toUpperCase();
        let timeoutId = null;

        if (!hasUserSignal) {
            requestOptions.signal = controller.signal;
        }

        if (method === 'GET' && requestOptions.cache === undefined) {
            requestOptions.cache = 'no-store';
        }

        if (!hasUserSignal && timeoutMs > 0) {
            timeoutId = setTimeout(() => {
                controller.abort();
            }, timeoutMs);
        }

        try {
            const response = await fetch(url, requestOptions);
            let payload = null;

            if (response.status !== 204) {
                payload = await response.json().catch(() => null);
            }

            if (!response.ok) {
                if (response.status >= 500 && attempt < retries) {
                    await wait(retryDelayMs * (attempt + 1));
                    continue;
                }

                throw new Error(toErrorMessage(payload, response.status));
            }

            if (response.status === 204) {
                return null;
            }

            if (!payload || payload.success !== true) {
                throw new Error(payload?.error?.message || 'Invalid server response');
            }

            return payload.data;
        } catch (error) {
            const shouldRetry =
                attempt < retries
                && (error instanceof TypeError || error?.name === 'AbortError');

            if (shouldRetry) {
                await wait(retryDelayMs * (attempt + 1));
                continue;
            }

            if (error?.name === 'AbortError') {
                throw new Error(`Request timeout (${timeoutMs} ms)`);
            }

            throw error;
        } finally {
            if (timeoutId !== null) {
                clearTimeout(timeoutId);
            }
        }
    }

    throw new Error('Request failed');
}
