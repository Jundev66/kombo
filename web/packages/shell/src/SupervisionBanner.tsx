import type { UserSummary } from '@kombo/api-client'

/**
 * «Esta pantalla la está manejando el dueño desde su sesión, no un turno.»
 *
 * Existe por dos razones, y la segunda importa más que la primera.
 *
 * **La primera**: el dueño tiene que poder abrir su propia caja para
 * supervisar. Pedirle el alta del aparato y un PIN cuando acaba de entrar al
 * panel con su contraseña es hacerle demostrar tres veces quién es.
 *
 * **La segunda**: esto ya pasaba, sólo que en silencio. Sanctum prefiere la
 * cookie de sesión al token, así que en una máquina donde alguien dejó el panel
 * abierto, el cajero tecleaba su PIN y **todo se ejecutaba a nombre del dueño**
 * sin que nada lo dijera. El síntoma era un permiso que debería faltar y no
 * faltaba. La banda convierte esa trampa en un hecho visible: la pantalla dice
 * quién está operando de verdad, porque lo dice `/me` y no el token guardado.
 *
 * Va en ámbar y no en gris a propósito. No es un adorno: es la advertencia de
 * que lo que se venda aquí lleva ESTE nombre. Y ámbar y no rojo porque no es un
 * fallo —el rojo está reservado para lo que salió mal—, es un «ojo con esto».
 *
 * El mismo par de colores que el aviso de la cocina, que se lee de lejos y
 * funciona igual sobre el tema claro de la caja que sobre el oscuro de allí.
 */
export function SupervisionBanner({ user, onLeave }: { user: UserSummary; onLeave: () => void }) {
  return (
    <div
      role="status"
      // Con nombre: en esta pantalla hay más de una región `status` —el aviso
      // de que la carta está cargando es otra— y sin nombre son
      // indistinguibles, tanto para un lector de pantalla como para quien
      // escribe una prueba.
      aria-label="Supervisión"
      className="flex shrink-0 items-center gap-3 bg-warn-500 px-4 py-2 text-sm text-ink-900"
    >
      <span aria-hidden="true">⚠</span>

      <p className="min-w-0 flex-1 truncate font-medium">
        Supervisando · {user.name}
        {user.roleName != null && ` (${user.roleName})`}
      </p>

      {/* No dice «Salir»: no hay turno que cerrar. Quien llegó aquí desde el
          panel espera volver al panel, no quedarse en una pantalla vacía. */}
      <button
        type="button"
        onClick={onLeave}
        className="min-h-11 shrink-0 font-medium underline-offset-2 hover:underline"
      >
        Volver al panel
      </button>
    </div>
  )
}
