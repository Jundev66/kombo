#!/usr/bin/env bash
#
# The work log: creating a new entry and keeping the index up to date.
#
#   ./scripts/trabajos.sh nuevo "Paginar la carta"   creates KMB-00NN-…/
#   ./scripts/trabajos.sh indice                     regenerates the index
#   ./scripts/trabajos.sh verificar                  what `make check` runs
#
# In bash rather than PHP on purpose: this runs on the HOST, before touching
# anything, and often before the containers are up. A helper for starting work
# that requires the environment to be running stops being used.
#
# Each entry's front matter is deliberately simple YAML — one key per line,
# no nesting — so it can be read with `sed` from here without pulling a YAML
# parser into a four-function script.

set -euo pipefail
cd "$(dirname "$0")/.."

TRABAJOS="docs/trabajos"
INDICE="$TRABAJOS/README.md"

# ── Utilities ───────────────────────────────────────────────────────────────

# The value of a front-matter key. Empty when absent.
#
# Cut at the `#`: the template carries each field's options as a trailing
# comment, and without this the index swallowed the whole comment — pipes
# included, which split a markdown table into invented columns.
campo() {
    sed -n "s/^$2: *//p" "$1/README.md" \
        | head -1 \
        | sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//'
}

# "Añadir la Carta" → "anadir-la-carta". No accents or ñ: these are directory
# names that end up in a URL and in somebody else's `git mv`.
#
# Three things that each cost an attempt:
#
# - `sed -E`, not plain `sed`: macOS's does not understand `\+` in a basic
#   expression, and left the spaces INSIDE the directory name without complaint.
# - `s///g` for the accents, not `y///`: `y` works byte by byte and splits
#   UTF-8 characters in two, which here is most of the interesting ones.
# - No `iconv ... || echo`: on macOS `iconv --translit` writes what it managed
#   AND exits with an error, so the `||` concatenated both outputs and the name
#   came out duplicated.
slugify() {
    printf '%s' "$1" \
        | sed 's/[áÁ]/a/g; s/[éÉ]/e/g; s/[íÍ]/i/g; s/[óÓ]/o/g; s/[úÚüÜ]/u/g; s/[ñÑ]/n/g' \
        | tr '[:upper:]' '[:lower:]' \
        | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//' \
        | cut -c1-50
}

siguiente_codigo() {
    local ultimo
    ultimo=$(find "$TRABAJOS" -maxdepth 1 -type d -name 'KMB-*' \
        | sed 's|.*/KMB-\([0-9]*\).*|\1|' | sort -n | tail -1)

    printf 'KMB-%04d' "$((10#${ultimo:-0} + 1))"
}

# ── Create ──────────────────────────────────────────────────────────────────

nuevo() {
    local titulo="${1:-}"

    if [[ -z "$titulo" ]]; then
        echo "Falta el título: ./scripts/trabajos.sh nuevo \"Paginar la carta\"" >&2
        exit 1
    fi

    local codigo carpeta
    codigo=$(siguiente_codigo)
    carpeta="$TRABAJOS/$codigo-$(slugify "$titulo")"

    mkdir -p "$carpeta"

    sed \
        -e "s/^codigo: .*/codigo: $codigo/" \
        -e "s/^titulo: .*/titulo: $titulo/" \
        -e "s/^fecha: .*/fecha: $(date +%F)/" \
        -e "s/^# KMB-XXXX · <título>/# $codigo · $titulo/" \
        "$TRABAJOS/PLANTILLA.md" > "$carpeta/README.md"

    indice

    echo "$carpeta/README.md"
}

# ── Index ───────────────────────────────────────────────────────────────────

tabla() {
    local carpeta codigo titulo tipo estado
    printf '| Código | Trabajo | Tipo | Estado |\n'
    printf '|---|---|---|---|\n'

    # By code, which is chronological: the log reads top to bottom the way the
    # system was built.
    while IFS= read -r carpeta; do
        codigo=$(campo "$carpeta" codigo)
        titulo=$(campo "$carpeta" titulo)
        tipo=$(campo "$carpeta" tipo)
        estado=$(campo "$carpeta" estado)

        printf '| [%s](%s/) | %s | %s | %s |\n' \
            "$codigo" "$(basename "$carpeta")" "$titulo" "$tipo" "$estado"
    done < <(find "$TRABAJOS" -maxdepth 1 -type d -name 'KMB-*' | sort)
}

indice() {
    local tmp
    tmp=$(mktemp)

    # The header is written by hand and the table is generated. The separator is
    # what allows both in one file: above it a person edits, below it the script
    # overwrites.
    sed -n '1,/^<!-- La tabla la genera/p' "$INDICE" > "$tmp" 2>/dev/null \
        || cabecera_indice > "$tmp"

    # If the file had no marker yet, start from scratch.
    grep -q '^<!-- La tabla la genera' "$tmp" || cabecera_indice > "$tmp"

    {
        echo ""
        tabla
    } >> "$tmp"

    mv "$tmp" "$INDICE"
}

