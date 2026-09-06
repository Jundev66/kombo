import { Badge, Button, Card, EmptyState, Field, Input, plural, Spinner, Page, CardGrid} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { catalog } from '../api/catalog'

/**
 * The sections of the menu. They exist so the till has less to look at: a
 * sixty-product menu with no categories cannot be scanned with a customer
 * standing there.
 */
export function CategoriesScreen() {
  const queryClient = useQueryClient()
  const [name, setName] = useState('')

  const categories = useQuery({ queryKey: ['categories'], queryFn: catalog.categories })

  const create = useMutation({
    mutationFn: () => catalog.createCategory(name),
    onSuccess: () => {
      setName('')
      void queryClient.invalidateQueries({ queryKey: ['categories'] })
    },
  })

  const remove = useMutation({
    mutationFn: (id: string) => catalog.deleteCategory(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['categories'] })
      // The products are left without a category, so the menu changed.
      void queryClient.invalidateQueries({ queryKey: ['products'] })
    },
  })

  function onSubmit(event: FormEvent): void {
    event.preventDefault()
    if (name.trim() !== '') create.mutate()
  }

  return (
    <Page width="board" className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Categorías</h1>

      <form onSubmit={onSubmit} className="flex items-end gap-2">
        <div className="flex-1">
          <Field label="Nueva categoría">
            {({ id }) => (
              <Input
                id={id}
                value={name}
                placeholder="Arepas, Bebidas, Postres…"
                onChange={(e) => setName(e.target.value)}
              />
            )}
          </Field>
        </div>
        <Button type="submit" disabled={create.isPending}>
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

      <CardGrid>
        {categories.data?.map((category) => (
          <li key={category.id}>
            <Card className="flex min-h-touch items-center gap-3 p-3">
              <span className="flex-1 font-medium text-[var(--text-strong)]">{category.name}</span>

              <Badge>{plural(category.productCount, 'producto', 'productos')}</Badge>

              <Button
                variant="ghost"
                size="sm"
                onClick={() => remove.mutate(category.id)}
                // Deliberately no confirmation dialog: deleting a category does NOT delete
                // its products — they are left sectionless — so the damage is reversible in
                // two taps. Confirming everything teaches people to confirm without reading.
                aria-label={`Borrar ${category.name}`}
              >
                Borrar
              </Button>
            </Card>
          </li>
        ))}
      </CardGrid>
    </Page>
  )
}
