import type { Page } from '@playwright/test'

/**
 * Llama a la API **desde dentro de la página**, no desde Node.
 *
 * Tres razones, y la primera muerde enseguida:
 *
 * 1. `page.request` / `APIRequestContext` resuelve nombres con **Node**, así
 *    que NO ve `--host-resolver-rules`. Dentro del contenedor,
 *    `elsazon.localhost` sencillamente no existe y la petición falla con un
 *    error de DNS que parece que la API está caída.
 * 2. Desde la página viaja la **cookie de sesión de ese origen**, y cada
 *    negocio es su propio origen. Es justo lo que queremos ejercitar.
 * 3. **Preguntar es determinista; espiar no.** El servidor de desarrollo corre
 *    con StrictMode de React, que dispara cada efecto dos veces: un
 *    `waitForResponse` puede quedarse con la segunda respuesta del usuario
 *    ANTERIOR.
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

/** El estado crudo de una respuesta, sin exigir que sea correcta. */
export async function apiStatus(page: Page, path: string): Promise<number> {
    return page.evaluate(async (p: string) => {
        const response = await fetch(p, { credentials: 'same-origin' })

        return response.status
    }, path)
}
