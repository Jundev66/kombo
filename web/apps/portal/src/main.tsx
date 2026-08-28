import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './App'
import './index.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)

/*
 * El service worker, sólo en producción.
 *
 * En desarrollo estorba: sirve de la caché el JS de hace dos guardados y hace
 * perder media hora buscando un fallo que ya estaba arreglado.
 *
 * Y se registra DESPUÉS de pintar (`load`), no antes: la primera visita tiene
 * que ver la carta cuanto antes, y descargar el trabajador puede esperar a que
 * la pantalla esté lista.
 */
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    void navigator.serviceWorker.register('/sw.js')
  })
}
