import { Badge, Button, Card, EmptyState, Field, Input, Spinner } from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { catalog } from '../api/catalog'

/**
 * Las secciones de la carta.
 *
 * Existen para que la caja tenga menos que mirar. Una carta de sesenta
 * productos sin categorías es una lista imposible de recorrer con un cliente
 * delante.
 */
export function CategoriesScreen() {
  const queryClient = useQueryClient()
  const [nombre, setNombre] = useState('')

  const categories = useQuery({ queryKey: ['categories'], queryFn: catalog.categories })

  const crear = useMutation({
    mutationFn: () => catalog.createCategory(nombre),
    onSuccess: () => {
      setNombre('')
      void queryClient.invalidateQueries({ queryKey: ['categories'] })
    },
  })

  const borrar = useMutation({
    mutationFn: (id: string) => catalog.deleteCategory(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['categories'] })
      // Los productos se quedan sin categoría, así que la carta cambió.
      void queryClient.invalidateQueries({ queryKey: ['products'] })
    },
  })

  function onSubmit(event: FormEvent): void {
    event.preventDefault()
    if (nombre.trim() !== '') crear.mutate()
  }

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Categorías</h1>

      <form onSubmit={onSubmit} className="flex items-end gap-2">
        <div className="flex-1">
          <Field label="Nueva categoría">
            {({ id }) => (
              <Input
                id={id}
                value={nombre}
                placeholder="Arepas, Bebidas, Postres…"
                onChange={(e) => setNombre(e.target.value)}
              />
            )}
          </Field>
        </div>
        <Button type="submit" disabled={crear.isPending}>
          Añadir
        </Button>
      </form>

      {categories.isLoading && <Spinner />}

      {categories.data?.length === 0 && (
        <EmptyState
          title="Sin categorías"
          description="No hacen falta para vender, pero con veinte productos se agradecen."
        />
      )}

      <ul className="flex flex-col gap-2">
        {categories.data?.map((category) => (
          <li key={category.id}>
            <Card className="flex min-h-touch items-center gap-3 p-3">
              <span className="flex-1 font-medium text-[var(--text-strong)]">{category.name}</span>

              <Badge>{category.productCount} productos</Badge>

              <Button
                variant="ghost"
                size="sm"
                onClick={() => borrar.mutate(category.id)}
                // Sin diálogo de confirmación a propósito: borrar una categoría
                // NO borra sus productos —se quedan sin sección— así que el
                // daño es reversible en dos toques. Confirmar todo enseña a
                // confirmar sin leer.
                aria-label={`Borrar ${category.name}`}
              >
                Borrar
              </Button>
            </Card>
          </li>
        ))}
      </ul>
    </div>
  )
}
