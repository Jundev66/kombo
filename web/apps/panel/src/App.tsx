import { AppShell, Boot, useSession } from '@kombo/shell'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { MODULE_UI } from './modules/registry'
import { OrderDetailScreen } from './screens/OrderDetailScreen'
import { ProductFormScreen } from './screens/ProductFormScreen'
import { buildMenu } from '@kombo/shell'

/**
 * El panel del dueño.
 *
 * `basename` es `/panel/` porque nginx sirve esta aplicación bajo esa ruta,
 * dentro del mismo origen que el portal y la cocina. El mismo origen no es
 * casualidad: es lo que hace que el navegador aísle el almacenamiento por
 * negocio sin que nadie escriba una línea.
 */
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // 30 s: suficiente para que moverse entre pantallas no dispare una
      // consulta por cada vuelta, y poco como para no trabajar sobre una carta
      // que otro acaba de cambiar.
      staleTime: 30_000,
      // Un reintento. En una conexión mala, cinco sólo alargan la espera antes
      // de decir que no se pudo.
      retry: 1,
    },
  },
})

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter basename="/panel">
        <Boot>
          <AppShell registry={MODULE_UI}>
            <PanelRoutes />
          </AppShell>
        </Boot>
      </BrowserRouter>
    </QueryClientProvider>
  )
}

function PanelRoutes() {
  const { capabilities } = useSession()

  if (capabilities == null) return null

  // Sólo se registran las rutas de los módulos que este negocio tiene y este
  // usuario puede ver. Quien escriba `/tasa` a mano sin permiso no llega a una
  // pantalla en gris: esa ruta no existe en su aplicación, igual que el
  // endpoint responde 404.
  const entries = buildMenu(MODULE_UI, capabilities)
  const home = entries[0]?.path ?? '/sin-acceso'

  return (
    /*
     * La `key` por usuario no es decorativa: sin ella, al cambiar de persona
     * React no desmonta la pantalla actual —misma ruta— y la anterior sigue
     * viendo datos que el servidor ya no le manda.
     */
    <Routes key={capabilities.user?.id ?? 'anonimo'}>
      {entries.map((entry) => (
        <Route key={entry.path} path={entry.path} element={<entry.Screen />} />
      ))}

      {/* Los detalles no están en el menú: se llega desde su lista. */}
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
