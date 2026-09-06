import { defineConfig } from 'vite';
import { resolve } from 'node:path';
import { writeFileSync, mkdirSync } from 'node:fs';

const OUT_DIR = resolve(import.meta.dirname, '../public_html/assets');

/**
 * Vite legt eine Übersicht mit sehr technischen Schlüsseln an
 * (src/main.js, ...). Das PHP daneben will es einfacher: 'app.js'.
 * Dieses kleine Zusatzstück übersetzt das eine ins andere.
 */
function simpleManifest() {
  return {
    name: 'webatze-simple-manifest',
    apply: 'build',
    writeBundle(_options, bundle) {
      const map = {};

      for (const [fileName, chunk] of Object.entries(bundle)) {
        if (chunk.type === 'chunk' && chunk.isEntry) {
          map[`${chunk.name}.js`] = fileName;
          // 'main' heisst im PHP 'app' – damit CSS und JS gleich benannt sind.
          if (chunk.name === 'main') map['app.js'] = fileName;
        }
        if (chunk.type === 'asset' && fileName.endsWith('.css')) {
          // 'main-a1b2c3d4.css' -> 'app.css', 'admin-....css' -> 'admin.css'
          const base = fileName
            .split('/')
            .pop()
            .replace(/-[A-Za-z0-9_-]{6,}\.css$/, '')
            .replace(/\.css$/, '');
          const logical = base === 'main' || base === 'style' ? 'app' : base;
          map[`${logical}.css`] = fileName;
        }
      }

      mkdirSync(OUT_DIR, { recursive: true });
      writeFileSync(
        resolve(OUT_DIR, 'manifest.json'),
        JSON.stringify(map, null, 2) + '\n',
        'utf8'
      );

      // Schriften und Bilder liegen unter frontend/public und werden von
      // Vite selbst nach assets/ kopiert – hier ist nichts weiter zu tun.

      console.log('  manifest.json geschrieben:', Object.keys(map).join(', '));
    },
  };
}

export default defineConfig({
  // Alles im Build wird unter /assets/ ausgeliefert.
  base: '/assets/',

  plugins: [simpleManifest()],

  build: {
    outDir: OUT_DIR,
    emptyOutDir: true,
    assetsDir: '.',
    // Ältere Handys sollen die Seite auch bekommen.
    target: 'es2020',
    // Jeder Einstiegspunkt bekommt sein eigenes CSS: die Admin-Stile
    // sollen nicht auf der öffentlichen Website mitgeladen werden.
    cssCodeSplit: true,
    sourcemap: false,
    reportCompressedSize: true,
    // three.js ist naturgemäss gross; die Warnung wäre nur Lärm.
    chunkSizeWarningLimit: 700,

    rollupOptions: {
      input: {
        main: resolve(import.meta.dirname, 'src/main.js'),
        admin: resolve(import.meta.dirname, 'src/admin/admin.js'),
        editor: resolve(import.meta.dirname, 'src/editor/editor.js'),
        direkt: resolve(import.meta.dirname, 'src/direkt/direkt.js'),
      },
      output: {
        entryFileNames: '[name]-[hash].js',
        chunkFileNames: '[name]-[hash].js',
        assetFileNames: '[name]-[hash][extname]',
        manualChunks(id) {
          // three.js in ein eigenes Paket, das erst bei Bedarf geladen wird.
          if (id.includes('node_modules/three')) return 'three';
          return undefined;
        },
      },
    },
  },

  css: {
    devSourcemap: true,
  },

  server: {
    port: 5173,
    strictPort: false,
  },
});
