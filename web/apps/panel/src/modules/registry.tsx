import type { ModuleUi } from '@kombo/shell'
import {
  BanknoteIcon,
  ChartIcon,
  ChatIcon,
  ClockIcon,
  FlameIcon,
  FolderIcon,
  MenuIcon,
  PinIcon,
  PlusCircleIcon,
  ReceiptIcon,
  RegisterIcon,
  TruckIcon,
  UserIcon,
  UsersIcon,
} from '@kombo/ui'
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
 *
 * Los grupos son de este archivo y no del servidor a propósito: qué módulos
 * existen lo decide el backend, y cómo se ordenan en una pantalla es una
 * decisión de esta pantalla.
 */
export const MODULE_UI: ModuleUi[] = [
  {
    // Primero, y con el sitio 1: es la pantalla donde se trabaja. La carta se
    // carga una vez y se toca poco; los pedidos se miran todo el día.
    module: 'orders',
    path: '/pedidos',
    permission: 'orders.view',
    Screen: OrdersScreen,
    Icon: ReceiptIcon,
    label: 'Pedidos',
    primary: 1,
  },
  {
    module: 'catalog',
    path: '/carta',
    permission: 'catalog.view',
    Screen: ProductsScreen,
    Icon: MenuIcon,
    label: 'Carta',
    primary: 2,
  },
  {
    // Tercero en el sitio de honor: es lo que el dueño abre desde el teléfono
    // al cerrar, y no tiene por qué buscarlo.
    module: 'reports',
    path: '/ventas',
    permission: 'reports.view_sales',
    Screen: ReportsScreen,
    Icon: ChartIcon,
    label: 'Ventas',
    primary: 3,
  },

  /*
   * Las otras dos pantallas del local, desde aquí.
   *
   * Antes eran islas: para pasar del panel a la caja había que saberse la URL
   * de memoria. Y no es una comodidad menor —el dueño abre su caja para
   * supervisar, que es justo lo que no podía hacer sin volver a identificarse
   * dos veces—.
   *
   * Van con su módulo y su permiso como cualquier otra entrada, así que un
   * negocio sin mostrador no ve el enlace: la misma regla que hace que sus
   * rutas respondan 404, sin una excepción escrita aquí.
   */
  {
    module: 'counter',
    path: '/caja',
    href: '/caja/',
    permission: 'counter.sell',
    Icon: RegisterIcon,
    label: 'Caja',
    group: 'Pantallas del local',
  },
  {
    module: 'kitchen',
    path: '/cocina',
    href: '/cocina/',
    permission: 'kitchen.view',
    Icon: FlameIcon,
    label: 'Cocina',
    group: 'Pantallas del local',
  },

  {
    module: 'catalog',
    path: '/categorias',
    permission: 'catalog.manage',
    Screen: CategoriesScreen,
    Icon: FolderIcon,
    label: 'Categorías',
    group: 'Carta',
  },
  {
    module: 'catalog',
    path: '/agregados',
    permission: 'catalog.manage',
    Screen: ModifiersScreen,
    Icon: PlusCircleIcon,
    label: 'Agregados',
    group: 'Carta',
  },

  {
    // Primero que las zonas: el repartidor abre esto todo el día y el dueño
    // toca las tarifas una vez al mes.
    module: 'delivery',
    path: '/entregas',
    permission: 'delivery.view_own',
    Screen: DeliveriesScreen,
    Icon: TruckIcon,
    label: 'Entregas',
    group: 'Reparto y clientes',
  },
  {
    module: 'delivery',
    path: '/zonas',
    permission: 'delivery.manage',
    Screen: ZonesScreen,
    Icon: PinIcon,
    label: 'Zonas',
    group: 'Reparto y clientes',
  },
  {
    module: 'customers',
    path: '/clientes',
    permission: 'customers.view',
    Screen: CustomersScreen,
    Icon: UserIcon,
    label: 'Clientes',
    group: 'Reparto y clientes',
  },

  {
    // El horario vive en `core` porque no es opcional: sin él, el portal no
    // acepta un solo pedido.
    module: 'core',
    path: '/horario',
    permission: 'settings.manage',
    Screen: HoursScreen,
    Icon: ClockIcon,
    label: 'Horario',
    group: 'Negocio',
  },
  {
    module: 'core',
    path: '/tasa',
    permission: 'settings.manage',
    Screen: RateScreen,
    Icon: BanknoteIcon,
    label: 'Tasa',
    group: 'Negocio',
  },
  {
    module: 'channels',
    path: '/canales',
    permission: 'channels.view',
    Screen: ChannelsScreen,
    Icon: ChatIcon,
    label: 'WhatsApp',
    group: 'Negocio',
  },
  {
    module: 'core',
    path: '/equipo',
    permission: 'users.manage',
    Screen: TeamScreen,
    Icon: UsersIcon,
    label: 'Equipo',
    group: 'Negocio',
  },
]
