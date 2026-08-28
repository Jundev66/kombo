import { Badge, Button, Card, EmptyState, Field, Input, Select, Spinner, formatUsd, parseAmount } from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { catalog } from '../api/catalog'

/**
 * Los agregados: «sin cebolla», «extra queso», «término de la carne».
 *
 * Un grupo es una PREGUNTA y sus opciones son las respuestas. Se crean juntos
 * en una sola pantalla porque un grupo sin opciones no sirve de nada, y dejarlo
 * a medias es dejar una pregunta sin respuestas en la carta.
 */
export function ModifiersScreen() {
  const queryClient = useQueryClient()
  const groups = useQuery({ queryKey: ['modifier-groups'], queryFn: catalog.modifierGroups })

  const [nombre, setNombre] = useState('')
  const [obligatorio, setObligatorio] = useState('opcional')
  const [opciones, setOpciones] = useState<Array<{ name: string; precio: string }>>([
    { name: '', precio: '' },
  ])

  const crear = useMutation({
    mutationFn: () =>
      catalog.createModifierGroup({
        name: nombre,
        // «Elige uno» es (1,1); «los que quiera» es (0, tantos como haya).
        min_choices: obligatorio === 'uno' ? 1 : 0,
        max_choices: obligatorio === 'uno' ? 1 : Math.max(1, opciones.length),
        modifiers: opciones
          .filter((o) => o.name.trim() !== '')
          .map((o) => ({
            name: o.name,
            // Puede ser NEGATIVO: «sin queso» a veces descuenta.
            price_delta_cents: parseAmount(o.precio) ?? 0,
          })),
      }),
    onSuccess: () => {
      setNombre('')
      setOpciones([{ name: '', precio: '' }])
      void queryClient.invalidateQueries({ queryKey: ['modifier-groups'] })
    },
  })

  const borrar = useMutation({
    mutationFn: (id: string) => catalog.deleteModifierGroup(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['modifier-groups'] }),
  })

  function onSubmit(event: FormEvent): void {
    event.preventDefault()
    if (nombre.trim() !== '') crear.mutate()
  }

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Agregados</h1>

      <Card className="flex flex-col gap-3 p-4">
        <form onSubmit={onSubmit} className="flex flex-col gap-3">
          <Field label="La pregunta" hint="Lo que se le pregunta a quien pide.">
            {({ id }) => (
              <Input
                id={id}
                value={nombre}
                placeholder="Término de la carne, Extras…"
                onChange={(e) => setNombre(e.target.value)}
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

            {opciones.map((opcion, i) => (
              <div key={i} className="flex gap-2">
                <Input
                  aria-label={`Opción ${i + 1}`}
                  placeholder="Sin cebolla"
                  value={opcion.name}
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
                  value={opcion.precio}
                  onChange={(e) =>
                    setOpciones((prev) =>
                      prev.map((o, j) => (i === j ? { ...o, precio: e.target.value } : o)),
                    )
                  }
                />
              </div>
            ))}

            <Button
              variant="secondary"
              size="sm"
              onClick={() => setOpciones((prev) => [...prev, { name: '', precio: '' }])}
            >
              Otra opción
            </Button>
          </fieldset>

          <Button type="submit" block disabled={crear.isPending}>
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
                  {/* La regla la explica el SERVIDOR, para que el portal, la
                      caja y el bot digan exactamente lo mismo. */}
                  <p className="text-xs text-[var(--text-muted)]">{group.rule}</p>
                </div>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => borrar.mutate(group.id)}
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
    </div>
  )
}
