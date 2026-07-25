import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import { initializeLocaleSync } from './i18n';

initializeLocaleSync();

createInertiaApp({
  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true }) as Record<string, { default: ComponentType }>;
    const page = pages[`./Pages/${name}.tsx`];
    if (!page) throw new Error(`Unknown Inertia page: ${name}`);
    return page;
  },
  setup({ el, App, props }) { createRoot(el).render(<App {...props} />); },
  progress: { color: '#38bdf8' },
});
