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
 * The service worker, in production only.
 *
 * In development it gets in the way: it serves JS from two saves ago and costs
 * half an hour hunting a bug that was already fixed.
 *
 * And it is registered AFTER paint (`load`): the first visit has to see the
 * menu as soon as possible, and downloading the worker can wait.
 */
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    void navigator.serviceWorker.register('/sw.js')
  })
}
