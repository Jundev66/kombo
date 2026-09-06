import { ApiError } from '@kombo/api-client'
import { Button, Field, Input, Money, Sheet, formatUsd, parseAmount, toAmountInput } from '@kombo/ui'
import { useState } from 'react'
import { NEEDS_REFERENCE, PAYMENT_METHODS, paymentLabel, type SalePayment } from './api'

/**
 * Taking payment.
 *
 * Real mixed payment, which is how it works here: three dollars in cash and the
 * rest in bolívares by mobile transfer. Payments are added one at a time and the
 * screen always says how much is left.
 */
export function PaymentSheet({
  totalCents,
  rate,
  onConfirm,
  onClose,
}: {
  totalCents: number
  rate: number | null
  onConfirm: (payments: SalePayment[]) => Promise<void>
  onClose: () => void
}) {
  const [payments, setPayments] = useState<SalePayment[]>([])
  const [method, setMethod] = useState<string>('cash_usd')
  const [amount, setAmount] = useState<string>(toAmountInput(totalCents))
  const [reference, setReference] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [charging, setCharging] = useState(false)

  const paidCents = payments.reduce((sum, p) => sum + p.amount_cents, 0)
  const outstandingCents = totalCents - paidCents

  // What is over is CHANGE, not an error: customers pay a 7 bill with a 10
  // every day.
  const changeCents = paidCents > totalCents ? paidCents - totalCents : 0

  const needsReference = NEEDS_REFERENCE.includes(method)

  function addPayment(): void {
    const cents = parseAmount(amount)

    if (cents === null || cents <= 0) {
      setError('¿Cuánto?')
      return
    }

    if (needsReference && reference.trim() === '') {
      setError('Falta la referencia.')
      return
    }

    setPayments((current) => [
      ...current,
      { method, amount_cents: cents, reference: reference.trim() || null },
    ])

    setError(null)
    setReference('')
    // What is left, pre-filled: the next payment is almost always the remainder.
    setAmount(toAmountInput(Math.max(0, outstandingCents - cents)))
  }

  async function charge(): Promise<void> {
    setCharging(true)
    setError(null)

    try {
      // If nobody added payments by hand, the whole amount is charged with the
      // chosen method: the commonest case is "cash, exact", and it should not cost
      // two extra taps.
      await onConfirm(
        payments.length > 0
          ? payments
          : [{ method, amount_cents: totalCents, reference: reference.trim() || null }],
      )
    } catch (failure) {
      setError(
        failure instanceof ApiError ? failure.message : 'No se pudo cobrar. Inténtalo otra vez.',
      )
      setCharging(false)
    }
  }

  const covered = paidCents >= totalCents || payments.length === 0

  return (
    <Sheet
      title="Cobrar"
      onClose={onClose}
      footer={
        <Button size="touch" block disabled={!covered || charging} onClick={() => void charge()}>
          {charging ? 'Cobrando…' : `Cobrar ${formatUsd(totalCents)}`}
        </Button>
      }
    >
      <div className="flex flex-col gap-5">
        <div className="flex items-end justify-between">
          <span className="text-[var(--text-muted)]">Total</span>
          <Money cents={totalCents} rate={rate} scale="xl" />
        </div>

        {payments.length > 0 && (
          <ul className="flex flex-col gap-1">
            {payments.map((payment, index) => (
              <li
                key={index}
                className="flex items-center justify-between rounded-[var(--radius-md)] bg-[var(--surface-sunken)] px-3 py-2"
              >
                <span className="text-sm text-[var(--text-strong)]">
                  {paymentLabel(payment.method)}
                  {payment.reference != null && (
                    <span className="text-[var(--text-muted)]"> · {payment.reference}</span>
                  )}
                </span>

                <span className="flex items-center gap-3">
                  <span className="tabular text-sm">{formatUsd(payment.amount_cents)}</span>

                  <button
                    type="button"
                    aria-label={`Quitar ${paymentLabel(payment.method)}`}
                    onClick={() => setPayments((c) => c.filter((_, i) => i !== index))}
                    className="text-[var(--text-muted)]"
                  >
                    ×
                  </button>
                </span>
              </li>
            ))}
          </ul>
        )}

        {payments.length > 0 && (
          <p className="flex items-center justify-between font-medium">
            <span className="text-[var(--text-muted)]">
              {changeCents > 0 ? 'Vuelto' : 'Falta'}
            </span>
            <span
              className={`tabular text-lg ${changeCents > 0 ? 'text-ok-700' : 'text-[var(--text-strong)]'}`}
            >
              {formatUsd(changeCents > 0 ? changeCents : outstandingCents)}
            </span>
          </p>
        )}

        <div className="grid grid-cols-3 gap-2">
          {PAYMENT_METHODS.map((option) => (
            <button
              key={option.value}
              type="button"
              aria-pressed={method === option.value}
              onClick={() => setMethod(option.value)}
              className={`min-h-touch rounded-[var(--radius-md)] border px-2 text-sm font-medium ${
                method === option.value
                  ? 'border-accent-500 bg-accent-50 text-accent-700'
                  : 'border-[var(--surface-border)] text-[var(--text-default)]'
              }`}
            >
              {option.label}
            </button>
          ))}
        </div>

        <div className="flex gap-3">
          <div className="flex-1">
            <Field label="Monto">
              {({ id }) => (
                <Input
                  id={id}
                  inputMode="decimal"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                />
              )}
            </Field>
          </div>

          {needsReference && (
            <div className="flex-1">
              <Field label="Referencia">
                {({ id }) => (
                  <Input
                    id={id}
                    inputMode="numeric"
                    value={reference}
                    onChange={(e) => setReference(e.target.value)}
                  />
                )}
              </Field>
            </div>
          )}
        </div>

        {error != null && (
          <p role="alert" className="text-sm font-medium text-bad-500">
            {error}
          </p>
        )}

        <Button variant="secondary" block onClick={addPayment}>
          Agregar este pago
        </Button>
      </div>
    </Sheet>
  )
}
