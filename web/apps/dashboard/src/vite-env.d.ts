/// <reference types="vite/client" />

// Brings in the declarations for side-effect imports (`./index.css`) and
// static assets. Without it, TypeScript 7 fails with TS2882: it does not
// know what importing a stylesheet means.
