import type { Page } from '@playwright/test'

/**
 * Calls the API FROM INSIDE the page, not from Node.
 *
 * Three reasons, and the first bites immediately:
 *
 * 1. `page.request` resolves names with NODE, so it does not see
 *    `--host-resolver-rules`. Inside the container `elsazon.localhost` simply
 *    does not exist, and the request fails with a DNS error that looks like the
 *    API being down.
 * 2. From the page, that origin's session cookie travels — and each tenant is
 *    its own origin, which is exactly what we want to exercise.
 * 3. Asking is deterministic; spying is not. The dev server runs React
 *    StrictMode, firing every effect twice, so a `waitForResponse` can capture
 *    the PREVIOUS user's second response.
 */
export async function apiFetch<T>(page: Page, path: string): Promise<T> {
    return page.evaluate(async (p: string) => {
        const response = await fetch(p, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })

        if (!response.ok) {
            throw new Error(`${p} respondió ${response.status}`)
        }

        const text = await response.text()

        try {
            return JSON.parse(text) as unknown
        } catch {
            return text as unknown
        }
    }, path) as Promise<T>
}

/** A response's raw state, without requiring it to be successful. */
export async function apiStatus(page: Page, path: string): Promise<number> {
    return page.evaluate(async (p: string) => {
        const response = await fetch(p, { credentials: 'same-origin' })

        return response.status
    }, path)
}

/**
 * A POST from inside the page, with the CSRF cookie Laravel requires.
 *
 * For SEEDING what a test needs — an order that cannot yet be created from any
 * screen — without leaving the browser or losing the tenant's session.
 */
export async function apiPost<T>(page: Page, path: string, body: unknown): Promise<T> {
    return page.evaluate(
        async ({ p, b }: { p: string; b: unknown }) => {
            const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1]

            const response = await fetch(p, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
                },
                body: JSON.stringify(b),
            })

            const text = await response.text()

            if (!response.ok) {
                throw new Error(`${p} respondió ${response.status}: ${text}`)
            }

            return JSON.parse(text) as unknown
        },
        { p: path, b: body },
    ) as Promise<T>
}
