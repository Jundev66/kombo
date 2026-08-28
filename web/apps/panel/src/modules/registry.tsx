import type { ModuleUi } from '@kombo/shell'
import { CategoriesScreen } from '../screens/CategoriesScreen'
import { ModifiersScreen } from '../screens/ModifiersScreen'
import { OrdersScreen } from '../screens/OrdersScreen'
import { ProductsScreen } from '../screens/ProductsScreen'
import { RateScreen } from '../screens/RateScreen'
import { ZonesScreen } from '../screens/ZonesScreen'

/**
 * Cómo se dibuja cada módulo en el panel. **No cuáles existen.**
 *
 * Un módulo que este negocio no tiene no aparece, y uno que el frontend
 * todavía no sabe dibujar tampoco — sin romper nada. Por eso las fases que
 * vienen se añaden aquí y en ningún otro sitio.
 */
export const MODULE_UI: ModuleUi[] = [
  {
    // Primero, y con el sitio 1: es la pantalla donde se trabaja. La carta se
    // carga una vez y se toca poco; los pedidos se miran todo el día.
    module: 'orders',
    path: '/pedidos',
    permission: 'orders.view',
    Screen: OrdersScreen,
    icon: '🧾',
    label: 'Pedidos',
    primary: 1,
  },
  {
    module: 'catalog',
    path: '/carta',
    permission: 'catalog.view',
    Screen: ProductsScreen,
    icon: '🍽️',
    label: 'Carta',
    primary: 2,
  },
  {
    module: 'catalog',
    path: '/categorias',
    permission: 'catalog.manage',
    Screen: CategoriesScreen,
    icon: '🗂️',
    label: 'Categorías',
  },
  {
    module: 'catalog',
    path: '/agregados',
    permission: 'catalog.manage',
    Screen: ModifiersScreen,
    icon: '➕',
    label: 'Agregados',
  },
  {
    module: 'delivery',
    path: '/zonas',
    permission: 'delivery.manage',
    Screen: ZonesScreen,
    icon: '🛵',
    label: 'Zonas',
  },
  {
    module: 'core',
    path: '/tasa',
    permission: 'settings.manage',
    Screen: RateScreen,
    icon: '💵',
    label: 'Tasa',
    primary: 3,
  },

  // Fase 6: portal · Fase 7: canales
  // Fase 8: equipo y configuración
]
