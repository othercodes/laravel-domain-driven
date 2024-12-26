import {resolve} from 'path';
import {defineConfig} from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

const frontAppPath = 'resources/templates/tailwindcss/js';

export default defineConfig({
    resolve: {
        alias: {
            // override the default alias of '@' to point to the frontAppPath
            '@': resolve(__dirname, frontAppPath),
        }
    },
    plugins: [
        laravel({
            input: frontAppPath.concat('/app.js'),
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
