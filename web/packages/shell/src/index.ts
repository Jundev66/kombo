// Armazón por capacidades: sesión, arranque, menú, login — y la puerta de las
// pantallas del local, que entran con PIN y no con sesión de navegador.

export { AppShell } from './AppShell'
export { Boot } from './Boot'
export { LoginScreen } from './LoginScreen'
export { buildMenu, splitMenu } from './menu'
export type { MenuEntry, ModuleUi } from './menu'
export { boot, login, logout, useSession } from './session'
export type { SessionStatus } from './session'
export { terminal } from './terminal'
export { TerminalGate } from './TerminalGate'
export type { Staff } from './TerminalGate'
