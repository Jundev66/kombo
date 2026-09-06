import type { ReactNode } from 'react'
import { cn } from '../lib/cn'

/**
 * How width is allotted. The two ways of not being responsive.
 *
 * The system was born for the phone, and that is still right. What was wrong is
 * what happened above that size, and it failed in two opposite ways: the
 * dashboard and the platform admin stayed in a narrow column with huge grey
 * margins, while the portal had no cap at all and stretched phone rows a metre
 * and a half wide.
 *
 * Both are fixed here rather than per screen: twelve screens each solving their
 * own width are twelve different answers to one question.
 */

type Width = 'reading' | 'board' | 'full'

const WIDTHS: Record<Width, string> = {
  /**
   * Forms and text: opening hours, the rate, an order.
   *
   * It stops where a line stops reading comfortably. Stretching a form to the
   * edge does not improve it — it only pushes the label away from its field.
   */
  reading: 'max-w-3xl',

  /**
   * Lists and grids: orders, the menu, customers.
   *
   * Here width pays off, because what it buys is how many fit at once. The cap
   * still exists: beyond it the row is long enough that the eye loses the line
   * between the name and the amount.
   */
  board: 'max-w-7xl',

  /** No cap: the till and the kitchen, full-screen tools. */
  full: '',
}

/**
 * A screen's container.
 *
 * `mx-auto` centres it when there is width to spare, and the padding grows with
 * the screen: 16 px is what a phone needs and looks cramped on a laptop.
 */
export function Page({
  width = 'board',
  className,
  children,
}: {
  width?: Width
  className?: string
  children: ReactNode
}) {
  return (
    <div className={cn('mx-auto w-full px-4 sm:px-6 lg:px-8', WIDTHS[width], className)}>
      {children}
    </div>
  )
}

/**
 * A card grid that grows with the screen.
 *
 * One column on the phone — where the card IS the row — and up to three on a
 * laptop. On the orders board that is the difference between seeing seven at a
 * glance and seeing twenty.
 *
 * The breakpoints go by CONTENT, not by device size: an order card needs about
 * 320 px for the product name not to wrap, so the second column arrives when
 * two fit.
 */
export function CardGrid({
  columns = 3,
  className,
  children,
}: {
  /** The maximum. With 2, it never goes past two however wide the screen. */
  columns?: 2 | 3
  className?: string
  children: ReactNode
}) {
  return (
    <ul
      className={cn(
        // Breakpoints come from measuring the card, not from device names: an order
        // card needs ~320 px. Two fit at 768 px, three at 1280. The first attempt
        // used `lg`/`2xl` and a 1512 px laptop stayed at two 600 px columns — the
        // same waste as before, one step later.
        'grid grid-cols-1 gap-3 md:grid-cols-2',
        columns === 3 && 'xl:grid-cols-3',
        className,
      )}
    >
      {children}
    </ul>
  )
}
