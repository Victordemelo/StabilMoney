import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // ⚠️ Host EXPLÍCITO em IPv4. Sem isto o Vite escolhe sozinho e, nesta máquina,
        // subia em IPv6 (`http://[::1]:5173`) — e a CSP do app NÃO CONSEGUE liberar
        // esse endereço: a gramática de `host-source` do CSP não aceita literal IPv6,
        // então o navegador descarta a fonte como inválida e bloqueia a folha de
        // estilo e o websocket do HMR. O app abria SEM CSS NENHUM com `npm run dev`,
        // e o único aviso era no console. Ver App\Http\Middleware\SecurityHeaders.
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
