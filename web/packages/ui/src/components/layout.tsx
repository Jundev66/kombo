import type { ReactNode } from 'react'
import { cn } from '../lib/cn'

/**
 * Cómo se reparte el ancho. **Las dos formas de no ser responsive.**
 *
 * El sistema nació para el teléfono, y eso sigue siendo correcto: la caja es
 * una PC de mostrador con una pantalla pequeña y la cocina una tablet barata.
 * Lo que no era correcto es lo que pasaba de ahí para arriba, y fallaba de dos
 * maneras opuestas:
 *
 * - **El panel y la super administración** se quedaban en una columna estrecha
 *   con dos márgenes grises enormes: en un portátil usaban la mitad de la
 *   pantalla, y dentro de esa columna cada tarjeta se estiraba a 736 px para
 *   sostener cuatro líneas de texto.
 * - **El portal** hacía lo contrario: sin tope de ancho, estiraba filas de
 *   teléfono a metro y medio. Un producto ocupaba una franja entera con dos
 *   palabras en la esquina.
 *
 * Las dos se arreglan aquí y no en cada pantalla: doce pantallas resolviendo
 * cada una su ancho es doce respuestas distintas a la misma pregunta.
 */

type Ancho = 'lectura' | 'tablero' | 'completo'

const ANCHOS: Record<Ancho, string> = {
  /**
   * Formularios y textos: el horario, la tasa, un pedido.
   *
   * Se para donde una línea deja de leerse cómoda. Estirar un formulario hasta
   * el borde no lo mejora — sólo aleja la etiqueta de su campo.
   */
  lectura: 'max-w-3xl',

  /**
   * Listas y rejillas: los pedidos, la carta, los clientes.
   *
   * Aquí sí compensa el ancho, porque lo que se gana es **cuántas caben a la
   * vez**. El tope existe igualmente: más allá de esto la fila es tan larga que
   * el ojo pierde el renglón entre el nombre y el importe.
   */
  tablero: 'max-w-7xl',

  /** Sin tope: la caja y la cocina, que son herramientas a pantalla completa. */
  completo: '',
}

/**
 * El contenedor de una pantalla.
 *
 * `mx-auto` es lo que la centra cuando sobra ancho, y el relleno crece con la
 * pantalla: 16 px en un teléfono son los que hacen falta, y en un portátil se
 * ven apretados contra el borde.
 */
export function Page({
  ancho = 'tablero',
  className,
  children,
}: {
  ancho?: Ancho
  className?: string
  children: ReactNode
}) {
  return (
    <div className={cn('mx-auto w-full px-4 sm:px-6 lg:px-8', ANCHOS[ancho], className)}>
      {children}
    </div>
  )
}

/**
 * Una rejilla de tarjetas que crece con la pantalla.
 *
 * Una columna en el teléfono —donde la tarjeta ES la fila— y hasta tres en un
 * portátil. En el tablero de pedidos eso es la diferencia entre ver siete de un
 * vistazo y ver veinte: con veintidós pedidos abiertos, los que no se ven son
 * los que no se atienden.
 *
 * Los cortes van por CONTENIDO y no por tamaño de dispositivo. Una tarjeta de
 * pedido necesita unos 320 px para que el nombre del producto no se parta, así
 * que la segunda columna entra cuando caben dos, no cuando alguien decide que
 * eso es «una tablet».
 */
export function CardGrid({
  columnas = 3,
  className,
  children,
}: {
  /** El máximo. Con 2, no pasa de dos por ancha que sea la pantalla. */
  columnas?: 2 | 3
  className?: string
  children: ReactNode
}) {
  return (
    <ul
      className={cn(
        // Los cortes salen de medir la tarjeta, no de nombres de dispositivo:
        // una de pedido necesita ~320 px para que el nombre del producto no se
        // parta. A 768 px caben dos; a 1280, tres. El primer intento las metía
        // en `lg`/`2xl` y un portátil de 1512 se quedaba en dos columnas de
        // 600 px — el mismo desperdicio de antes, con un paso menos.
        'grid grid-cols-1 gap-3 md:grid-cols-2',
        columnas === 3 && 'xl:grid-cols-3',
        className,
      )}
    >
      {children}
    </ul>
  )
}
