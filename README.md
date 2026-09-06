# Kombo

**Multi-tenant** food ordering system. A single deployment serves every
customer: each business enters through its own subdomain and sees only its own
data, guaranteed by PostgreSQL Row Level Security.

An order arrives through one of three doors — the customer **portal**, a
WhatsApp or Telegram **bot**, or the **counter** till — and all three end up on
the same **kitchen screen**. When the order is ready it leaves the kitchen and
the customer hears about it wherever they ordered.

**Laravel 13 · PHP 8.5 · PostgreSQL 18 · Redis 8 · React 19 · Vite 8 ·
TypeScript 7 · Tailwind 4 · Playwright**

## Getting started

```bash
make up      # bring everything up
make setup   # first run: app key, migrations, test database
make demo    # seed the demo businesses
```

```
http://elsazon.localhost:8010/            customer portal
http://elsazon.localhost:8010/dashboard/   owner dashboard
http://elsazon.localhost:8010/pos/         counter till
http://elsazon.localhost:8010/kds/         kitchen screen
http://admin.localhost:8010/               platform administration
```

## Verifying

```bash
make check   # architecture, isolation, test suite, style, types and bundle budget
make e2e     # end-to-end tests through a real browser
```

## Putting this on a server

- **[`docs/despliegue.md`](docs/despliegue.md)** — from an empty VPS to a
  business taking orders: wildcard DNS, Cloudflare, `compose.prod.yml`, and the
  four checks to run once you are done.
- **[`docs/canales.md`](docs/canales.md)** — connecting each business's WhatsApp
  and Telegram: where every credential comes from, and what to do when the bot
  stops answering.
- **[`docs/respaldos.md`](docs/respaldos.md)** — what gets backed up, where, and
  above all **how to restore it**. Restore one on deployment day: a backup
  nobody has ever restored is not a backup.

## Understanding the system

The entry point is **[`AGENTS.md`](AGENTS.md)** — the map, the invariants, and
what must not be broken. It serves a new teammate and any AI that opens the
repository equally well. Every large directory has its own:
[`api/`](api/AGENTS.md), [`web/`](web/AGENTS.md), [`e2e/`](e2e/AGENTS.md).

`CLAUDE.md`, `GEMINI.md`, `.cursorrules` and `.github/copilot-instructions.md`
are three-line pointers back to it: each tool looks for its own name, and
**there is only one text**. If tomorrow you bring in a tool that reads a
different filename, you add the pointer — you do not copy the text.

And **[`docs/trabajos/`](docs/trabajos/README.md)** is the why behind how things
are: one job per directory under a `KMB-XXXX` code, recording what was
discarded, what went wrong while doing it, and how it was verified. The codes
are cited from the code itself, so a `// KMB-0009` at the exact spot leads to
the document that explains it.

```bash
make trabajo t="What I am about to do"   # open the next one
make trabajos                            # regenerate the index
```

Documentation under `docs/` and `AGENTS.md` is written in Spanish; the code and
its comments are in English.

## A note on fiscal documents

The till issues **delivery notes**, not invoices: a commercial document with its
own sequence, printed with `No es una factura` ("this is not an invoice") on it.
The system does not compute VAT as a fiscal debit, keeps no sales ledger, and
does not number documents from ranges assigned by the tax authority. A delivery
note does not replace an invoice and does not remove the business's tax
obligations; issuing invoices requires the means authorised by SENIAT, the
Venezuelan tax authority.

There is a `FiscalDocument` port with a null implementation, in case a business
gets certified later on. Until that adapter exists, there is no hidden switch
that turns a note into an invoice.
