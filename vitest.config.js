import { defineConfig } from 'vitest/config';

/**
 * Testes de unidade do JavaScript do app (`resources/js/sm/`).
 *
 * Arquivo SEPARADO do `vite.config.js` de propósito: aquele carrega o
 * `laravel-vite-plugin`, que espera um servidor de dev/manifest e não tem o que
 * fazer numa suíte de unidade. O Vitest dá precedência ao `vitest.config.*`,
 * então o build do app segue intocado.
 *
 * Rodar com: `npm run test:js` (ou `npm run test:js:watch`).
 */
export default defineConfig({
    test: {
        // O código testado mexe em DOM (máscara de input, listeners), então
        // precisa de `document` — daí o jsdom em vez do ambiente `node`.
        environment: 'jsdom',

        include: ['tests/js/**/*.test.js'],

        // ⚠️ `tests/e2e` é do Playwright (`npm run e2e`) e usa outra API de
        // `test`/`expect`. Sem esta exclusão o Vitest tentaria executar aqueles
        // specs e quebraria — os defaults do Vitest NÃO são preservados quando
        // `exclude` é declarado, por isso node_modules e vendor voltam à mão.
        exclude: ['node_modules/**', 'vendor/**', 'tests/e2e/**', 'public/build/**'],
    },
});
