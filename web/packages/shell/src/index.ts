// Capability-driven shell: session, boot, menu, login — and the gate for the
// shop-floor screens, which enter with a PIN rather than a browser session.

export { AppShell } from './AppShell'
export { Boot } from './Boot'
export { backToDashboard, useDoorway } from './doorway'
export type { EntryMode } from './doorway'
export { LoginScreen } from './LoginScreen'
export { ServerUnavailable } from './ServerUnavailable'
export { SupervisionBanner } from './SupervisionBanner'
export { buildMenu, splitMenu } from './menu'
export type { MenuEntry, MenuGroup, ModuleUi } from './menu'
export { boot, login, logout, useSession } from './session'
export type { SessionStatus } from './session'
export { terminal } from './terminal'
export { TerminalGate } from './TerminalGate'
export type { Staff } from './TerminalGate'
