import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Brand } from './Brand';
import { LanguageSelector } from './LanguageSelector';

export function PageShell({ children }: { children: ReactNode }) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;

  return <><header className="mx-auto flex max-w-5xl items-center justify-between px-6 pt-5"><Brand /><LanguageSelector /></header>{flash?.success && <div role="status" className="mx-auto mt-4 max-w-5xl rounded border border-emerald-800 bg-emerald-950/50 px-4 py-3 text-emerald-200">{flash.success}</div>}{flash?.error && <div role="alert" className="mx-auto mt-4 max-w-5xl rounded border border-red-800 bg-red-950/50 px-4 py-3 text-red-200">{flash.error}</div>}{children}</>;
}
