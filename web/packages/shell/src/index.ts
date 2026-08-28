// Armazón por capacidades: sesión, arranque, menú y login.

export { AppShell } from './AppShell'
export { Boot } from './Boot'
export { LoginScreen } from './LoginScreen'
export { buildMenu, splitMenu } from './menu'
export type { MenuEntry, ModuleUi } from './menu'
export { boot, login, logout, useSession } from './session'
export type { SessionStatus } from './session'
