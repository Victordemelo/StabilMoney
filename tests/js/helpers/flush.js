/**
 * Espera as promessas pendentes (microtasks) terminarem.
 *
 * Os módulos testados aqui (`nav.js`, `launch.js`) tratam eventos com handlers
 * `async` que ninguém aguarda: `a.click()` e `form.dispatchEvent('submit')`
 * voltam na hora, com o trabalho ainda em curso. Como os `fetch` são mockados e
 * resolvem sem timer, tudo que falta é dar a vez ao laço de microtasks — e é isso
 * que os `await` deste laço fazem.
 *
 * `ticks` é generoso de propósito: cada `await` dentro do módulo consome um, e
 * ficar contando a profundidade da cadeia deixaria o teste refém do formato do
 * código em vez do comportamento dele.
 */
export async function flush(ticks = 50) {
    for (let i = 0; i < ticks; i++) {
        await Promise.resolve();
    }
}
