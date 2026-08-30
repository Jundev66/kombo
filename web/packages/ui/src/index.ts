// Sistema visual: componentes, tema y los principios que los gobiernan.
// Los principios están en web/AGENTS.md; en una revisión se resuelve
// señalando uno de ellos, no por gusto.

export { Button, buttonClasses } from './components/Button'
export type { ButtonSize, ButtonVariant } from './components/Button'
export { Field } from './components/Field'
export {
  BanknoteIcon,
  ChartIcon,
  ChatIcon,
  ClockIcon,
  ExternalIcon,
  FlameIcon,
  FolderIcon,
  MenuIcon,
  MoreIcon,
  PinIcon,
  PlusCircleIcon,
  ReceiptIcon,
  RegisterIcon,
  TruckIcon,
  UserIcon,
  UsersIcon,
} from './components/icons'
export type { Icon } from './components/icons'
export { Input } from './components/Input'
export { CardGrid, Page } from './components/layout'
export { Money } from './components/Money'
export { Badge, Card, EmptyState, ListFooter, Spinner } from './components/primitives'
export { Select, Textarea, Toggle } from './components/Select'
export { Sheet } from './components/Sheet'
export { brandSurface } from './lib/brand'
export type { BrandSurface } from './lib/brand'
export { cn } from './lib/cn'
export { formatBs, formatUsd, parseAmount, toAmountInput, toBs } from './lib/money'
export type { Cents, Rate } from './lib/money'
export { plural } from './lib/plural'
