/// <reference types="vite/client" />

// Trae las declaraciones de los imports con efecto secundario (`./index.css`)
// y de los recursos estáticos. Sin esto, TypeScript 7 falla con TS2882: no
// sabe qué es importar una hoja de estilos.
