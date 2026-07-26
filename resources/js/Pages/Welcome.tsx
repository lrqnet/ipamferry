import { Link } from "@inertiajs/react";
import { PageShell } from "../Components/PageShell";
import { useI18n } from "../i18n";

export default function Welcome({ installed }: { installed: boolean }) {
  const { t } = useI18n();
  return (
    <PageShell>
      <main className="mx-auto max-w-3xl px-6 py-24">
        <h1 className="text-5xl font-bold">{t("welcome.title")}</h1>
        <p className="mt-6 text-lg text-slate-300">{t("welcome.body")}</p>
        <Link
          className="mt-8 inline-block rounded bg-sky-400 px-5 py-3 font-semibold text-slate-950"
          href={installed ? "/login" : "/setup"}
        >
          {installed ? t("welcome.login") : t("welcome.setup")}
        </Link>
      </main>
    </PageShell>
  );
}
