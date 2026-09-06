import { ApiError } from '@kombo/api-client'
import type { Staff } from '@kombo/shell'
import { Button, Field, Input, Select, Sheet, formatBs, formatUsd } from '@kombo/ui'
import { useEffect, useState } from 'react'
import { counter, paymentLabel, type DeliveryNote } from './api'

/**
 * The delivery note, exactly as handed to the customer.
 *
 * The stored `snapshot` is painted rather than the live tables: reprinting a
 * note from three months ago has to give the same paper even if the product has
 * been renamed. Whoever is complaining has the original in their hand.
 *
 * The document says what it is: "NOTA DE ENTREGA" above and "No es una factura"
 * below, both coming from the server inside the document itself. There is no
 * hidden option that removes them.
 */
export function NoteSheet({
  note: initial,
  needsPin,
  onDone,
}: {
  note: DeliveryNote
  /** Whether whoever is at the till can only REQUEST the void. */
  needsPin: boolean
  onDone: () => void
}) {
  const [note, setNote] = useState(initial)
  const [voiding, setVoiding] = useState(false)

  return (
    <Sheet
      title={note.isVoided ? `Nota ${note.reference} · ANULADA` : `Nota ${note.reference}`}
      onClose={onDone}
      footer={
        <div className="flex gap-2">
          <Button variant="secondary" size="touch" className="flex-1" onClick={() => window.print()}>
            Imprimir
          </Button>

          <Button size="touch" className="flex-1" onClick={onDone}>
            Nueva venta
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-5">
        <article
          id="nota-para-imprimir"
          className="rounded-[var(--radius-md)] border border-[var(--surface-border)] p-4 font-mono text-sm text-[var(--text-strong)]"
        >
          <header className="mb-3 text-center">
            <p className="font-bold tracking-wide">{note.snapshot.title}</p>
            {/* Below the title, unadorned: it is what stops this paper being
                mistaken for an invoice. */}
            <p className="text-[var(--text-muted)]">{note.snapshot.disclaimer}</p>
            <p className="mt-2 tabular">{note.reference}</p>
            <p className="tabular text-[var(--text-muted)]">
              Pedido #{note.snapshot.orderNumber}
            </p>
          </header>

          {note.isVoided && (
            <p role="status" className="mb-3 text-center font-bold text-bad-500">
              ANULADA · {note.voidReason}
            </p>
          )}

          <ul className="flex flex-col gap-1 border-y border-dashed border-[var(--surface-border)] py-3">
            {note.snapshot.lines.map((line, index) => (
              <li key={index}>
                <span className="flex justify-between gap-3">
                  <span>
                    <span className="tabular">{line.quantity}×</span> {line.name}
                  </span>
                  <span className="tabular">{formatUsd(line.lineTotalCents)}</span>
                </span>

                {line.modifiers.map((modifier, i) => (
                  <span key={i} className="block pl-5 text-xs text-[var(--text-muted)]">
                    + {modifier.name}
                  </span>
                ))}
              </li>
            ))}
          </ul>

          <p className="mt-3 flex justify-between text-base font-bold">
            <span>TOTAL</span>
            <span className="tabular">{formatUsd(note.snapshot.totalCents)}</span>
          </p>

          {note.snapshot.exchangeRate != null && note.snapshot.exchangeRate > 0 && (
            <p className="flex justify-between text-[var(--text-muted)]">
              <span>En bolívares</span>
              {/* At the rate FROZEN when payment was taken: this note's bolívar
                  amount cannot change tomorrow. */}
              <span className="tabular">
                {formatBs(note.snapshot.totalCents, note.snapshot.exchangeRate)}
              </span>
            </p>
          )}

          <ul className="mt-3 border-t border-dashed border-[var(--surface-border)] pt-3 text-xs text-[var(--text-muted)]">
            {note.snapshot.payments.map((payment, index) => (
              <li key={index} className="flex justify-between">
                <span>
                  {paymentLabel(payment.method)}
                  {payment.reference != null && ` · ${payment.reference}`}
                </span>
                <span className="tabular">{formatUsd(payment.amountCents)}</span>
              </li>
            ))}
          </ul>

          {note.issuedByName != null && (
            <p className="mt-3 text-center text-xs text-[var(--text-muted)]">
              Le atendió {note.issuedByName}
            </p>
          )}
        </article>

        {!note.isVoided &&
          (voiding ? (
            <VoidForm
              orderId={note.orderId}
              needsPin={needsPin}
              onCancel={() => setVoiding(false)}
              onVoided={(voidedNote) => {
                setNote(voidedNote)
                setVoiding(false)
              }}
            />
          ) : (
            <Button variant="ghost" block onClick={() => setVoiding(true)}>
              Anular esta venta
            </Button>
          ))}

        {note.printedCount > 1 && (
          <p className="text-center text-xs text-[var(--text-muted)]">
            Reimpresa {note.printedCount} veces.
          </p>
        )}
      </div>
    </Sheet>
  )
}

/**
 * Voiding: a required reason and, when needed, a manager's PIN.
 *
 * The PIN is asked for BEFORE trying when we already know it will be needed —
 * `/me` says which actions need it — rather than letting the server reject the
 * first attempt and asking with a customer waiting.
 */
function VoidForm({
  orderId,
  needsPin,
  onVoided,
  onCancel,
}: {
  orderId: string
  needsPin: boolean
  onVoided: (note: DeliveryNote) => void
  onCancel: () => void
}) {
  const [reason, setReason] = useState('')
  const [pin, setPin] = useState('')
  const [userId, setUserId] = useState('')
  const [staff, setStaff] = useState<Staff[]>([])
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)

  useEffect(() => {
    if (!needsPin) return

    void counter.staff().then(setStaff).catch(() => setStaff([]))
  }, [needsPin])

  async function submit(): Promise<void> {
    setSending(true)
    setError(null)

    try {
      const { note } = await counter.voidSale(
        orderId,
        reason,
        needsPin ? { userId, pin } : undefined,
      )

      if (note != null) {
        onVoided(note)
      }
    } catch (failure) {
      setError(
        failure instanceof ApiError ? failure.message : 'No se pudo anular. Inténtalo otra vez.',
      )
      setSending(false)
    }
  }

  return (
    <div className="flex flex-col gap-3 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-4">
      <Field label="¿Por qué se anula?" required error={error ?? undefined}>
        {({ id, invalid }) => (
          <Input
            id={id}
            value={reason}
            invalid={invalid}
            onChange={(e) => setReason(e.target.value)}
          />
        )}
      </Field>

      {needsPin && (
        <>
          <Field label="Autoriza" hint="Quién lo autoriza, no quién lo pide." required>
            {({ id }) => (
              <Select id={id} value={userId} onChange={(e) => setUserId(e.target.value)}>
                <option value="">Elige quién autoriza</option>
                {staff.map((person) => (
                  <option key={person.id} value={person.id}>
                    {person.name}
                    {person.roleName != null && ` · ${person.roleName}`}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          <Field label="PIN" required>
            {({ id }) => (
              <Input
                id={id}
                type="password"
                inputMode="numeric"
                maxLength={4}
                value={pin}
                onChange={(e) => setPin(e.target.value)}
              />
            )}
          </Field>
        </>
      )}

      <div className="flex gap-2">
        <Button variant="ghost" className="flex-1" onClick={onCancel}>
          Mejor no
        </Button>

        <Button
          variant="danger"
          className="flex-1"
          disabled={reason.trim().length < 3 || sending}
          onClick={() => void submit()}
        >
          {sending ? 'Anulando…' : 'Anular'}
        </Button>
      </div>
    </div>
  )
}
