#!/usr/bin/env bash
#
# El registro de trabajos: crear uno nuevo y mantener el índice.
#
#   ./scripts/trabajos.sh nuevo "Paginar la carta"   crea KMB-00NN-paginar-la-carta/
#   ./scripts/trabajos.sh indice                     regenera docs/trabajos/README.md
#   ./scripts/trabajos.sh verificar                  lo que corre `make check`
#
# En bash y no en PHP a propósito: esto se corre en el HOST, antes de tocar
# nada, y muchas veces antes de haber levantado los contenedores. Un ayudante
# para empezar a trabajar que exige tener el entorno arriba se deja de usar.
#
# La cabecera de cada trabajo es un YAML deliberadamente simple —una clave por
# línea, sin anidar— para poder leerla con `sed` desde aquí sin traer un parser
# de YAML a un script de cuatro funciones.

set -euo pipefail
cd "$(dirname "$0")/.."

TRABAJOS="docs/trabajos"
INDICE="$TRABAJOS/README.md"

# ── Utilidades ──────────────────────────────────────────────────────────────

# El valor de una clave de la cabecera. Vacío si no está.
#
# Se corta en el `#`: la plantilla lleva las opciones de cada campo como
# comentario al lado —`tipo: arreglo   # funcionalidad | arreglo | ...`— y sin
# esto el índice se llevaba el comentario entero. Con sus barras verticales
# dentro de una tabla de markdown, que la parten en columnas inventadas.
campo() {
    sed -n "s/^$2: *//p" "$1/README.md" \
        | head -1 \
        | sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//'
}

# «Añadir la Carta» → «anadir-la-carta». Sin acentos ni eñes: son nombres de
# carpeta que acaban en una URL y en un `git mv` de alguien con otro teclado.
#
# Tres cosas que costaron un intento cada una:
#
# - `sed -E`, no `sed` a secas: el de macOS no entiende `\+` en expresión
#   básica, y dejaba los espacios DENTRO del nombre de la carpeta sin quejarse.
# - `s///g` para los acentos, no `y///`: `y` trabaja por bytes y parte en dos
#   los caracteres UTF-8, que aquí son casi todos los interesantes.
# - Nada de `iconv ... || echo`: en macOS `iconv --translit` escribe lo que
#   pudo Y sale con error, así que el `||` pegaba las dos salidas y el nombre
#   salía duplicado.
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

# ── Crear ───────────────────────────────────────────────────────────────────

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

# ── Índice ──────────────────────────────────────────────────────────────────

tabla() {
    local carpeta codigo titulo tipo estado
    printf '| Código | Trabajo | Tipo | Estado |\n'
    printf '|---|---|---|---|\n'

    # Por código, que es cronológico: el registro se lee de arriba abajo como
    # se construyó el sistema.
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

    # La cabecera se escribe a mano y la tabla se genera. El separador es lo
    # que permite las dos cosas en un archivo: lo de arriba lo edita una
    # persona, lo de abajo lo pisa el script.
    sed -n '1,/^<!-- La tabla la genera/p' "$INDICE" > "$tmp" 2>/dev/null \
        || cabecera_indice > "$tmp"

    # Si el archivo aún no tenía marca, se arranca de cero.
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

# ── Guardián ────────────────────────────────────────────────────────────────
#
# Vive aquí y no en `api/tests/Architecture/` —que es donde están los otros
# guardianes— por una razón material: el contenedor de la API sólo monta
# `api/`, así que desde dentro `docs/trabajos/` no existe. Y tampoco debería
# hacerlo: el registro es del repositorio entero, no del backend.
#
# No comprueba el CONTENIDO. Que un trabajo esté bien escrito no lo puede decir
# un script, y fingir que sí daría permiso para escribirlos mal mientras pasen.

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

        # La cabecera completa: el índice se genera de ella, y un campo vacío
        # sale como un hueco en la tabla sin que se sepa si falta el dato o
        # falta el trabajo.
        for clave in codigo titulo tipo estado fecha; do
            valor=$(campo "$carpeta" "$clave")
            [[ -n "$valor" ]] || fallo "A «${nombre}» le falta «${clave}» en la cabecera."
        done

        codigo=$(campo "$carpeta" codigo)

        # Un código que apunta a dos sitios deja de identificar nada, y se
        # citan desde comentarios del código y desde mensajes de commit: son
        # referencias que tienen que resolver.
        case " $codigos_vistos " in
            *" $codigo "*) fallo "El código $codigo está repetido." ;;
            *) codigos_vistos="$codigos_vistos $codigo" ;;
        esac

        # Si la cabecera y la carpeta divergen, buscar por una encuentra el
        # documento y buscar por la otra encuentra la carpeta. Dos formas de
        # buscar lo mismo que dan respuestas distintas es peor que una que falle.
        [[ "$nombre" == "$codigo"* ]] \
            || fallo "«${nombre}» dice ser $codigo en su cabecera."

        # Un `estado: terminado` donde el resto dice `hecho` no rompe nada y
        # hace inútil filtrar el índice, que es para lo único que sirve.
        case "$(campo "$carpeta" tipo)" in
            funcionalidad|arreglo|decision|plan) ;;
            *) fallo "«${nombre}» tiene un tipo que no está en la lista." ;;
        esac

        case "$(campo "$carpeta" estado)" in
            propuesto|en-curso|hecho|descartado) ;;
            *) fallo "«${nombre}» tiene un estado que no está en la lista." ;;
        esac
    done < <(find "$TRABAJOS" -maxdepth 1 -type d -name 'KMB-*' | sort)

    # Un índice al que le falta lo último es PEOR que no tenerlo: quien busca
    # si algo ya se decidió mira ahí, no lo encuentra, y concluye que nadie lo
    # decidió.
    local antes despues
    antes=$(cat "$INDICE")
    indice
    despues=$(cat "$INDICE")

    if [[ "$antes" != "$despues" ]]; then
        # Se deja regenerado: si el único problema era éste, `make check` vuelve
        # a pasar sin que nadie tenga que acordarse del comando.
        fallo "El índice no estaba al día. Ya lo regeneré — revísalo y añádelo al commit."
    fi

    if [[ $ERRORES -gt 0 ]]; then
        echo "" >&2
        echo "$ERRORES problema(s) en docs/trabajos/." >&2
        exit 1
    fi

    echo "✓ Registro de trabajos: $(find "$TRABAJOS" -maxdepth 1 -type d -name 'KMB-*' | wc -l | tr -d ' ') trabajos, índice al día."
}

# ── Entrada ─────────────────────────────────────────────────────────────────

case "${1:-}" in
    nuevo)      nuevo "${2:-}" ;;
    indice)     indice; echo "$INDICE" ;;
    verificar)  comprobar ;;
    *)
        echo "Uso: $0 {nuevo \"Título\" | indice | verificar}" >&2
        exit 1
        ;;
esac
