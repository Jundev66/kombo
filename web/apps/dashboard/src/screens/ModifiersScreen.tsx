import { Badge, Button, Card, EmptyState, Field, Input, Select, Spinner, formatUsd, parseAmount, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { catalog } from '../api/catalog'

/**
 * The add-ons: "no onion", "extra cheese", "how would you like the meat".
 *
 * A group is a QUESTION and its options are the answers. They are created
 * together on one screen because a group with no options is useless, and
 * leaving it half-done leaves a question with no answers on the menu.
 */
export function ModifiersScreen() {
  const queryClient = useQueryClient()
  const groups = useQuery({ queryKey: ['modifier-groups'], queryFn: catalog.modifierGroups })

  const [name, setName] = useState('')
  const [obligatorio, setObligatorio] = useState('opcional')
  const [options, setOpciones] = useState<Array<{ name: string; price: string }>>([
    { name: '', price: '' },
  ])

  const create = useMutation({
    mutationFn: () =>
      catalog.createModifierGroup({
        name: name,
        // "Pick one" is (1,1); "as many as they like" is (0, as many as there are).
        min_choices: obligatorio === 'uno' ? 1 : 0,
        max_choices: obligatorio === 'uno' ? 1 : Math.max(1, options.length),
        modifiers: options
          .filter((o) => o.name.trim() !== '')
          .map((o) => ({
            name: o.name,
            // Can be NEGATIVE: "no cheese" sometimes takes money off.
            price_delta_cents: parseAmount(o.price) ?? 0,
          })),
      }),
    onSuccess: () => {
      setName('')
      setOpciones([{ name: '', price: '' }])
      void queryClient.invalidateQueries({ queryKey: ['modifier-groups'] })
    },
  })

  const remove = useMutation({
    mutationFn: (id: string) => catalog.deleteModifierGroup(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['modifier-groups'] }),
  })

  function onSubmit(event: FormEvent): void {
    event.preventDefault()
    if (name.trim() !== '') create.mutate()
  }

  return (
    <Page width="board" className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Agregados</h1>

      <Card className="flex flex-col gap-3 p-4">
        <form onSubmit={onSubmit} className="flex flex-col gap-3">
          <Field label="La pregunta" hint="Lo que se le pregunta a quien pide.">
            {({ id }) => (
              <Input
                id={id}
                value={name}
                placeholder="Término de la carne, Extras…"
                onChange={(e) => setName(e.target.value)}
              />
            )}
          </Field>

          <Field label="Cómo se responde">
            {({ id }) => (
              <Select id={id} value={obligatorio} onChange={(e) => setObligatorio(e.target.value)}>
                <option value="opcional">Puede elegir los que quiera, o ninguno</option>
                <option value="uno">Tiene que elegir uno</option>
              </Select>
            )}
          </Field>

          <fieldset className="flex flex-col gap-2">
            <legend className="text-sm font-medium text-[var(--text-strong)]">Las opciones</legend>

            {options.map((option, i) => (
              <div key={i} className="flex gap-2">
                <Input
                  aria-label={`Opción ${i + 1}`}
                  placeholder="Sin cebolla"
                  value={option.name}
                  onChange={(e) =>
                    setOpciones((prev) =>
                      prev.map((o, j) => (i === j ? { ...o, name: e.target.value } : o)),
                    )
                  }
                />
                <Input
                  aria-label={`Precio de la opción ${i + 1}`}
                  inputMode="decimal"
                  placeholder="0,00"
                  className="w-28"
                  value={option.price}
                  onChange={(e) =>
                    setOpciones((prev) =>
                      prev.map((o, j) => (i === j ? { ...o, price: e.target.value } : o)),
                    )
                  }
                />
              </div>
            ))}

            <Button
              variant="secondary"
              size="sm"
              onClick={() => setOpciones((prev) => [...prev, { name: '', price: '' }])}
            >
              Otra opción
            </Button>
          </fieldset>

          <Button type="submit" block disabled={create.isPending}>
            Guardar el grupo
          </Button>
        </form>
      </Card>

      {groups.isLoading && <Spinner />}

      {groups.data?.length === 0 && (
        <EmptyState
          title="Sin agregados"
          description="Sirven para «sin cebolla» o «extra queso». La cocina los lee en la comanda."
        />
      )}

      <ul className="flex flex-col gap-2">
        {groups.data?.map((group) => (
          <li key={group.id}>
            <Card className="p-3">
              <div className="flex items-center gap-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-[var(--text-strong)]">{group.name}</p>
                  {/* The SERVER explains the rule, so the portal, the till and the bot
                      say exactly the same thing. */}
                  <p className="text-xs text-[var(--text-muted)]">{group.rule}</p>
                </div>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => remove.mutate(group.id)}
                  aria-label={`Borrar ${group.name}`}
                >
                  Borrar
                </Button>
              </div>

              <ul className="mt-2 flex flex-wrap gap-1.5">
                {group.modifiers.map((modifier) => (
                  <li key={modifier.id}>
                    <Badge tone={modifier.priceDeltaCents < 0 ? 'ok' : 'neutral'}>
                      {modifier.name}
                      {modifier.priceDeltaCents !== 0 && ` ${formatUsd(modifier.priceDeltaCents)}`}
                    </Badge>
                  </li>
                ))}
              </ul>
            </Card>
          </li>
        ))}
      </ul>
    </Page>
  )
}
