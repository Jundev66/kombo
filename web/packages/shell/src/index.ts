// Armazón por capacidades: sesión, arranque, menú, login — y la puerta de las
// pantallas del local, que entran con PIN y no con sesión de navegador.

export { AppShell } from './AppShell'
export { Boot } from './Boot'
export { backToPanel, useDoorway } from './doorway'
export type { EntryMode } from './doorway'
export { LoginScreen } from './LoginScreen'
export { SupervisionBanner } from './SupervisionBanner'
export { buildMenu, splitMenu } from './menu'
export type { MenuEntry, MenuGroup, ModuleUi } from './menu'
export { boot, login, logout, useSession } from './session'
export type { SessionStatus } from './session'
export { terminal } from './terminal'
export { TerminalGate } from './TerminalGate'
export type { Staff } from './TerminalGate'
