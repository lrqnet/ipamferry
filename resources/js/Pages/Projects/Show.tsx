import { Form, Link, router, useForm, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useRef } from "react";
import { PageShell } from "../../Components/PageShell";
import { useI18n } from "../../i18n";

type Action = {
  action_key: string;
  operation: string;
  source_type: string;
  source_id: string;
  target_type: string;
  natural_key: Record<string, unknown>;
};

type Plan = {
  id: number;
  fingerprint: string;
  action_count: number;
  actions: Action[];
  conflict_count: number;
  conflicts: Record<string, unknown>[];
  warnings: string[];
  preservation_summary: Record<string, number>;
  actions_truncated: boolean;
  conflicts_truncated: boolean;
  warnings_truncated: boolean;
  target_is_sandbox: boolean;
  is_current: boolean;
  approved_at?: string | null;
  approved_by?: number | null;
  applied_at?: string | null;
  verified_at?: string | null;
};

type Execution = {
  id: number;
  status: string;
  summary?: {
    total?: number;
    completed?: number;
    remaining?: number;
    by_status?: Record<string, number>;
    verification?: { passed: boolean; checked: number; errors: unknown[] };
  };
  last_error?: string | null;
};

type Project = {
  id: number;
  name: string;
  status: string;
  source_kind: string;
  locale: string;
  has_source_snapshot: boolean;
  has_target_snapshot: boolean;
  definition_locked: boolean;
  target_is_sandbox: boolean;
  discovery_manifest?: {
    source_counts?: Record<string, number>;
    target_counts?: Record<string, number>;
  } | null;
  last_error?: string | null;
};

type PageProps = {
  locale?: unknown;
  availableLocales?: { value: string; label: string }[];
  auth?: { user?: { role?: string } };
  errors?: Record<string, string>;
};

const inputClass =
  "mt-1 w-full rounded border border-slate-700 bg-slate-800 p-2 text-slate-100";
const cardClass = "rounded border border-slate-800 bg-slate-900 p-6";

