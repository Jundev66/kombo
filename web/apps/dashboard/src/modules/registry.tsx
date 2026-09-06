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
 * How each module is drawn in the dashboard. NOT which ones exist.
 *
 * A module this tenant does not have does not appear, and neither does one the
 * frontend cannot yet draw — without breaking anything.
 *
 * The groups belong to this file rather than the server on purpose: which
 * modules exist is the backend's call, and how they are ordered on a screen is
 * this screen's.
 */
export const MODULE_UI: ModuleUi[] = [
  {
    // First, in slot 1: it is the screen people work on. The menu is loaded once
    // and touched rarely; the orders are watched all day.
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
    // Third in the place of honour: it is what the owner opens on their phone at
    // closing time, and should not have to be hunted for.
    module: 'reports',
    path: '/ventas',
    permission: 'reports.view_sales',
    Screen: ReportsScreen,
    Icon: ChartIcon,
    label: 'Ventas',
    primary: 3,
  },

  /*
   * The other two shop-floor screens, from here.
   *
   * They used to be islands: getting from the dashboard to the till meant
   * knowing the URL by heart. Not a minor convenience — the owner opens their
   * own till to supervise.
   *
   * They carry their module and permission like any other entry, so a tenant
   * with no counter does not see the link: the same rule that makes its routes
   * answer 404, with no exception written here.
   */
  {
    module: 'counter',
    path: '/caja',
    href: '/pos/',
    permission: 'counter.sell',
    Icon: RegisterIcon,
    label: 'Caja',
    group: 'Pantallas del local',
  },
  {
    module: 'kitchen',
    path: '/cocina',
    href: '/kds/',
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
    // Before the zones: the courier opens this all day and the owner touches
    // the fees once a month.
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
    // The opening hours live in `core` because they are not optional: without
    // them the portal takes no orders at all.
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
