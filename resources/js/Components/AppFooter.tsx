import { router, usePage } from "@inertiajs/react";
import { Download, Heart, RefreshCw } from "lucide-react";
import { useEffect, useState } from "react";
import { useI18n } from "../i18n";

type Update = {
  installedVersion: string;
  status:
    | "idle"
    | "checking"
    | "available"
    | "requested"
    | "updating"
    | "completed"
    | "failed";
  availableVersion?: string | null;
  releaseUrl?: string | null;
  lastCheckedAt?: string | null;
  error?: string | null;
  enabled: boolean;
};

export function AppFooter() {
  const { t } = useI18n();
  const { auth, installationUpdate } = usePage<{
    auth?: { user?: { role?: string } };
    installationUpdate?: Update;
  }>().props;
  const [confirming, setConfirming] = useState(false);
  const isOwner = auth?.user?.role === "owner";
  const update = installationUpdate;
  const busy =
    update?.status === "checking" ||
    update?.status === "requested" ||
    update?.status === "updating";

  useEffect(() => {
    if (!busy) {
      return;
    }

    const interval = window.setInterval(() => {
      router.reload({
        only: ["installationUpdate"],
      });
    }, 3_000);

    return () => window.clearInterval(interval);
  }, [busy]);

  const check = () =>
    router.post("/installation-update/check", {}, { preserveScroll: true });
  const request = () => {
    setConfirming(false);
    router.post("/installation-update", {}, { preserveScroll: true });
  };

  return (
    <footer className="mx-auto mt-auto flex w-full max-w-5xl flex-col gap-3 border-t border-slate-800 px-6 py-6 text-sm text-slate-400 sm:flex-row sm:items-center sm:justify-between">
      <p>
        {t("footer.created_by")}{" "}
        <a
          className="text-slate-200 hover:text-sky-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-300"
          href="https://github.com/lrqnet"
          target="_blank"
          rel="noreferrer"
        >
          Lucas Quaresma
        </a>
        <span className="px-2 text-slate-600" aria-hidden="true">
          •
        </span>
        <a
          className="text-sky-300 hover:text-sky-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-300"
          href="https://github.com/lrqnet/ipamferry"
          target="_blank"
          rel="noreferrer"
        >
          {t("footer.repository")}
        </a>
        <span className="px-2 text-slate-600" aria-hidden="true">
          •
        </span>
        <a
          className="inline-flex items-center gap-1 text-sky-300 hover:text-sky-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-300"
          href="https://github.com/sponsors/lrqnet"
          target="_blank"
          rel="noreferrer"
        >
          <Heart size={14} aria-hidden="true" />
          {t("footer.support")}
        </a>
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <span>
          {t("footer.version", { version: update?.installedVersion ?? "—" })}
        </span>
        {isOwner && update?.enabled && (
          <>
            {update.status === "available" && (
              <button
                type="button"
                onClick={() => setConfirming(true)}
                className="inline-flex items-center gap-1 rounded border border-sky-700 px-2 py-1 font-medium text-sky-200 hover:bg-sky-950/60"
              >
                <Download size={14} aria-hidden="true" />{" "}
                {t("footer.update_available", {
                  version: update.availableVersion,
                })}
              </button>
            )}
            {update.status !== "available" && (
              <button
                type="button"
                onClick={check}
                disabled={busy}
                className="inline-flex items-center gap-1 rounded px-2 py-1 text-sky-300 hover:bg-slate-800 disabled:opacity-50"
              >
                <RefreshCw
                  className={busy ? "animate-spin" : ""}
                  size={14}
                  aria-hidden="true"
                />{" "}
                {busy
                  ? t(
                      update.status === "checking"
                        ? "footer.checking"
                        : "footer.updating",
                    )
                  : t("footer.check")}
              </button>
            )}
          </>
        )}
        {update?.status === "failed" && (
          <span role="status" className="text-amber-300">
            {t("footer.check_failed")}
          </span>
        )}
      </div>
      {confirming && update?.availableVersion && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="update-title"
          className="fixed inset-0 z-50 grid place-items-center bg-slate-950/80 p-6"
        >
          <section className="max-w-md rounded-xl border border-slate-700 bg-slate-900 p-6 shadow-2xl">
            <h2 id="update-title" className="text-xl font-bold text-slate-100">
              {t("footer.confirm_title")}
            </h2>
            <p className="mt-3 text-slate-300">
              {t("footer.confirm_body", {
                current: update.installedVersion,
                next: update.availableVersion,
              })}
            </p>
            <p className="mt-3 text-sm text-amber-200">
              {t("footer.confirm_warning")}
            </p>
            <div className="mt-6 flex justify-end gap-3">
              <button
                type="button"
                onClick={() => setConfirming(false)}
                className="rounded px-3 py-2 text-slate-300 hover:bg-slate-800"
              >
                {t("footer.cancel")}
              </button>
              <button
                type="button"
                onClick={request}
                className="rounded bg-sky-400 px-3 py-2 font-semibold text-slate-950 hover:bg-sky-300"
              >
                {t("footer.confirm")}
              </button>
            </div>
          </section>
        </div>
      )}
    </footer>
  );
}
