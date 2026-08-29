import type { ModuleUi } from '@kombo/shell'
import { CategoriesScreen } from '../screens/CategoriesScreen'
import { CustomersScreen } from '../screens/CustomersScreen'
import { DeliveriesScreen } from '../screens/DeliveriesScreen'
import { ChannelsScreen } from '../screens/ChannelsScreen'
import { ModifiersScreen } from '../screens/ModifiersScreen'
import { OrdersScreen } from '../screens/OrdersScreen'
import { ProductsScreen } from '../screens/ProductsScreen'
import { HoursScreen } from '../screens/HoursScreen'
import { ReportsScreen } from '../screens/ReportsScreen'
import { TeamScreen } from '../screens/TeamScreen'
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
    // Primero que las zonas: el repartidor abre esto todo el día y el dueño
    // toca las tarifas una vez al mes.
    module: 'delivery',
    path: '/entregas',
    permission: 'delivery.view_own',
    Screen: DeliveriesScreen,
    icon: '🛵',
    label: 'Entregas',
  },
  {
    module: 'customers',
    path: '/clientes',
    permission: 'customers.view',
    Screen: CustomersScreen,
    icon: '🧑',
    label: 'Clientes',
  },
  {
    module: 'delivery',
    path: '/zonas',
    permission: 'delivery.manage',
    Screen: ZonesScreen,
    icon: '📍',
    label: 'Zonas',
  },
  {
    // Tercero en el sitio de honor: es lo que el dueño abre desde el teléfono
    // al cerrar, y no tiene por qué buscarlo.
    module: 'reports',
    path: '/ventas',
    permission: 'reports.view_sales',
    Screen: ReportsScreen,
    icon: '📈',
    label: 'Ventas',
    primary: 3,
  },
  {
    module: 'channels',
    path: '/canales',
    permission: 'channels.view',
    Screen: ChannelsScreen,
    icon: '💬',
    label: 'WhatsApp',
  },
  {
    // El horario vive en `core` porque no es opcional: sin él, el portal no
    // acepta un solo pedido.
    module: 'core',
    path: '/horario',
    permission: 'settings.manage',
    Screen: HoursScreen,
    icon: '🕘',
    label: 'Horario',
  },
  {
    module: 'core',
    path: '/equipo',
    permission: 'users.manage',
    Screen: TeamScreen,
    icon: '👥',
    label: 'Equipo',
  },
  {
    module: 'core',
    path: '/tasa',
    permission: 'settings.manage',
    Screen: RateScreen,
    icon: '💵',
    label: 'Tasa',
  },

]
