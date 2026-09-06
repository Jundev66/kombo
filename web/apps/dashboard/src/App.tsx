import { AppShell, Boot, useSession, type MenuEntry } from '@kombo/shell'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ComponentType } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { MODULE_UI } from './modules/registry'
import { OrderDetailScreen } from './screens/OrderDetailScreen'
import { ProductFormScreen } from './screens/ProductFormScreen'
import { buildMenu } from '@kombo/shell'

/**
 * The owner's dashboard.
 *
 * `basename` is `/dashboard/` because nginx serves this app under that path,
 * inside the same origin as the portal and the kitchen. The shared origin is
 * what makes the browser isolate storage per tenant with nobody writing a line.
 */
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // 30 s: enough that moving between screens does not fire a query each way,
      // and short enough not to work on a menu somebody else just changed.
      staleTime: 30_000,
      // One retry. On a bad connection, five only lengthen the wait before saying
      // it did not work.
      retry: 1,
    },
  },
})

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter basename="/dashboard">
        <Boot>
          <AppShell registry={MODULE_UI}>
            <DashboardRoutes />
          </AppShell>
        </Boot>
      </BrowserRouter>
    </QueryClientProvider>
  )
}

function DashboardRoutes() {
  const { capabilities } = useSession()

  if (capabilities == null) return null

  // Only the routes of modules this tenant has and this user can see are
  // registered. Typing `/rate` by hand without permission does not reach a
  // greyed-out screen: that route does not exist in their app, just as the
  // endpoint answers 404.
  // Entries with `href` — the till, the kitchen — leave this app, so they have
  // no route to register and cannot be the target of "/": sending the owner
  // out of the dashboard on arrival is not a home page.
  const entries = buildMenu(MODULE_UI, capabilities).filter(
    (entry): entry is MenuEntry & { Screen: ComponentType } => entry.Screen != null,
  )
  const home = entries[0]?.path ?? '/sin-acceso'

  return (
    /*
     * The per-user `key` is not decorative: without it, switching person leaves
     * React without unmounting the current screen — same route — and the
     * previous one keeps showing data the server no longer sends.
     */
    <Routes key={capabilities.user?.id ?? 'anonimo'}>
      {entries.map((entry) => (
        <Route key={entry.path} path={entry.path} element={<entry.Screen />} />
      ))}

      {/* Detail screens are not in the menu: you reach them from their list. */}
      <Route path="/carta/nuevo" element={<ProductFormScreen />} />
      <Route path="/carta/:id" element={<ProductFormScreen />} />
      <Route path="/pedidos/:id" element={<OrderDetailScreen />} />

      <Route path="/" element={<Navigate to={home} replace />} />
      <Route
        path="*"
        element={
          <p className="py-12 text-center text-[var(--text-muted)]">
            Esa pantalla no existe para tu negocio.
          </p>
        }
      />
    </Routes>
  )
}
