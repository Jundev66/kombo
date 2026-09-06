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

| Código | Trabajo | Tipo | Estado |
|---|---|---|---|
| [KMB-0001](KMB-0001-el-aislamiento-vive-en-la-base-de-datos/) | El aislamiento vive en la base de datos, no en el código | decision | hecho |
| [KMB-0002](KMB-0002-modulo-es-una-carpeta-mas-un-manifiesto/) | Un módulo es una carpeta más un manifiesto | decision | hecho |
| [KMB-0003](KMB-0003-get-me-es-el-eje-del-sistema/) | GET /api/v1/me es el eje, y el frontend no decide nada | decision | hecho |
| [KMB-0004](KMB-0004-no-se-emiten-documentos-fiscales/) | El sistema no emite documentos fiscales | decision | hecho |
| [KMB-0005](KMB-0005-el-dueno-supervisa-su-propia-caja/) | El dueño supervisa su propia caja con la sesión que ya tiene | funcionalidad | hecho |
| [KMB-0006](KMB-0006-el-encargado-llega-a-todo-salvo-nombrar-duenos/) | El encargado llega a todo salvo nombrar dueños | funcionalidad | hecho |
| [KMB-0007](KMB-0007-reconciliar-los-roles-de-los-negocios-que-ya-exist/) | Reconciliar los roles de los negocios que ya existen | arreglo | hecho |
| [KMB-0008](KMB-0008-las-dos-vistas-de-pedidos-de-un-cliente/) | Las dos vistas de pedidos de un cliente | arreglo | hecho |
| [KMB-0009](KMB-0009-ninguna-lista-corta-en-silencio/) | Ninguna lista corta en silencio | arreglo | hecho |
| [KMB-0010](KMB-0010-iconos-propios-y-menu-agrupado/) | Iconos propios, menú agrupado y enlaces entre pantallas | funcionalidad | hecho |
| [KMB-0011](KMB-0011-toda-la-app-responsive-no-solo-del-telefono-hacia-/) | Toda la app responsive, no sólo del teléfono hacia abajo | arreglo | hecho |
| [KMB-0012](KMB-0012-nginx-resuelve-el-nombre-del-api-en-cada-peticion/) | nginx resuelve el nombre del api en cada petición | arreglo | hecho |