export default function Show({
  project,
  latestPlan,
  latestExecution,
  sandboxAvailable,
}: {
  project: Project;
  latestPlan?: Plan | null;
  latestExecution?: Execution | null;
  sandboxAvailable: boolean;
}) {
  const { t } = useI18n();
  const page = usePage<PageProps>();
  const role = page.props.auth?.user?.role;
  const canApprove = role === "owner" || role === "administrator";
  const planUsesSandbox = latestPlan?.target_is_sandbox ?? false;
  const applyingRef = useRef(false);
  const applyForm = useForm({
    netbox_url: "",
    netbox_token: "",
    use_sandbox: false,
    confirm: false,
  });
  const verifyForm = useForm({
    netbox_url: "",
    netbox_token: "",
    use_sandbox: false,
  });
  const counts = useMemo(
    () => project.discovery_manifest?.source_counts ?? {},
    [project.discovery_manifest],
  );

  useEffect(() => {
    if (!["planning", "discovering"].includes(project.status)) return;
    const timer = window.setInterval(
      () =>
        router.reload({ only: ["project", "latestPlan", "latestExecution"] }),
      2000,
    );
    return () => window.clearInterval(timer);
  }, [project.status]);

  const warning = (value: string) => {
    if (value.startsWith("{")) {
      try {
        const issue = JSON.parse(value) as { reason?: string };
        if (issue.reason) return t(`conflict.${issue.reason}`);
      } catch {
        return value;
      }
    }
    const mapping =
      /^([a-z0-9_]+) require mapping review before export to NetBox\.$/.exec(
        value,
      );
    if (mapping) return t("show.mapping_warning", { type: mapping[1] });
    const differences =
      /^([a-z0-9_]+) ([^ ]+) exists in NetBox with differences; reuse keeps existing values\.$/.exec(
        value,
      );
    if (differences)
      return t("show.reuse_warning", {
        type: differences[1],
        id: differences[2],
      });
    const schema =
      /^NetBox did not expose a writable POST schema for (.+)$/.exec(value);
    if (schema) return t("show.schema_warning", { endpoint: schema[1] });
    return value;
  };

  const conflictLabel = (conflict: Record<string, unknown>) => {
    const reason =
      typeof conflict.reason === "string" ? conflict.reason : "unknown";
    return t(`conflict.${reason}`);
  };

  const continueApply = () => {
    if (!latestPlan || applyingRef.current) return;
    applyingRef.current = true;
    applyForm.transform((data) => ({ ...data, use_sandbox: planUsesSandbox }));
    applyForm.post(`/projects/${project.id}/plans/${latestPlan.id}/apply`, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: (response) => {
        const props = response.props as unknown as {
          latestExecution?: Execution | null;
        };
        const execution = props.latestExecution;
        if (
          execution?.status === "applying" &&
          (execution.summary?.remaining ?? 0) > 0
        ) {
          applyingRef.current = false;
          window.setTimeout(continueApply, 100);
          return;
        }
        applyForm.reset("netbox_token", "confirm");
      },
      onFinish: () => {
        applyingRef.current = false;
      },
    });
  };

  const discovery =
    project.source_kind === "dump" ? (
      <Form
        action={`/projects/${project.id}/discover-dump`}
        method="post"
        encType="multipart/form-data"
        className={cardClass}
      >
        <fieldset disabled={project.definition_locked}>
          <h2 className="font-bold">{t("show.import")}</h2>
          <p className="mt-1 text-sm text-slate-400">{t("show.sql_safe")}</p>
          {sandboxAvailable && (
            <label className="mt-3 block text-sm">
              <input name="use_sandbox" type="checkbox" value="1" />{" "}
              {t("show.use_sandbox")}
            </label>
          )}
          <label className="mt-3 block text-sm">
            {t("show.dump_file")}
            <input
              name="dump"
              type="file"
              accept=".sql,text/plain,application/sql"
              required
              className="mt-1 block w-full"
            />
          </label>
          <label className="mt-3 block text-sm">
            {t("show.netbox_url")}
            <input name="netbox_url" type="url" className={inputClass} />
          </label>
          <label className="mt-3 block text-sm">
            {t("show.netbox_token")}
            <input
              name="netbox_token"
              type="password"
              autoComplete="off"
              className={inputClass}
            />
          </label>
          <button className="mt-5 rounded bg-sky-400 px-4 py-2 font-semibold text-slate-950">
            {t("show.read_dump")}
          </button>
        </fieldset>
      </Form>
    ) : (
      <Form
        action={`/projects/${project.id}/discover`}
        method="post"
        className={cardClass}
      >
        <fieldset disabled={project.definition_locked}>
          <h2 className="font-bold">{t("show.discover")}</h2>
          <p className="mt-1 text-sm text-slate-400">{t("show.tokens_safe")}</p>
          {sandboxAvailable && (
            <label className="mt-3 block text-sm">
              <input name="use_sandbox" type="checkbox" value="1" />{" "}
              {t("show.use_sandbox")}
            </label>
          )}
          {[
            ["phpipam_url", "show.phpipam_url", "url"],
            ["phpipam_app_id", "show.phpipam_app", "text"],
            ["phpipam_token", "show.phpipam_token", "password"],
            ["netbox_url", "show.netbox_url", "url"],
            ["netbox_token", "show.netbox_token", "password"],
          ].map(([name, label, type]) => (
            <label className="mt-3 block text-sm" key={name}>
              {t(label)}
              <input
                name={name}
                type={type}
                autoComplete={type === "password" ? "off" : undefined}
                required={!name.startsWith("netbox_")}
                className={inputClass}
              />
            </label>
          ))}
          <button className="mt-5 rounded bg-sky-400 px-4 py-2 font-semibold text-slate-950">
            {t("show.run_discovery")}
          </button>
        </fieldset>
      </Form>
    );

  return (
    <PageShell>
      <main className="mx-auto max-w-6xl p-8">
        <Link href="/projects" className="text-sky-400">
          ← {t("common.back")}
        </Link>
        <div className="mt-3 flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="text-3xl font-bold">{project.name}</h1>
            <p className="text-slate-400">
              {t("common.status")}: {t(`status.${project.status}`)}
            </p>
          </div>
          {latestPlan && (
            <code className="text-xs text-slate-500">
              {latestPlan.fingerprint.slice(0, 16)}…
            </code>
          )}
        </div>

        {(project.last_error || page.props.errors?.migration) && (
          <div
            role="alert"
            className="mt-5 rounded border border-red-800 bg-red-950/50 p-4 text-red-200"
          >
            {page.props.errors?.migration ?? project.last_error}
          </div>
        )}
        {Object.entries(page.props.errors ?? {}).filter(
          ([field]) => field !== "migration",
        ).length > 0 && (
          <ul
            role="alert"
            className="mt-5 list-disc rounded border border-red-800 bg-red-950/50 p-4 pl-9 text-red-200"
          >
            {Object.entries(page.props.errors ?? {})
              .filter(([field]) => field !== "migration")
              .map(([field, message]) => (
                <li key={field}>{message}</li>
              ))}
          </ul>
        )}
        {project.definition_locked && (
          <p
            role="status"
            className="mt-4 rounded border border-amber-800 bg-amber-950/30 p-3 text-sm text-amber-200"
          >
            {t("show.definition_locked")}
          </p>
        )}

        <section className="mt-8 grid gap-6 lg:grid-cols-2">
          {discovery}
          <section className={cardClass}>
            <h2 className="font-bold">{t("show.inventory")}</h2>
            {Object.keys(counts).length === 0 ? (
              <p className="mt-3 text-sm text-slate-400">
                {t("show.inventory_empty")}
              </p>
            ) : (
              <dl className="mt-4 grid grid-cols-2 gap-2 text-sm">
                {Object.entries(counts).map(([type, count]) => (
                  <div key={type} className="rounded bg-slate-800 p-3">
                    <dt className="text-slate-400">{type}</dt>
                    <dd className="text-lg font-semibold">{count}</dd>
                  </div>
                ))}
              </dl>
            )}
          </section>
        </section>

        <section className={`${cardClass} mt-6`}>
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div>
              <h2 className="font-bold">{t("show.mapping")}</h2>
              <p className="mt-1 text-sm text-slate-400">
                {t("show.mapping_help")}
              </p>
            </div>
            <Link
              href={`/projects/${project.id}/mapping`}
              className="rounded border border-sky-400 px-4 py-2 text-sky-300"
            >
              {t("mapping.open")}
            </Link>
          </div>
          <Form
            action={`/projects/${project.id}/artifact-locale`}
            method="put"
            className="mt-5 rounded border border-slate-700 bg-slate-950/50 p-4"
          >
            <fieldset disabled={project.definition_locked}>
              <label className="block text-sm" htmlFor="artifact-locale">
                {t("projects.locale")}
              </label>
              <p className="mt-1 text-xs text-slate-400">
                {t("show.artifact_locale_help")}
              </p>
              <div className="mt-3 flex flex-wrap items-end gap-3">
                <select
                  id="artifact-locale"
                  name="locale"
                  defaultValue={project.locale}
                  className="min-w-56 rounded border border-slate-700 bg-slate-800 px-3 py-2 text-sm"
                >
                  {page.props.availableLocales?.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
                <button className="rounded border border-sky-400 px-4 py-2 text-sm text-sky-300 disabled:opacity-50">
                  {t("show.save_artifact_locale")}
                </button>
              </div>
            </fieldset>
          </Form>
        </section>

        {project.has_source_snapshot && (
          <section className={`${cardClass} mt-6`}>
            <h2 className="font-bold">{t("show.change_target")}</h2>
            <p className="mt-1 text-sm text-slate-400">
              {t("show.change_target_help")}
            </p>
            <Form
              action={`/projects/${project.id}/target`}
              method="post"
              className="mt-4"
            >
              {sandboxAvailable && (
                <label className="block text-sm">
                  <input
                    name="use_sandbox"
                    type="checkbox"
                    value="1"
                    defaultChecked={project.target_is_sandbox}
                  />{" "}
                  {t("show.use_sandbox")}
                </label>
              )}
              <label className="mt-3 block text-sm">
                {t("show.netbox_url")}
                <input name="netbox_url" type="url" className={inputClass} />
              </label>
              <label className="mt-3 block text-sm">
                {t("show.netbox_token")}
                <input
                  name="netbox_token"
                  type="password"
                  autoComplete="off"
                  className={inputClass}
                />
              </label>
              <button
                disabled={project.definition_locked}
                className="mt-4 rounded border border-sky-400 px-4 py-2 text-sky-300 disabled:opacity-50"
              >
                {t("show.refresh_target")}
              </button>
            </Form>
          </section>
        )}

        <section className={`${cardClass} mt-6`}>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="font-bold">{t("show.plan_apply")}</h2>
              <p className="mt-1 text-sm text-slate-400">
                {t("show.plan_help")}
              </p>
            </div>
            <Form action={`/projects/${project.id}/plan`} method="post">
              <button
                disabled={
                  !project.has_source_snapshot ||
                  project.definition_locked ||
                  project.status === "verified" ||
                  ["planning", "discovering"].includes(project.status)
                }
                className="rounded border border-sky-400 px-4 py-2 text-sky-300 disabled:opacity-50"
              >
                {project.status === "planning"
                  ? t("show.planning")
                  : t("show.generate_plan")}
              </button>
            </Form>
          </div>

          {latestPlan && (
            <div className="mt-6">
              <p className="text-sm">
                {t("show.actions_conflicts", {
                  actions: latestPlan.action_count,
                  conflicts: latestPlan.conflict_count,
                })}
              </p>
              {!latestPlan.is_current && (
                <p role="alert" className="mt-2 text-sm text-amber-300">
                  {t("show.stale_plan")}
                </p>
              )}
              {latestPlan.warnings.map((item, index) => (
                <p
                  className="mt-2 text-sm text-amber-300"
                  key={`${item}-${index}`}
                >
                  {warning(item)}
                </p>
              ))}
              {latestPlan.warnings_truncated && (
                <p className="mt-2 text-sm text-slate-400">
                  {t("show.truncated")}
                </p>
              )}
              {latestPlan.conflicts.length > 0 && (
                <details className="mt-4 rounded border border-red-900 bg-red-950/30 p-3">
                  <summary className="cursor-pointer text-red-200">
                    {t("show.conflicts")}
                  </summary>
                  <div className="mt-3 grid gap-3">
                    {latestPlan.conflicts.map((conflict, index) => (
                      <div
                        className="rounded bg-red-950/60 p-3"
                        key={`${String(conflict.reason)}-${index}`}
                      >
                        <p className="font-semibold text-red-100">
                          {conflictLabel(conflict)}
                        </p>
                        <pre className="mt-2 overflow-auto text-xs text-red-200">
                          {JSON.stringify(conflict, null, 2)}
                        </pre>
                      </div>
                    ))}
                  </div>
                  {latestPlan.conflicts_truncated && (
                    <p className="mt-3 text-sm text-red-200">
                      {t("show.truncated")}
                    </p>
                  )}
                </details>
              )}
              <details className="mt-4">
                <summary className="cursor-pointer text-sky-300">
                  {t("show.actions")}
                </summary>
                <div className="mt-3 overflow-x-auto">
                  <table className="w-full text-left text-xs">
                    <thead className="text-slate-400">
                      <tr>
                        <th className="p-2">{t("show.operation")}</th>
                        <th className="p-2">{t("show.source")}</th>
                        <th className="p-2">{t("show.target")}</th>
                        <th className="p-2">{t("show.identity")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {latestPlan.actions.map((action) => (
                        <tr
                          key={action.action_key}
                          className="border-t border-slate-800"
                        >
                          <td className="p-2">
                            {t(`operation.${action.operation}`)}
                          </td>
                          <td className="p-2">
                            {action.source_type}:{action.source_id}
                          </td>
                          <td className="p-2">{action.target_type}</td>
                          <td className="p-2 font-mono">
                            {JSON.stringify(action.natural_key)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {latestPlan.actions_truncated && (
                    <p className="mt-3 text-sm text-slate-400">
                      {t("show.truncated")}
                    </p>
                  )}
                </div>
              </details>

              {Object.keys(latestPlan.preservation_summary).length > 0 && (
                <section
                  className="mt-4 rounded border border-amber-400/50 bg-amber-950/30 p-4"
                  aria-labelledby="preservation-title"
                >
                  <h3
                    id="preservation-title"
                    className="font-semibold text-amber-100"
                  >
                    {t("show.preservation_title")}
                  </h3>
                  <p className="mt-1 text-sm text-amber-100/80">
                    {t("show.preservation_help")}
                  </p>
                  <ul className="mt-3 grid gap-1 text-sm text-amber-50 sm:grid-cols-2">
                    {Object.entries(latestPlan.preservation_summary).map(
                      ([category, count]) => (
                        <li key={category}>
                          <code>{category}</code>: {count}
                        </li>
                      ),
                    )}
                  </ul>
                </section>
              )}

              {!latestPlan.approved_at &&
                latestPlan.is_current &&
                canApprove && (
                  <Form
                    action={`/projects/${project.id}/plans/${latestPlan.id}/approve`}
                    method="post"
                    className="mt-5"
                  >
                    <label className="block text-sm">
                      <input name="confirm" type="checkbox" required />{" "}
                      {t("show.confirm_approve")}
                    </label>
                    {Object.keys(latestPlan.preservation_summary).length >
                      0 && (
                      <label className="mt-3 block text-sm text-amber-100">
                        <input
                          name="preservation_acknowledged"
                          type="checkbox"
                          required
                        />{" "}
                        {t("show.confirm_preservation")}
                      </label>
                    )}
                    <button
                      disabled={latestPlan.conflict_count > 0}
                      className="mt-3 rounded bg-amber-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                    >
                      {t("show.approve")}
                    </button>
                  </Form>
                )}

              {latestPlan.approved_at &&
                latestPlan.is_current &&
                latestPlan.conflict_count === 0 &&
                !["applied", "verified"].includes(
                  latestExecution?.status ?? "",
                ) && (
                  <form
                    onSubmit={(event) => {
                      event.preventDefault();
                      continueApply();
                    }}
                    className="mt-5"
                  >
                    {planUsesSandbox && (
                      <p className="text-sm text-slate-300">
                        {t("show.use_sandbox")}
                      </p>
                    )}
                    {!planUsesSandbox && (
                      <>
                        <label className="mt-3 block text-sm">
                          {t("show.netbox_url")}
                          <input
                            type="url"
                            required
                            value={applyForm.data.netbox_url}
                            onChange={(event) =>
                              applyForm.setData(
                                "netbox_url",
                                event.target.value,
                              )
                            }
                            className={inputClass}
                          />
                        </label>
                        <label className="mt-3 block text-sm">
                          {t("show.netbox_token")}
                          <input
                            type="password"
                            autoComplete="off"
                            required
                            value={applyForm.data.netbox_token}
                            onChange={(event) =>
                              applyForm.setData(
                                "netbox_token",
                                event.target.value,
                              )
                            }
                            className={inputClass}
                          />
                        </label>
                      </>
                    )}
                    <label className="mt-3 block text-sm">
                      <input
                        type="checkbox"
                        checked={applyForm.data.confirm}
                        onChange={(event) =>
                          applyForm.setData("confirm", event.target.checked)
                        }
                        required
                      />{" "}
                      {t("show.confirm_apply")}
                    </label>
                    <button
                      disabled={applyForm.processing}
                      className="mt-3 rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                    >
                      {applyForm.processing
                        ? t("show.applying")
                        : latestExecution?.status === "applying"
                          ? t("show.resume")
                          : t("show.apply")}
                    </button>
                  </form>
                )}

              {latestExecution && (
                <div className="mt-5 rounded bg-slate-800 p-4 text-sm">
                  <p>
                    {t("show.execution", { id: latestExecution.id })}:{" "}
                    {t(`execution.${latestExecution.status}`)}
                  </p>
                  {latestExecution.summary?.total !== undefined && (
                    <p className="mt-1 text-slate-300">
                      {t("show.progress", {
                        completed: latestExecution.summary.completed ?? 0,
                        total: latestExecution.summary.total,
                      })}
                    </p>
                  )}
                  {latestExecution.last_error && (
                    <p className="mt-2 text-red-300">
                      {latestExecution.last_error}
                    </p>
                  )}
                </div>
              )}

              {latestExecution &&
                ["applied", "verification_failed"].includes(
                  latestExecution.status,
                ) && (
                  <form
                    onSubmit={(event) => {
                      event.preventDefault();
                      verifyForm.transform((data) => ({
                        ...data,
                        use_sandbox: planUsesSandbox,
                      }));
                      verifyForm.post(
                        `/projects/${project.id}/plans/${latestPlan.id}/executions/${latestExecution.id}/verify`,
                        {
                          preserveScroll: true,
                          onSuccess: () => verifyForm.reset("netbox_token"),
                        },
                      );
                    }}
                    className="mt-5"
                  >
                    <h3 className="font-semibold">{t("show.verify")}</h3>
                    {planUsesSandbox && (
                      <p className="mt-3 text-sm text-slate-300">
                        {t("show.use_sandbox")}
                      </p>
                    )}
                    {!planUsesSandbox && (
                      <>
                        <label className="mt-3 block text-sm">
                          {t("show.netbox_url")}
                          <input
                            type="url"
                            required
                            value={verifyForm.data.netbox_url}
                            onChange={(event) =>
                              verifyForm.setData(
                                "netbox_url",
                                event.target.value,
                              )
                            }
                            className={inputClass}
                          />
                        </label>
                        <label className="mt-3 block text-sm">
                          {t("show.netbox_token")}
                          <input
                            type="password"
                            autoComplete="off"
                            required
                            value={verifyForm.data.netbox_token}
                            onChange={(event) =>
                              verifyForm.setData(
                                "netbox_token",
                                event.target.value,
                              )
                            }
                            className={inputClass}
                          />
                        </label>
                      </>
                    )}
                    <button
                      disabled={verifyForm.processing}
                      className="mt-3 rounded bg-sky-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                    >
                      {verifyForm.processing
                        ? t("show.verifying")
                        : t("show.run_verify")}
                    </button>
                  </form>
                )}

              <a
                href={`/projects/${project.id}/plans/${latestPlan.id}/bundle`}
                className="mt-5 inline-block text-sky-300"
              >
                {t("show.bundle")}
              </a>
            </div>
          )}
        </section>
      </main>
    </PageShell>
  );
}
