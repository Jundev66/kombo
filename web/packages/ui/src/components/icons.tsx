import type { ComponentType, ReactNode } from 'react'

/**
 * The icons, drawn by hand and inline.
 *
 * Not a library, and not out of preference: the startup budget is 180 KB at the
 * till and 120 KB in the kitchen, and any icon package takes a slice that never
 * comes back. These are fifteen strokes weighing under a kilobyte in total,
 * each exported separately so an app that does not use one does not carry it.
 *
 * They used to be emoji. Every operating system draws its own, so the same
 * screen looked different on the kitchen tablet, the owner's phone and the
 * counter PC — and some did not exist in an old Android's font, where a square
 * appeared instead. An icon that is sometimes a square is not an icon.
 */
export type Icon = ComponentType<{ className?: string }>

function Glyph({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      strokeLinecap="round"
      strokeLinejoin="round"
      // Decorative: the text label is always alongside, so announcing them would
      // repeat every menu entry twice.
      aria-hidden="true"
      className={className ?? 'size-6'}
    >
      {children}
    </svg>
  )
}

/** Orders. */
export const ReceiptIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M6 2h12v20l-3-2-3 2-3-2-3 2Z" />
    <path d="M9.5 7.5h5M9.5 11.5h5" />
  </Glyph>
)

/** The menu. */
export const MenuIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M6 2v5a2 2 0 0 0 4 0V2M8 9v13" />
    <path d="M16 2c1.5 1.2 2 3 2 5s-.5 3.2-2 4v11" />
  </Glyph>
)

/** Categories. */
export const FolderIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" />
  </Glyph>
)

/** Add-ons. */
export const PlusCircleIcon: Icon = (props) => (
  <Glyph {...props}>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 8.5v7M8.5 12h7" />
  </Glyph>
)

/** Deliveries. */
export const TruckIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M2 6h11v10H2ZM13 9.5h4l3 3V16h-7" />
    <circle cx="7" cy="18" r="2" />
    <circle cx="17" cy="18" r="2" />
  </Glyph>
)

/** A customer. */
export const UserIcon: Icon = (props) => (
  <Glyph {...props}>
    <circle cx="12" cy="8" r="4" />
    <path d="M4.5 21a7.5 7.5 0 0 1 15 0" />
  </Glyph>
)

/** Delivery zones. */
export const PinIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z" />
    <circle cx="12" cy="10" r="2.5" />
  </Glyph>
)

/** Sales. */
export const ChartIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M3 21h18" />
    <path d="M6 21v-6M11 21V7M16 21v-9M21 21V4" />
  </Glyph>
)

/** WhatsApp and the channels. */
export const ChatIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M21 12a8 8 0 0 1-8 8H8l-5 3 1.2-4.3A8 8 0 0 1 11 4h2a8 8 0 0 1 8 8Z" />
  </Glyph>
)

/** Opening hours. */
export const ClockIcon: Icon = (props) => (
  <Glyph {...props}>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 7v5.2l3.2 2" />
  </Glyph>
)

/** The team. */
export const UsersIcon: Icon = (props) => (
  <Glyph {...props}>
    <circle cx="9" cy="8" r="3.5" />
    <path d="M2.5 20a6.5 6.5 0 0 1 13 0" />
    <path d="M16 5.2a3.5 3.5 0 0 1 0 5.6M17.5 14.2A6.5 6.5 0 0 1 21.5 20" />
  </Glyph>
)

/** The rate of the day. */
export const BanknoteIcon: Icon = (props) => (
  <Glyph {...props}>
    <rect x="2" y="6" width="20" height="12" rx="2" />
    <circle cx="12" cy="12" r="2.5" />
    <path d="M6 12h.01M18 12h.01" />
  </Glyph>
)

/** The counter till. */
export const RegisterIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M3 9 4.5 4h15L21 9M4 9v11h16V9" />
    <path d="M9.5 20v-5.5h5V20" />
  </Glyph>
)

/** The kitchen. */
export const FlameIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M12 22a6 6 0 0 0 6-6c0-5-6-14-6-14S6 11 6 16a6 6 0 0 0 6 6Z" />
    <path d="M12 18a2.5 2.5 0 0 0 2.5-2.5c0-2-2.5-5.5-2.5-5.5s-2.5 3.5-2.5 5.5A2.5 2.5 0 0 0 12 18Z" />
  </Glyph>
)

/** "More". */
export const MoreIcon: Icon = (props) => (
  <Glyph {...props}>
    <circle cx="5" cy="12" r="1.25" fill="currentColor" />
    <circle cx="12" cy="12" r="1.25" fill="currentColor" />
    <circle cx="19" cy="12" r="1.25" fill="currentColor" />
  </Glyph>
)

/** This leaves the app. */
export const ExternalIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M8 16 16 8M9.5 8H16v6.5" />
  </Glyph>
)
