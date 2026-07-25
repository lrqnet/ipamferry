import { Form, Link, usePage } from '@inertiajs/react';
import { PageShell } from '../../Components/PageShell';
import { isLocaleCode, useI18n } from '../../i18n';

type Project = { id: number; name: string; status: string };
export default function Index({ projects }: { projects: Project[] }) {
  const { t } = useI18n(); const { locale, availableLocales } = usePage().props as { locale?: unknown; availableLocales?: { value: string; label: string }[] };
  const selected = isLocaleCode(locale) ? locale : 'en';
  return <PageShell><main className="mx-auto grid max-w-5xl gap-10 p-8 md:grid-cols-[1fr_2fr]"><Form action="/projects" method="post" className="rounded border border-slate-800 bg-slate-900 p-6"><h1 className="text-xl font-bold">{t('projects.new')}</h1><label className="mt-4 block text-sm">{t('projects.name')}<input name="name" required className="mt-1 w-full rounded bg-slate-800 p-2" /></label><label className="mt-4 block text-sm">{t('projects.source')}<select name="source_kind" className="mt-1 w-full rounded bg-slate-800 p-2"><option value="api">{t('projects.api')}</option><option value="dump">{t('projects.dump')}</option></select></label><label className="mt-4 block text-sm">{t('projects.locale')}<select name="locale" defaultValue={selected} className="mt-1 w-full rounded bg-slate-800 p-2">{availableLocales?.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label><button className="mt-5 rounded bg-sky-400 px-4 py-2 font-semibold text-slate-950">{t('common.create')}</button></Form><section><h2 className="text-xl font-bold">{t('projects.existing')}</h2><div className="mt-4 grid gap-3">{projects.map(project => <Link key={project.id} href={`/projects/${project.id}`} className="rounded border border-slate-800 p-4">{project.name} <span className="float-right text-slate-400">{project.status}</span></Link>)}</div></section></main></PageShell>;
}
