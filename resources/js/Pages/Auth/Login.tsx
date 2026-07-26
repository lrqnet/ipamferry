import { useForm } from "@inertiajs/react";
import { FormEvent } from "react";
import { PageShell } from "../../Components/PageShell";
import { useI18n } from "../../i18n";

export default function Login() {
  const { t } = useI18n();
  const form = useForm({ email: "", password: "" });

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    form.post("/login", { onFinish: () => form.reset("password") });
  };

  return (
    <PageShell>
      <main className="mx-auto max-w-md p-12">
        <h1 className="text-2xl font-bold">{t("login.title")}</h1>
        <form onSubmit={submit} className="mt-6 grid gap-3">
          <label className="text-sm">
            {t("setup.email")}
            <input
              name="email"
              type="email"
              required
              maxLength={254}
              pattern="[^\s@]+@[^\s@]+\.[^\s@]+"
              autoComplete="email"
              spellCheck={false}
              value={form.data.email}
              onChange={(event) =>
                form.setData("email", event.target.value.replace(/\s/g, ""))
              }
              className="mt-1 w-full rounded bg-slate-800 p-3"
            />
          </label>
          {form.errors.email && (
            <p role="alert" className="text-sm text-red-300">
              {form.errors.email}
            </p>
          )}
          <label className="text-sm">
            {t("setup.password")}
            <input
              name="password"
              type="password"
              required
              maxLength={128}
              autoComplete="current-password"
              value={form.data.password}
              onChange={(event) => form.setData("password", event.target.value)}
              className="mt-1 w-full rounded bg-slate-800 p-3"
            />
          </label>
          {form.errors.password && (
            <p role="alert" className="text-sm text-red-300">
              {form.errors.password}
            </p>
          )}
          <button
            disabled={form.processing}
            className="rounded bg-sky-400 p-3 font-semibold text-slate-950 disabled:opacity-60"
          >
            {t("login.submit")}
          </button>
        </form>
      </main>
    </PageShell>
  );
}
