// Sistema visual: componentes, tema y los principios que los gobiernan.
// Los siete principios están en web/CLAUDE.md; en una revisión se resuelve
// señalando uno de ellos, no por gusto.

export { Button, buttonClasses } from './components/Button'
export type { ButtonSize, ButtonVariant } from './components/Button'
export { Field } from './components/Field'
export { Input } from './components/Input'
export { Money } from './components/Money'
export { Badge, Card, EmptyState, Spinner } from './components/primitives'
export { Select, Textarea, Toggle } from './components/Select'
export { cn } from './lib/cn'
export { formatBs, formatUsd, parseAmount, toAmountInput, toBs } from './lib/money'
export type { Cents, Rate } from './lib/money'
