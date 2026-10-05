import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// 构建产物固定文件名，由 resources/views/spa.blade.php 直接引用
export default defineConfig({
  plugins: [react()],
  root: 'resources/spa',
  build: {
    outDir: '../../public/spa',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'assets/app.js',
        assetFileNames: 'assets/app.[ext]',
      },
    },
  },
})
