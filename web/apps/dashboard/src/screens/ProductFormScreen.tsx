import { ApiError, can } from '@kombo/api-client'
import { useSession } from '@kombo/shell'
import { Button, Field, Input, Select, Textarea, Toggle, parseAmount, toAmountInput, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router'
import { catalog } from '../api/catalog'

/**
 * Creating and editing a product, built for the phone.
 *
 * One scroll, large fields, and the price at the very top because it is what
 * people come to change most. No tabs and no accordions: on a six-inch screen,
 * hiding half the form guarantees somebody saves without seeing what was
 * missing.
 */
export function ProductFormScreen() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { capabilities } = useSession()

  const isNew = id === undefined
  const canChangePrice = capabilities != null && can(capabilities, 'catalog.change_price')

  const categories = useQuery({ queryKey: ['categories'], queryFn: catalog.categories })
  const groups = useQuery({ queryKey: ['modifier-groups'], queryFn: catalog.modifierGroups })
  const existing = useQuery({
    queryKey: ['product', id],
    queryFn: () => catalog.product(id as string),
    enabled: !isNew,
  })

  const [name, setName] = useState('')
  const [price, setPrice] = useState('')
  const [descripcion, setDescripcion] = useState('')
  const [fotoUrl, setFotoUrl] = useState('')
  const [subiendo, setSubiendo] = useState(false)
  const [errorFoto, setErrorFoto] = useState<string | null>(null)
  const [categoriaId, setCategoriaId] = useState('')
  const [minutes, setMinutos] = useState('')
  const [llevaCuenta, setLlevaCuenta] = useState(false)
  const [left, setQuedan] = useState('')
  const [onMenu, setOnMenu] = useState(true)
  const [gruposElegidos, setGruposElegidos] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const p = existing.data
    if (p == null) return

    setName(p.name)
    setPrice(toAmountInput(p.priceCents))
    setDescripcion(p.description ?? '')
    setFotoUrl(p.photoUrl ?? '')
    setCategoriaId(p.categoryId ?? '')
    setMinutos(p.prepMinutes?.toString() ?? '')
    setLlevaCuenta(p.tracksStock)
    setQuedan(p.stockQty?.toString() ?? '')
    setOnMenu(p.isActive)
    setGruposElegidos(p.modifierGroupIds ?? [])
  }, [existing.data])

  const save = useMutation({
    mutationFn: async () => {
      const priceCents = parseAmount(price)
      if (priceCents === null || priceCents < 0) {
        throw new Error('Ese precio no se entiende. Escríbelo como 3,50.')
      }

      const common = {
        name,
        category_id: categoriaId === '' ? null : categoriaId,
        description: descripcion === '' ? null : descripcion,
        photo_url: fotoUrl === '' ? null : fotoUrl,
        prep_minutes: minutes === '' ? null : Number(minutes),
        track_stock: llevaCuenta,
        stock_qty: llevaCuenta ? Number(left || 0) : null,
        modifier_group_ids: gruposElegidos,
      }

      if (isNew) {
        return catalog.createProduct({ ...common, price_cents: priceCents })
      }

      await catalog.updateProduct(id as string, { ...common, is_active: onMenu })

      // The price goes through its own call, and only if this person can change
      // it. That is what makes the separate permission real: for whoever lacks it
      // the field is locked and this call is not made.
      if (canChangePrice && priceCents !== existing.data?.priceCents) {
        await catalog.changePrice(id as string, priceCents)
      }

      return null
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['products'] })
      void navigate('/carta')
    },
    onError: (caught: unknown) => {
      setError(
        caught instanceof ApiError
          ? messageFrom(caught)
          : caught instanceof Error
            ? caught.message
            : 'No se pudo guardar.',
      )
    },
  })

  async function uploadPhoto(file: File): Promise<void> {
    if (id === undefined) return

    setSubiendo(true)
    setErrorFoto(null)

    try {
      setFotoUrl(await catalog.uploadPhoto(id, file))
    } catch (failure) {
      setErrorFoto(failure instanceof Error ? failure.message : 'No se pudo subir la foto.')
    } finally {
      setSubiendo(false)
    }
  }

  async function removePhoto(): Promise<void> {
    if (id === undefined) return

    await catalog.removePhoto(id)
    setFotoUrl('')
  }

  function onSubmit(event: FormEvent): void {
    event.preventDefault()
    setError(null)
    save.mutate()
  }

  return (
    <Page width="reading">
      <form onSubmit={onSubmit} className="flex flex-col gap-4">
        <h1 className="text-xl font-bold text-[var(--text-strong)]">
          {isNew ? 'Nuevo producto' : name}
        </h1>

        <Field label="Nombre" required error={error ?? undefined}>
          {({ id: fieldId, invalid }) => (
            <Input
              id={fieldId}
              value={name}
              invalid={invalid}
              autoFocus={isNew}
              onChange={(e) => setName(e.target.value)}
            />
          )}
        </Field>

        <Field
          label="Precio en dólares"
          required
          hint={canChangePrice ? 'Escríbelo como 3,50' : 'No tienes permiso para cambiar precios.'}
        >
          {({ id: fieldId }) => (
            <Input
              id={fieldId}
              inputMode="decimal"
              value={price}
              disabled={!isNew && !canChangePrice}
              onChange={(e) => setPrice(e.target.value)}
            />
          )}
        </Field>

        <Field label="Categoría">
          {({ id: fieldId }) => (
            <Select id={fieldId} value={categoriaId} onChange={(e) => setCategoriaId(e.target.value)}>
              <option value="">Sin categoría</option>
              {categories.data?.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field label="Descripción" hint="Lo que verá el cliente en el portal.">
          {({ id: fieldId }) => (
            <Textarea
              id={fieldId}
              value={descripcion}
              onChange={(e) => setDescripcion(e.target.value)}
            />
          )}
        </Field>

        {/* The photo is what sells on the portal, so it is uploaded here rather
            than pasted as an address. Only when editing: the product has to
            exist before a file can be attached to it. */}
        {id !== undefined ? (
          <Field
            label="Foto"
            hint="Se ve en el portal y en la caja. Cámbiala subiendo otra."
            error={errorFoto ?? undefined}
          >
            {({ id: fieldId }) => (
              <div className="flex items-center gap-3">
                {fotoUrl !== '' && (
                  <img
                    src={fotoUrl}
                    alt=""
                    className="size-20 rounded-[var(--radius-md)] object-cover"
                  />
                )}

                <input
                  id={fieldId}
                  type="file"
                  accept="image/*"
                  disabled={subiendo}
                  onChange={(e) => {
                    const file = e.target.files?.[0]
                    if (file !== undefined) void uploadPhoto(file)
                  }}
                  className="flex-1 text-sm file:mr-3 file:min-h-11 file:rounded-[var(--radius-md)] file:border-0 file:bg-[var(--surface-sunken)] file:px-4 file:font-medium"
                />

                {fotoUrl !== '' && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => void removePhoto()}
                  >
                    Quitar
                  </Button>
                )}
              </div>
            )}
          </Field>
        ) : (
          <p className="text-sm text-[var(--text-muted)]">
            La foto se sube al guardar el producto, en la pantalla de edición.
          </p>
        )}

        <Field
          label="Minutos que tarda"
          hint="De aquí sale el aviso de «va tarde» en la pantalla de cocina. Déjalo vacío si no se prepara."
        >
          {({ id: fieldId }) => (
            <Input
              id={fieldId}
              inputMode="numeric"
              value={minutes}
              onChange={(e) => setMinutos(e.target.value)}
            />
          )}
        </Field>

        <Toggle
          checked={llevaCuenta}
          onChange={setLlevaCuenta}
          label="Llevar la cuenta de cuántos quedan"
        />

        {llevaCuenta && (
          <Field label="Cuántos quedan">
            {({ id: fieldId }) => (
              <Input
                id={fieldId}
                inputMode="numeric"
                value={left}
                onChange={(e) => setQuedan(e.target.value)}
              />
            )}
          </Field>
        )}

        {!isNew && (
          <Toggle checked={onMenu} onChange={setOnMenu} label="Está en la carta" />
        )}

        {(groups.data?.length ?? 0) > 0 && (
          <fieldset className="flex flex-col gap-2">
            <legend className="text-sm font-medium text-[var(--text-strong)]">Agregados</legend>

            {groups.data?.map((group) => (
              <label key={group.id} className="flex min-h-11 items-center gap-3">
                <input
                  type="checkbox"
                  checked={gruposElegidos.includes(group.id)}
                  onChange={(e) =>
                    setGruposElegidos((prev) =>
                      e.target.checked ? [...prev, group.id] : prev.filter((g) => g !== group.id),
                    )
                  }
                  className="size-5"
                />
                <span className="text-sm">
                  {group.name}{' '}
                  <span className="text-[var(--text-muted)]">— {group.rule}</span>
                </span>
              </label>
            ))}
          </fieldset>
        )}

        <Button type="submit" size="touch" block disabled={save.isPending}>
          {save.isPending ? 'Guardando…' : 'Guardar'}
        </Button>
        </form>
    </Page>
  )
}

function messageFrom(error: ApiError): string {
  const body = error.body

  if (typeof body === 'object' && body !== null && 'errors' in body) {
    const errors = (body as { errors: Record<string, string[]> }).errors
    const first = Object.values(errors)[0]?.[0]
    if (first) return first
  }

  return error.message
}
