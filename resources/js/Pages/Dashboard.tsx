import { Link } from "@inertiajs/react";
import { PageShell } from "../Components/PageShell";
import { useI18n } from "../i18n";

type Project = {
  id: number;
  name: string;
  status: string;
  plans_count: number;
};
export default function Dashboard({ projects }: { projects: Project[] }) {
  const { t } = useI18n();
  return (
    <PageShell>
      <main className="mx-auto max-w-5xl p-8">
        <header className="flex items-center justify-between">
          <h1 className="text-3xl font-bold">{t("dashboard.title")}</h1>
          <Link
            href="/projects"
            className="rounded bg-sky-400 px-4 py-2 font-semibold text-slate-950"
          >
            {t("dashboard.new")}
          </Link>
        </header>
        <section className="mt-8 grid gap-4">
          {projects.map((project) => (
            <Link
              key={project.id}
              href={`/projects/${project.id}`}
              className="rounded border border-slate-800 bg-slate-900 p-5"
            >
              <div className="flex justify-between">
                <strong>{project.name}</strong>
                <span className="text-slate-400">{project.status}</span>
              </div>
              <p className="mt-2 text-sm text-slate-400">
                {t("dashboard.plans", { count: project.plans_count })}
              </p>
            </Link>
          ))}
          {projects.length === 0 && (
            <p className="rounded border border-dashed border-slate-700 p-8 text-slate-400">
              {t("dashboard.empty")}
            </p>
          )}
        </section>
      </main>
    </PageShell>
  );
}