cabecera_indice() {
    cat <<'CABECERA'
# El registro de trabajos

Un trabajo por carpeta, con su código. **Aquí está el porqué de cómo están las
cosas** — lo que no se deduce leyendo el diff: qué se descartó, qué falló al
hacerlo, y qué se verificó.

Antes de tocar algo, busca si ya se decidió:

```bash
grep -rl "paginación" docs/trabajos/     # por tema
grep -rn "KMB-0009" .                    # dónde se cita este trabajo
make trabajos                            # regenerar este índice
make trabajo t="Lo que voy a hacer"      # abrir el siguiente
```

El código se cita **en el comentario que lo necesita** y en el mensaje del
commit. Un `// Por qué esto no se pagina: KMB-0009` en el sitio exacto vale más
que un documento que nadie sabe que existe.

Lo de antes de que existiera el registro está en los `AGENTS.md`: son la otra
mitad de la memoria de este proyecto, y no se van a reescribir aquí.

<!-- La tabla la genera `make trabajos`. No la edites a mano. -->
CABECERA
}

# ── Guard ───────────────────────────────────────────────────────────────────
#
# It lives here rather than in `api/tests/Architecture/` — where the other
# guards are — for a material reason: the API container only mounts `api/`, so
# `docs/trabajos/` does not exist from inside. Nor should it: the log belongs
# to the whole repository, not to the backend.
#
# It does not check CONTENT. Whether an entry is well written is not something
# a script can say, and pretending otherwise would license writing them badly.

fallo() {
    echo "✗ $1" >&2
    ERRORES=$((ERRORES + 1))
}

comprobar() {
    ERRORES=0

    local carpeta nombre codigo clave valor
    local codigos_vistos=""

    while IFS= read -r carpeta; do
        nombre=$(basename "$carpeta")

        if [[ ! -f "$carpeta/README.md" ]]; then
            fallo "«${nombre}» no tiene README.md."
            continue
        fi

        # The complete front matter: the index is generated from it, and an empty
        # field shows as a gap in the table with no way to tell whether the data or
        # the work is missing.
        for clave in codigo titulo tipo estado fecha; do
            valor=$(campo "$carpeta" "$clave")
            [[ -n "$valor" ]] || fallo "A «${nombre}» le falta «${clave}» en la cabecera."
        done

        codigo=$(campo "$carpeta" codigo)

        # A code pointing at two places identifies nothing, and they are cited from
        # code comments and commit messages: references that have to resolve.
        case " $codigos_vistos " in
            *" $codigo "*) fallo "El código $codigo está repetido." ;;
            *) codigos_vistos="$codigos_vistos $codigo" ;;
        esac

        # If the front matter and the directory diverge, searching by one finds the
        # document and searching by the other finds the directory. Two ways of
        # searching for one thing giving different answers is worse than one failure.
        [[ "$nombre" == "$codigo"* ]] \
            || fallo "«${nombre}» dice ser $codigo en su cabecera."

        # A `estado: terminado` where the rest say `hecho` breaks nothing and makes
        # filtering the index useless, which is all it is good for.
        case "$(campo "$carpeta" tipo)" in
            funcionalidad|arreglo|decision|plan) ;;
            *) fallo "«${nombre}» tiene un tipo que no está en la lista." ;;
        esac

        case "$(campo "$carpeta" estado)" in
            propuesto|en-curso|hecho|descartado) ;;
            *) fallo "«${nombre}» tiene un estado que no está en la lista." ;;
        esac
    done < <(find "$TRABAJOS" -maxdepth 1 -type d -name 'KMB-*' | sort)

    # An index missing the latest entry is WORSE than no index: whoever looks for
    # whether something was already decided goes there, does not find it, and
    # concludes nobody decided it.
    local antes despues
    antes=$(cat "$INDICE")
    indice
    despues=$(cat "$INDICE")

    if [[ "$antes" != "$despues" ]]; then
        # It is left regenerated: if that was the only problem, `make check` passes
        # again without anyone having to remember the command.
        fallo "El índice no estaba al día. Ya lo regeneré — revísalo y añádelo al commit."
    fi

    if [[ $ERRORES -gt 0 ]]; then
        echo "" >&2
        echo "$ERRORES problema(s) en docs/trabajos/." >&2
        exit 1
    fi

    echo "✓ Registro de trabajos: $(find "$TRABAJOS" -maxdepth 1 -type d -name 'KMB-*' | wc -l | tr -d ' ') trabajos, índice al día."
}

# ── Entry point ─────────────────────────────────────────────────────────────

case "${1:-}" in
    nuevo)      nuevo "${2:-}" ;;
    indice)     indice; echo "$INDICE" ;;
    verificar)  comprobar ;;
    *)
        echo "Uso: $0 {nuevo \"Título\" | indice | verificar}" >&2
        exit 1
        ;;
esac
