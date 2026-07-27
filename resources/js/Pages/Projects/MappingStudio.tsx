import { Link, router } from "@inertiajs/react";
import axios from "axios";
import {
  AlertTriangle,
  ArrowLeft,
  Check,
  CloudCog,
  Plus,
  Redo2,
  Save,
  Undo2,
  WandSparkles,
  X,
} from "lucide-react";
import {
  lazy,
  Suspense,
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";
import { PageShell } from "../../Components/PageShell";
import { useI18n } from "../../i18n";

type Scalar = string | number | boolean | null;
type JsonValue = Scalar | JsonValue[] | { [key: string]: JsonValue };
type Mapping = {
  schema_version: number;
  object_policies?: Record<
    string,
    { policy: "migrate" | "ignore" | "preserve"; target_type?: string }
  >;
  reference_rules?: Rule[];
  status_rules?: Rule[];
  update_rules?: Record<string, string[]>;
  field_rules?: Rule[];
  relation_rules?: Rule[];
  preservation_rules?: Record<string, string>;
  [key: string]: JsonValue | Rule[] | Record<string, unknown> | undefined;
};
type Rule = { id: string; [key: string]: JsonValue };
type FieldDefinition = {
  type: string;
  types: string[];
  filled: number;
  total: number;
  fill_rate: number;
  cardinality: number;
  cardinality_limited: boolean;
  examples: string[];
};
type CatalogType = {
  count: number;
  fields: Record<string, FieldDefinition>;
  identities?: {
    source_id: string;
    label: string;
    hints?: Record<string, string>;
  }[];
  identities_truncated?: boolean;
};
type Catalog = {
  schema_version: number;
  source_fingerprint: string;
  target_fingerprint: string;
  source: Record<string, CatalogType>;
  target: Record<string, CatalogType>;
  target_choices: Record<string, Record<string, unknown[]>>;
  natural_keys: Record<string, string[]>;
};
type Suggestion = {
  id: string;
  kind: "object" | "field";
  confidence: number;
  reason: string;
  signals?: string[];
  rule: Rule;
};
type Preview = {
  id: string;
  status: "queued" | "running" | "completed" | "failed";
  mapping_revision: number;
  result?: {
    applicable: false;
    approvable: false;
    summary: {
      actions: number;
      conflicts: number;
      warnings: number;
      operations: Record<string, number>;
      target_types: Record<string, number>;
    };
    coverage: {
      source: Record<string, number>;
      preserved: Record<string, number>;
    };
    conflicts: Record<string, unknown>[];
    warnings: string[];
  } | null;
  last_error?: string | null;
  expires_at: string;
};
type Project = {
  id: number;
  name: string;
  locale: string;
  status: string;
  mapping_revision: number;
  definition_locked: boolean;
  can_edit: boolean;
};
type ValidationIssue = { code: string; pointer: string; message: string };

const tabs = [
  "overview",
  "objects",
  "references",
  "fields",
  "status",
  "relations",
  "preview",
  "json",
] as const;
type Tab = (typeof tabs)[number];
const card = "rounded-xl border border-slate-800 bg-slate-900 p-5";
const input =
  "w-full rounded border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100";
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value)) as T;
const ruleId = (prefix: string) => `${prefix}-${crypto.randomUUID()}`;
const JsonExpert = lazy(() => import("../../Components/JsonExpertEditor"));

export default function MappingStudio({
  project,
  mapping: initialMapping,
  upgradeAvailable,
  upgradedMapping,
  catalog,
  suggestions: initialSuggestions,
  latestPreview,
}: {
  project: Project;
  mapping: Mapping;
  mappingJson: string;
  upgradeAvailable: boolean;
  upgradedMapping?: Mapping | null;
  catalog: Catalog;
  suggestions: Suggestion[];
  latestPreview?: Preview | null;
}) {
  const { t } = useI18n();
  const [activeTab, setActiveTab] = useState<Tab>("overview");
  const [mapping, setMapping] = useState<Mapping>(() => clone(initialMapping));
  const [savedMapping, setSavedMapping] = useState<Mapping>(() =>
    clone(initialMapping),
  );
  const [revision, setRevision] = useState(project.mapping_revision);
  const [jsonText, setJsonText] = useState(() =>
    JSON.stringify(initialMapping, null, 2),
  );
  const [jsonDirty, setJsonDirty] = useState(false);
  const [issues, setIssues] = useState<ValidationIssue[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [suggestions, setSuggestions] = useState(initialSuggestions);
  const [preview, setPreview] = useState<Preview | null>(latestPreview ?? null);
  const [history, setHistory] = useState<Mapping[]>([clone(initialMapping)]);
  const [historyIndex, setHistoryIndex] = useState(0);
  const isDirty = useMemo(
    () => JSON.stringify(mapping) !== JSON.stringify(savedMapping),
    [mapping, savedMapping],
  );
  const editable = project.can_edit && !project.definition_locked;

  const commitVisual = useCallback(
    (next: Mapping) => {
      const normalized = clone(next);
      setHistory((current) => {
        const updated = [
          ...current.slice(0, historyIndex + 1),
          normalized,
        ].slice(-100);
        setHistoryIndex(updated.length - 1);
        return updated;
      });
      setMapping(normalized);
      setJsonText(JSON.stringify(normalized, null, 2));
      setJsonDirty(false);
      setIssues([]);
      setNotice(null);
    },
    [historyIndex],
  );

  useEffect(() => {
    const beforeUnload = (event: BeforeUnloadEvent) => {
      if (!isDirty) return;
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", beforeUnload);
    const removeBefore = router.on("before", (event) => {
      if (isDirty && !window.confirm(t("mapping.unsaved_confirm")))
        event.preventDefault();
    });
    return () => {
      window.removeEventListener("beforeunload", beforeUnload);
      removeBefore();
    };
  }, [isDirty, t]);

  useEffect(() => {
    if (!preview || !["queued", "running"].includes(preview.status)) return;
    const timer = window.setInterval(() => {
      void axios
        .get<Preview>(`/projects/${project.id}/mapping/previews/${preview.id}`)
        .then(({ data }) => setPreview(data));
    }, 1500);
    return () => window.clearInterval(timer);
  }, [preview, project.id]);

  const undo = () => {
    if (historyIndex === 0) return;
    const nextIndex = historyIndex - 1;
    setHistoryIndex(nextIndex);
    const previous = clone(history[nextIndex]);
    setMapping(previous);
    setJsonText(JSON.stringify(previous, null, 2));
    setJsonDirty(false);
  };
  const redo = () => {
    if (historyIndex >= history.length - 1) return;
    const nextIndex = historyIndex + 1;
    setHistoryIndex(nextIndex);
    const next = clone(history[nextIndex]);
    setMapping(next);
    setJsonText(JSON.stringify(next, null, 2));
    setJsonDirty(false);
  };

  const save = async () => {
    setSaving(true);
    setIssues([]);
    setNotice(null);
    try {
      const response = await axios.put<{
        mapping: Mapping;
        revision: number;
        locale: string;
      }>(
        `/projects/${project.id}/mapping`,
        { mapping, locale: project.locale, revision },
        { headers: { Accept: "application/json" } },
      );
      const persisted = clone(response.data.mapping);
      setMapping(persisted);
      setSavedMapping(persisted);
      setRevision(response.data.revision);
      setJsonText(JSON.stringify(persisted, null, 2));
      setJsonDirty(false);
      setNotice(t("mapping.saved"));
    } catch (error) {
      if (axios.isAxiosError(error)) {
        const data = error.response?.data as
          | {
              message?: string;
              errors?: ValidationIssue[];
              current_revision?: number;
            }
          | undefined;
        setIssues(
          data?.errors ?? [
            {
              code: "mapping.update_failed",
              pointer: "",
              message: data?.message ?? t("mapping.save_failed"),
            },
          ],
        );
        if (error.response?.status === 409 && data?.current_revision)
          setRevision(data.current_revision);
      }
    } finally {
      setSaving(false);
    }
  };

  const applyJson = () => {
    try {
      const decoded = JSON.parse(jsonText) as Mapping;
      if (!decoded || Array.isArray(decoded) || typeof decoded !== "object")
        throw new Error(t("mapping.json_object"));
      commitVisual(decoded);
      setNotice(t("mapping.json_applied"));
    } catch (error) {
      setIssues([
        {
          code: "mapping.invalid_json",
          pointer: "",
          message:
            error instanceof Error ? error.message : t("mapping.invalid_json"),
        },
      ]);
    }
  };

  const acceptSuggestion = (suggestion: Suggestion) => {
    const next = clone(
      mapping.schema_version === 2 ? mapping : (upgradedMapping ?? mapping),
    );
    if (suggestion.kind === "object") {
      next.object_policies ??= {};
      next.object_policies[String(suggestion.rule.source_type)] = {
        policy: "migrate",
        target_type: String(suggestion.rule.target_type),
      };
    } else {
      next.field_rules ??= [];
      next.field_rules.push(suggestion.rule);
    }
    commitVisual(next);
    setSuggestions((current) =>
      current.filter((item) => item.id !== suggestion.id),
    );
  };
  const acceptAllSuggestions = () => {
    const next = clone(
      mapping.schema_version === 2 ? mapping : (upgradedMapping ?? mapping),
    );
    next.object_policies ??= {};
    next.field_rules ??= [];
    for (const suggestion of suggestions) {
      if (suggestion.kind === "object") {
        next.object_policies[String(suggestion.rule.source_type)] = {
          policy: "migrate",
          target_type: String(suggestion.rule.target_type),
        };
      } else {
        next.field_rules.push(suggestion.rule);
      }
    }
    commitVisual(next);
    setSuggestions([]);
  };

  const objectPolicies = mapping.object_policies ?? {};
  const fieldRules = mapping.field_rules ?? [];
  const referenceRules = mapping.reference_rules ?? [];
  const statusRules = mapping.status_rules ?? [];
  const relationRules = mapping.relation_rules ?? [];

  return (
    <PageShell>
      <main className="mx-auto max-w-7xl px-6 py-8">
        <Link
          href={`/projects/${project.id}`}
          className="inline-flex items-center gap-2 text-sky-300"
        >
          <ArrowLeft size={16} /> {t("mapping.back")}
        </Link>
        <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold">{t("mapping.title")}</h1>
            <p className="mt-1 text-slate-400">
              {project.name} · {t("mapping.revision", { revision })}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={undo}
              disabled={!editable || historyIndex === 0}
              aria-label={t("mapping.undo")}
              className="rounded border border-slate-700 p-2 disabled:opacity-40"
            >
              <Undo2 size={18} />
            </button>
            <button
              type="button"
              onClick={redo}
              disabled={!editable || historyIndex >= history.length - 1}
              aria-label={t("mapping.redo")}
              className="rounded border border-slate-700 p-2 disabled:opacity-40"
            >
              <Redo2 size={18} />
            </button>
            <button
              type="button"
              onClick={() => void save()}
              disabled={!editable || !isDirty || saving}
              className="inline-flex items-center gap-2 rounded bg-sky-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-40"
            >
              <Save size={17} />{" "}
              {saving ? t("mapping.saving") : t("mapping.save")}
            </button>
          </div>
        </div>

        {project.definition_locked && (
          <p
            role="alert"
            className="mt-4 rounded border border-amber-700 bg-amber-950/40 p-3 text-amber-200"
          >
            <AlertTriangle className="mr-2 inline" size={17} />
            {t("mapping.locked")}
          </p>
        )}
        {!project.can_edit && (
          <p className="mt-4 rounded border border-slate-700 bg-slate-900 p-3 text-slate-300">
            {t("mapping.read_only")}
          </p>
        )}
        {notice && (
          <p
            role="status"
            className="mt-4 rounded border border-emerald-800 bg-emerald-950/40 p-3 text-emerald-200"
          >
            <Check className="mr-2 inline" size={17} />
            {notice}
          </p>
        )}
        {issues.length > 0 && (
          <div
            role="alert"
            className="mt-4 rounded border border-red-800 bg-red-950/40 p-4 text-red-200"
          >
            <p className="font-semibold">{t("mapping.validation_errors")}</p>
            <ul className="mt-2 space-y-1 text-sm">
              {issues.map((issue, index) => {
                const localized = t(issue.code);
                return (
                  <li key={`${issue.pointer}-${index}`}>
                    <code>{issue.pointer || "/"}</code>:{" "}
                    {localized === issue.code ? issue.message : localized}
                  </li>
                );
              })}
            </ul>
          </div>
        )}

        <nav
          aria-label={t("mapping.sections")}
          className="mt-7 flex gap-1 overflow-x-auto rounded-xl border border-slate-800 bg-slate-900 p-1"
        >
          {tabs.map((tab) => (
            <button
              key={tab}
              type="button"
              onClick={() => setActiveTab(tab)}
              className={`whitespace-nowrap rounded-lg px-4 py-2 text-sm ${activeTab === tab ? "bg-sky-400 font-semibold text-slate-950" : "text-slate-300 hover:bg-slate-800"}`}
            >
              {t(`mapping.tab.${tab}`)}
            </button>
          ))}
        </nav>

        <section className="mt-5">
          {activeTab === "overview" && (
            <Overview
              catalog={catalog}
              suggestions={suggestions}
              editable={editable}
              upgradeAvailable={
                upgradeAvailable && mapping.schema_version === 1
              }
              onUpgrade={() => upgradedMapping && commitVisual(upgradedMapping)}
              onAccept={acceptSuggestion}
              onAcceptAll={acceptAllSuggestions}
              t={t}
            />
          )}
          {activeTab === "objects" && (
            <Objects
              catalog={catalog}
              policies={objectPolicies}
              preservationRules={mapping.preservation_rules ?? {}}
              editable={editable}
              onChange={(object_policies) =>
                commitVisual({ ...mapping, object_policies })
              }
              onPreservationChange={(preservation_rules) =>
                commitVisual({ ...mapping, preservation_rules })
              }
              t={t}
            />
          )}
          {activeTab === "references" && (
            <RulesEditor
              kind="reference"
              rules={referenceRules}
              editable={editable}
              onChange={(reference_rules) =>
                commitVisual({ ...mapping, reference_rules })
              }
              t={t}
            />
          )}
          {activeTab === "fields" && (
            <Fields
              catalog={catalog}
              rules={fieldRules}
              editable={editable}
              onChange={(field_rules) =>
                commitVisual({ ...mapping, field_rules })
              }
              t={t}
            />
          )}
          {activeTab === "status" && (
            <StatusUpdates
              mapping={mapping}
              rules={statusRules}
              catalog={catalog}
              editable={editable}
              onChange={commitVisual}
              t={t}
            />
          )}
          {activeTab === "relations" && (
            <RelationsStudio
              catalog={catalog}
              rules={relationRules}
              editable={editable}
              onChange={(relation_rules) =>
                commitVisual({ ...mapping, relation_rules })
              }
              t={t}
            />
          )}
          {activeTab === "preview" && (
            <PreviewPanel
              project={project}
              preview={preview}
              editable={editable}
              dirty={isDirty}
              setPreview={setPreview}
              t={t}
            />
          )}
          {activeTab === "json" && (
            <Suspense
              fallback={
                <div className={card} aria-busy="true">
                  {t("mapping.preview_state.running")}
                </div>
              }
            >
              <JsonExpert
                value={jsonText}
                dirty={jsonDirty}
                editable={editable}
                onChange={(value) => {
                  setJsonText(value);
                  setJsonDirty(value !== JSON.stringify(mapping, null, 2));
                }}
                onApply={applyJson}
                onDiscard={() => {
                  setJsonText(JSON.stringify(mapping, null, 2));
                  setJsonDirty(false);
                  setIssues([]);
                }}
                t={t}
              />
            </Suspense>
          )}
        </section>
      </main>
    </PageShell>
  );
}

function Overview({
  catalog,
  suggestions,
  editable,
  upgradeAvailable,
  onUpgrade,
  onAccept,
  onAcceptAll,
  t,
}: {
  catalog: Catalog;
  suggestions: Suggestion[];
  editable: boolean;
  upgradeAvailable: boolean;
  onUpgrade: () => void;
  onAccept: (suggestion: Suggestion) => void;
  onAcceptAll: () => void;
  t: (key: string, options?: Record<string, unknown>) => string;
}) {
  const sourceCount = Object.values(catalog.source).reduce(
    (sum, item) => sum + item.count,
    0,
  );
  const targetCount = Object.values(catalog.target).reduce(
    (sum, item) => sum + item.count,
    0,
  );
  return (
    <div className="grid gap-5 lg:grid-cols-3">
      <div className={card}>
        <p className="text-sm text-slate-400">{t("mapping.source_objects")}</p>
        <p className="mt-2 text-3xl font-bold">{sourceCount}</p>
        <p className="text-sm text-slate-500">
          {Object.keys(catalog.source).length} {t("mapping.object_types")}
        </p>
      </div>
      <div className={card}>
        <p className="text-sm text-slate-400">{t("mapping.target_objects")}</p>
        <p className="mt-2 text-3xl font-bold">{targetCount}</p>
        <p className="text-sm text-slate-500">
          {Object.keys(catalog.target).length} {t("mapping.object_types")}
        </p>
      </div>
      <div className={card}>
        <p className="text-sm text-slate-400">{t("mapping.suggestions")}</p>
        <p className="mt-2 text-3xl font-bold">{suggestions.length}</p>
        <p className="text-sm text-slate-500">{t("mapping.review_required")}</p>
      </div>
      {upgradeAvailable && (
        <div className={`${card} border-amber-700 lg:col-span-3`}>
          <h2 className="font-semibold text-amber-200">
            {t("mapping.v1_detected")}
          </h2>
          <p className="mt-1 text-sm text-slate-300">{t("mapping.v1_help")}</p>
          <button
            type="button"
            disabled={!editable}
            onClick={onUpgrade}
            className="mt-3 rounded bg-amber-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-40"
          >
            {t("mapping.upgrade")}
          </button>
        </div>
      )}
      <div className={`${card} lg:col-span-3`}>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="font-semibold">
              {t("mapping.deterministic_suggestions")}
            </h2>
            <p className="text-sm text-slate-400">
              {t("mapping.suggestions_help")}
            </p>
          </div>
          {suggestions.length > 0 && (
            <button
              type="button"
              disabled={!editable}
              onClick={onAcceptAll}
              className="inline-flex items-center gap-2 rounded border border-sky-500 px-3 py-2 text-sm text-sky-300 disabled:opacity-40"
            >
              <WandSparkles size={16} />
              {t("mapping.accept_all")}
            </button>
          )}
        </div>
        <div className="mt-4 grid gap-2">
          {suggestions.length === 0 ? (
            <p className="text-sm text-slate-500">
              {t("mapping.no_suggestions")}
            </p>
          ) : (
            suggestions.map((suggestion) => (
              <div
                key={suggestion.id}
                className="flex items-center justify-between gap-3 rounded border border-slate-800 bg-slate-950 p-3"
              >
                <div>
                  <p className="text-sm font-medium">
                    {suggestion.kind}: {String(suggestion.rule.source_type)} →{" "}
                    {String(
                      suggestion.rule.target_type ?? suggestion.rule.target,
                    )}
                  </p>
                  <p className="text-xs text-slate-500">
                    {t(`mapping.reason.${suggestion.reason}`)} ·{" "}
                    {Math.round(suggestion.confidence * 100)}%
                  </p>
                </div>
                <button
                  type="button"
                  disabled={!editable}
                  onClick={() => onAccept(suggestion)}
                  className="rounded border border-emerald-600 p-2 text-emerald-300 disabled:opacity-40"
                  aria-label={t("mapping.accept")}
                >
                  <Check size={16} />
                </button>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  );
}

function Objects({
  catalog,
  policies,
  preservationRules,
  editable,
  onChange,
  onPreservationChange,
  t,
}: {
  catalog: Catalog;
  policies: NonNullable<Mapping["object_policies"]>;
  preservationRules: Record<string, string>;
  editable: boolean;
  onChange: (policies: NonNullable<Mapping["object_policies"]>) => void;
  onPreservationChange: (rules: Record<string, string>) => void;
  t: (key: string) => string;
}) {
  const targetTypes = Object.keys(catalog.natural_keys);
  return (
    <div className={card}>
      <h2 className="text-lg font-semibold">{t("mapping.object_policies")}</h2>
      <p className="mt-1 text-sm text-slate-400">{t("mapping.object_help")}</p>
      <div className="mt-5 grid gap-3">
        {Object.entries(catalog.source).map(([type, definition]) => {
          const policy = policies[type] ?? {
            policy: "preserve" as const,
            target_type: type,
          };
          return (
            <div
              key={type}
              className="grid items-center gap-3 rounded border border-slate-800 bg-slate-950 p-4 md:grid-cols-[1fr_180px_1fr_180px]"
            >
              <div>
                <p className="font-medium">{type}</p>
                <p className="text-xs text-slate-500">
                  {definition.count} {t("mapping.records")} ·{" "}
                  {Object.keys(definition.fields).length} {t("mapping.fields")}
                </p>
              </div>
              <select
                disabled={!editable}
                value={policy.policy}
                onChange={(event) =>
                  onChange({
                    ...policies,
                    [type]: {
                      ...policy,
                      policy: event.target.value as
                        "migrate" | "ignore" | "preserve",
                    },
                  })
                }
                className={input}
              >
                <option value="migrate">{t("mapping.policy.migrate")}</option>
                <option value="preserve">{t("mapping.policy.preserve")}</option>
                <option value="ignore">{t("mapping.policy.ignore")}</option>
              </select>
              <input
                disabled={!editable || policy.policy !== "migrate"}
                list="target-types"
                value={policy.target_type ?? ""}
                onChange={(event) =>
                  onChange({
                    ...policies,
                    [type]: { ...policy, target_type: event.target.value },
                  })
                }
                className={input}
                aria-label={t("mapping.target_type")}
              />
              <select
                disabled={!editable || policy.policy !== "preserve"}
                value={preservationRules[type] ?? "report"}
                onChange={(event) =>
                  onPreservationChange({
                    ...preservationRules,
                    [type]: event.target.value,
                  })
                }
                className={input}
                aria-label={t("mapping.preservation_handling")}
              >
                {["report", "note", "custom_field", "discard"].map(
                  (handling) => (
                    <option key={handling} value={handling}>
                      {t(`mapping.preservation.${handling}`)}
                    </option>
                  ),
                )}
              </select>
            </div>
          );
        })}
      </div>
      <datalist id="target-types">
        {targetTypes.map((type) => (
          <option key={type} value={type} />
        ))}
      </datalist>
    </div>
  );
}

function Fields({
  catalog,
  rules,
  editable,
  onChange,
  t,
}: {
  catalog: Catalog;
  rules: Rule[];
  editable: boolean;
  onChange: (rules: Rule[]) => void;
  t: (key: string) => string;
}) {
  const sourceTypes = Object.keys(catalog.source);
  const add = () =>
    onChange([
      ...rules,
      {
        id: ruleId("field"),
        source_type: sourceTypes[0] ?? "prefix",
        source_field: "",
        target: "",
        target_kind: "field",
        action: "copy",
      },
    ]);
  return (
    <div className="grid gap-5 lg:grid-cols-[1.2fr_.8fr]">
      <div className={card}>
        <div className="flex justify-between gap-3">
          <div>
            <h2 className="text-lg font-semibold">
              {t("mapping.field_rules")}
            </h2>
            <p className="text-sm text-slate-400">{t("mapping.field_help")}</p>
          </div>
          <button
            type="button"
            disabled={!editable}
            onClick={add}
            className="rounded border border-sky-500 p-2 text-sky-300 disabled:opacity-40"
          >
            <Plus size={17} />
          </button>
        </div>
        <div className="mt-5 grid gap-3">
          {rules.map((rule, index) => {
            const fields = Object.keys(
              catalog.source[String(rule.source_type)]?.fields ?? {},
            );
            const update = (changes: Record<string, JsonValue>) =>
              onChange(
                rules.map((item, itemIndex) =>
                  itemIndex === index ? { ...item, ...changes } : item,
                ),
              );
            return (
              <div
                key={rule.id}
                className="rounded border border-slate-800 bg-slate-950 p-3"
              >
                <div className="grid gap-2 md:grid-cols-[1fr_1fr_140px_120px_1fr_auto]">
                  <select
                    disabled={!editable}
                    value={String(rule.source_type ?? "")}
                    onChange={(event) =>
                      update({ source_type: event.target.value })
                    }
                    className={input}
                  >
                    {sourceTypes.map((type) => (
                      <option key={type}>{type}</option>
                    ))}
                  </select>
                  <input
                    disabled={!editable}
                    list={`source-fields-${index}`}
                    value={String(rule.source_field ?? "")}
                    onChange={(event) =>
                      update({ source_field: event.target.value })
                    }
                    className={input}
                    placeholder={t("mapping.source_field")}
                  />
                  <datalist id={`source-fields-${index}`}>
                    {fields.map((field) => (
                      <option key={field} value={field} />
                    ))}
                  </datalist>
                  <select
                    disabled={!editable}
                    value={String(rule.action ?? "copy")}
                    onChange={(event) => update({ action: event.target.value })}
                    className={input}
                  >
                    {[
                      "copy",
                      "ignore",
                      "fixed",
                      "concat",
                      "normalize",
                      "lookup",
                    ].map((action) => (
                      <option key={action} value={action}>
                        {t(`mapping.action.${action}`)}
                      </option>
                    ))}
                  </select>
                  <select
                    disabled={!editable}
                    value={String(rule.target_kind ?? "field")}
                    onChange={(event) =>
                      update({ target_kind: event.target.value })
                    }
                    className={input}
                  >
                    <option value="field">{t("mapping.target_regular")}</option>
                    <option value="custom_field">
                      {t("mapping.target_custom")}
                    </option>
                  </select>
                  <input
                    disabled={!editable}
                    value={String(rule.target ?? "")}
                    onChange={(event) => update({ target: event.target.value })}
                    className={input}
                    placeholder={t("mapping.target_field")}
                  />
                  <button
                    type="button"
                    disabled={!editable}
                    onClick={() =>
                      onChange(
                        rules.filter((_, itemIndex) => itemIndex !== index),
                      )
                    }
                    className="rounded border border-red-800 p-2 text-red-300 disabled:opacity-40"
                    aria-label={t("mapping.remove")}
                  >
                    <X size={16} />
                  </button>
                </div>
                <FieldRuleOptions
                  rule={rule}
                  editable={editable}
                  update={update}
                  t={t}
                />
              </div>
            );
          })}
          {rules.length === 0 && (
            <p className="text-sm text-slate-500">
              {t("mapping.no_field_rules")}
            </p>
          )}
        </div>
      </div>
      <CatalogBrowser catalog={catalog.source} t={t} />
    </div>
  );
}

function FieldRuleOptions({
  rule,
  editable,
  update,
  t,
}: {
  rule: Rule;
  editable: boolean;
  update: (changes: Record<string, JsonValue>) => void;
  t: (key: string) => string;
}) {
  const action = String(rule.action ?? "copy");
  const [lookupText, setLookupText] = useState(() =>
    JSON.stringify(rule.table ?? {}, null, 2),
  );
  if (action === "fixed")
    return (
      <input
        disabled={!editable}
        value={String(rule.value ?? "")}
        onChange={(event) => update({ value: event.target.value })}
        className={`${input} mt-2`}
        placeholder={t("mapping.fixed_value")}
      />
    );
  if (action === "concat")
    return (
      <div className="mt-2 grid gap-2 md:grid-cols-[1fr_180px]">
        <input
          disabled={!editable}
          value={
            Array.isArray(rule.fields) ? rule.fields.map(String).join(", ") : ""
          }
          onChange={(event) =>
            update({
              fields: event.target.value
                .split(",")
                .map((value) => value.trim())
                .filter(Boolean),
            })
          }
          className={input}
          placeholder={t("mapping.concat_fields")}
        />
        <input
          disabled={!editable}
          value={String(rule.separator ?? " ")}
          onChange={(event) => update({ separator: event.target.value })}
          className={input}
          placeholder={t("mapping.separator")}
        />
      </div>
    );
  if (action === "normalize")
    return (
      <select
        disabled={!editable}
        value={String(rule.mode ?? "trim")}
        onChange={(event) => update({ mode: event.target.value })}
        className={`${input} mt-2`}
      >
        <option value="trim">trim</option>
        <option value="lower">lower</option>
        <option value="upper">upper</option>
        <option value="slug">slug</option>
      </select>
    );
  if (action === "lookup")
    return (
      <textarea
        disabled={!editable}
        value={lookupText}
        onChange={(event) => {
          setLookupText(event.target.value);
          try {
            update({ table: JSON.parse(event.target.value) as JsonValue });
          } catch {
            return;
          }
        }}
        rows={4}
        spellCheck={false}
        className={`${input} mt-2 font-mono`}
        aria-label={t("mapping.lookup_table")}
      />
    );
  return null;
}

function RulesEditor({
  kind,
  rules,
  editable,
  onChange,
  t,
}: {
  kind: "reference" | "relation";
  rules: Rule[];
  editable: boolean;
  onChange: (rules: Rule[]) => void;
  t: (key: string) => string;
}) {
  const add = () =>
    onChange([
      ...rules,
      kind === "reference"
        ? {
            id: ruleId("reference"),
            source_type: "",
            source_field: "",
            target_type: "",
            target_field: "",
            match: "natural_key",
          }
        : {
            id: ruleId("relation"),
            relation: "",
            source_type: "",
            target_type: "",
            enabled: false,
            settings: {},
          },
    ]);
  return (
    <div className={card}>
      <div className="flex justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">
            {t(`mapping.${kind}_rules`)}
          </h2>
          <p className="text-sm text-slate-400">{t(`mapping.${kind}_help`)}</p>
        </div>
        <button
          type="button"
          disabled={!editable}
          onClick={add}
          className="rounded border border-sky-500 p-2 text-sky-300 disabled:opacity-40"
        >
          <Plus size={17} />
        </button>
      </div>
      <div className="mt-5 grid gap-3">
        {rules.map((rule, index) => (
          <div
            key={rule.id}
            className="grid gap-2 rounded border border-slate-800 bg-slate-950 p-3 md:grid-cols-4"
          >
            <input
              disabled={!editable}
              value={String(
                kind === "reference"
                  ? (rule.source_type ?? "")
                  : (rule.relation ?? ""),
              )}
              onChange={(event) =>
                onChange(
                  rules.map((item, itemIndex) =>
                    itemIndex === index
                      ? {
                          ...item,
                          [kind === "reference" ? "source_type" : "relation"]:
                            event.target.value,
                        }
                      : item,
                  ),
                )
              }
              className={input}
              placeholder={t(
                kind === "reference"
                  ? "mapping.source_type"
                  : "mapping.relation",
              )}
            />
            <input
              disabled={!editable}
              value={String(rule.source_field ?? rule.source_type ?? "")}
              onChange={(event) =>
                onChange(
                  rules.map((item, itemIndex) =>
                    itemIndex === index
                      ? {
                          ...item,
                          [kind === "reference"
                            ? "source_field"
                            : "source_type"]: event.target.value,
                        }
                      : item,
                  ),
                )
              }
              className={input}
              placeholder={t("mapping.source_field")}
            />
            <input
              disabled={!editable}
              value={String(rule.target_type ?? "")}
              onChange={(event) =>
                onChange(
                  rules.map((item, itemIndex) =>
                    itemIndex === index
                      ? { ...item, target_type: event.target.value }
                      : item,
                  ),
                )
              }
              className={input}
              placeholder={t("mapping.target_type")}
            />
            <div className="flex gap-2">
              {kind === "reference" && (
                <input
                  disabled={!editable}
                  value={String(rule.target_field ?? "")}
                  onChange={(event) =>
                    onChange(
                      rules.map((item, itemIndex) =>
                        itemIndex === index
                          ? { ...item, target_field: event.target.value }
                          : item,
                      ),
                    )
                  }
                  className={input}
                  placeholder={t("mapping.target_field")}
                />
              )}
              {kind === "relation" && (
                <label className="flex flex-1 items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    disabled={!editable}
                    checked={Boolean(rule.enabled)}
                    onChange={(event) =>
                      onChange(
                        rules.map((item, itemIndex) =>
                          itemIndex === index
                            ? { ...item, enabled: event.target.checked }
                            : item,
                        ),
                      )
                    }
                  />
                  {t("mapping.enabled")}
                </label>
              )}
              <button
                type="button"
                disabled={!editable}
                onClick={() =>
                  onChange(rules.filter((_, itemIndex) => itemIndex !== index))
                }
                className="rounded border border-red-800 p-2 text-red-300 disabled:opacity-40"
              >
                <X size={16} />
              </button>
            </div>
          </div>
        ))}
        {rules.length === 0 && (
          <p className="text-sm text-slate-500">
            {t(`mapping.no_${kind}_rules`)}
          </p>
        )}
      </div>
    </div>
  );
}

function RelationsStudio({
  catalog,
  rules,
  editable,
  onChange,
  t,
}: {
  catalog: Catalog;
  rules: Rule[];
  editable: boolean;
  onChange: (rules: Rule[]) => void;
  t: (key: string) => string;
}) {
  const relationNames = [
    "location_classification",
    "device_defaults",
    "customer_contacts",
    "asn_defaults",
    "circuit_terminations",
    "primary_ip",
    "nat_1to1",
  ] as const;
  const getRule = (name: string): Rule =>
    rules.find((rule) => rule.relation === name) ?? {
      id: ruleId("relation"),
      relation: name,
      enabled: false,
      settings: {},
    };
  const replaceRule = (name: string, update: (rule: Rule) => Rule) => {
    const existing = rules.findIndex((rule) => rule.relation === name);
    const next = [...rules];
    const updated = update(clone(getRule(name)));
    if (existing === -1) next.push(updated);
    else next[existing] = updated;
    onChange(next);
  };
  const locationRule = getRule("location_classification");
  const locationSettings = (
    locationRule.settings &&
    typeof locationRule.settings === "object" &&
    !Array.isArray(locationRule.settings)
      ? locationRule.settings
      : {}
  ) as Record<string, JsonValue>;
  const classifications = (
    locationSettings.locations &&
    typeof locationSettings.locations === "object" &&
    !Array.isArray(locationSettings.locations)
      ? locationSettings.locations
      : {}
  ) as Record<string, Record<string, JsonValue>>;
  const locationIdentities = catalog.source.location?.identities ?? [];
  const siteIds = locationIdentities.filter(
    (identity) => classifications[identity.source_id]?.kind === "site",
  );
  const deviceRule = getRule("device_defaults");
  const deviceSettings = (
    deviceRule.settings &&
    typeof deviceRule.settings === "object" &&
    !Array.isArray(deviceRule.settings)
      ? deviceRule.settings
      : {}
  ) as Record<string, JsonValue>;
  const categories = (
    deviceSettings.categories &&
    typeof deviceSettings.categories === "object" &&
    !Array.isArray(deviceSettings.categories)
      ? deviceSettings.categories
      : {}
  ) as Record<string, Record<string, JsonValue>>;
  const deviceOverrides = (
    deviceSettings.devices &&
    typeof deviceSettings.devices === "object" &&
    !Array.isArray(deviceSettings.devices)
      ? deviceSettings.devices
      : {}
  ) as Record<string, Record<string, JsonValue>>;
  const categoryIdentities = catalog.source.device_role?.identities ?? [];
  const deviceIdentities = catalog.source.device?.identities ?? [];
  const natRule = getRule("nat_1to1");
  const natSettings = (
    natRule.settings &&
    typeof natRule.settings === "object" &&
    !Array.isArray(natRule.settings)
      ? natRule.settings
      : {}
  ) as Record<string, JsonValue>;
  const confirmedNat = Array.isArray(natSettings.relation_ids)
    ? natSettings.relation_ids.map(String)
    : [];
  const contactRule = getRule("customer_contacts");
  const contactSettings = (
    contactRule.settings &&
    typeof contactRule.settings === "object" &&
    !Array.isArray(contactRule.settings)
      ? contactRule.settings
      : {}
  ) as Record<string, JsonValue>;
  const contactRole = (
    contactSettings.contact_role &&
    typeof contactSettings.contact_role === "object" &&
    !Array.isArray(contactSettings.contact_role)
      ? contactSettings.contact_role
      : {}
  ) as Record<string, JsonValue>;
  const asnRule = getRule("asn_defaults");
  const asnSettings = (
    asnRule.settings &&
    typeof asnRule.settings === "object" &&
    !Array.isArray(asnRule.settings)
      ? asnRule.settings
      : {}
  ) as Record<string, JsonValue>;
  const rir = (
    asnSettings.rir &&
    typeof asnSettings.rir === "object" &&
    !Array.isArray(asnSettings.rir)
      ? asnSettings.rir
      : {}
  ) as Record<string, JsonValue>;
  const terminationRule = getRule("circuit_terminations");
  const terminationSettings = (
    terminationRule.settings &&
    typeof terminationRule.settings === "object" &&
    !Array.isArray(terminationRule.settings)
      ? terminationRule.settings
      : {}
  ) as Record<string, JsonValue>;
  const confirmedCircuits = Array.isArray(terminationSettings.circuit_ids)
    ? terminationSettings.circuit_ids.map(String)
    : [];

  const setSettings = (name: string, settings: Record<string, JsonValue>) =>
    replaceRule(name, (rule) => ({ ...rule, settings }));
  return (
    <div className="grid gap-5">
      <div className={card}>
        <h2 className="text-lg font-semibold">{t("mapping.relation_rules")}</h2>
        <p className="mt-1 text-sm text-slate-400">
          {t("mapping.relation_help")}
        </p>
        <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
          {relationNames.map((name) => {
            const rule = getRule(name);
            return (
              <label
                key={name}
                className="flex items-center gap-3 rounded border border-slate-800 bg-slate-950 p-3 text-sm"
              >
                <input
                  type="checkbox"
                  disabled={!editable}
                  checked={Boolean(rule.enabled)}
                  onChange={(event) =>
                    replaceRule(name, (current) => ({
                      ...current,
                      enabled: event.target.checked,
                    }))
                  }
                />
                <span>{t(`mapping.relation.${name}`)}</span>
              </label>
            );
          })}
        </div>
      </div>

      {Boolean(locationRule.enabled) && (
        <div className={card}>
          <h2 className="text-lg font-semibold">
            {t("mapping.location_classification")}
          </h2>
          <p className="mt-1 text-sm text-slate-400">
            {t("mapping.location_help")}
          </p>
          <div className="mt-4 grid gap-3">
            {locationIdentities.map((identity) => {
              const classification = classifications[identity.source_id] ?? {};
              return (
                <div
                  key={identity.source_id}
                  className="grid items-center gap-2 rounded border border-slate-800 bg-slate-950 p-3 md:grid-cols-[1fr_160px_1fr]"
                >
                  <div>
                    <p className="font-medium">{identity.label}</p>
                    <code className="text-xs text-slate-500">
                      {identity.source_id}
                    </code>
                  </div>
                  <select
                    disabled={!editable}
                    value={String(classification.kind ?? "")}
                    onChange={(event) =>
                      setSettings("location_classification", {
                        ...locationSettings,
                        locations: {
                          ...classifications,
                          [identity.source_id]: {
                            ...classification,
                            kind: event.target.value,
                            name: identity.label,
                            approved: true,
                          },
                        },
                      })
                    }
                    className={input}
                  >
                    <option value="">{t("mapping.choose")}</option>
                    <option value="site">{t("mapping.as_site")}</option>
                    <option value="location">{t("mapping.as_location")}</option>
                  </select>
                  {classification.kind === "location" ? (
                    <select
                      disabled={!editable}
                      value={String(classification.site_source_id ?? "")}
                      onChange={(event) =>
                        setSettings("location_classification", {
                          ...locationSettings,
                          locations: {
                            ...classifications,
                            [identity.source_id]: {
                              ...classification,
                              site_source_id: event.target.value,
                            },
                          },
                        })
                      }
                      className={input}
                    >
                      <option value="">{t("mapping.choose_site")}</option>
                      {siteIds.map((site) => (
                        <option key={site.source_id} value={site.source_id}>
                          {site.label}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <input
                      disabled={!editable || classification.kind !== "site"}
                      value={String(classification.slug ?? "")}
                      onChange={(event) =>
                        setSettings("location_classification", {
                          ...locationSettings,
                          locations: {
                            ...classifications,
                            [identity.source_id]: {
                              ...classification,
                              slug: event.target.value,
                            },
                          },
                        })
                      }
                      className={input}
                      placeholder="slug"
                    />
                  )}
                </div>
              );
            })}
            {locationIdentities.length === 0 && (
              <p className="text-sm text-slate-500">
                {t("mapping.no_locations")}
              </p>
            )}
          </div>
          <div className="mt-5 grid gap-2 rounded border border-dashed border-slate-700 p-4 md:grid-cols-3">
            <input
              disabled={!editable}
              value={String(
                (
                  locationSettings.fallback_site as
                    Record<string, JsonValue> | undefined
                )?.name ?? "",
              )}
              onChange={(event) =>
                setSettings("location_classification", {
                  ...locationSettings,
                  fallback_site: {
                    ...((locationSettings.fallback_site as
                      Record<string, JsonValue> | undefined) ?? {}),
                    id: "fallback",
                    name: event.target.value,
                    approved: true,
                  },
                })
              }
              className={input}
              placeholder={t("mapping.fallback_site_name")}
            />
            <input
              disabled={!editable}
              value={String(
                (
                  locationSettings.fallback_site as
                    Record<string, JsonValue> | undefined
                )?.slug ?? "",
              )}
              onChange={(event) =>
                setSettings("location_classification", {
                  ...locationSettings,
                  fallback_site: {
                    ...((locationSettings.fallback_site as
                      Record<string, JsonValue> | undefined) ?? {}),
                    id: "fallback",
                    slug: event.target.value,
                    approved: true,
                  },
                })
              }
              className={input}
              placeholder="slug"
            />
            <p className="self-center text-xs text-slate-500">
              {t("mapping.fallback_help")}
            </p>
          </div>
        </div>
      )}

      {Boolean(deviceRule.enabled) && (
        <div className={card}>
          <h2 className="text-lg font-semibold">
            {t("mapping.device_categories")}
          </h2>
          <p className="mt-1 text-sm text-slate-400">
            {t("mapping.device_help")}
          </p>
          <div className="mt-4 grid gap-3">
            {categoryIdentities.map((identity) => {
              const category = categories[identity.source_id] ?? {};
              const manufacturer = (
                category.manufacturer &&
                typeof category.manufacturer === "object" &&
                !Array.isArray(category.manufacturer)
                  ? category.manufacturer
                  : {}
              ) as Record<string, JsonValue>;
              const deviceType = (
                category.device_type &&
                typeof category.device_type === "object" &&
                !Array.isArray(category.device_type)
                  ? category.device_type
                  : {}
              ) as Record<string, JsonValue>;
              const updateCategory = (update: Record<string, JsonValue>) =>
                setSettings("device_defaults", {
                  ...deviceSettings,
                  categories: {
                    ...categories,
                    [identity.source_id]: { ...category, ...update },
                  },
                });
              return (
                <div
                  key={identity.source_id}
                  className="grid gap-2 rounded border border-slate-800 bg-slate-950 p-3 md:grid-cols-[1fr_1fr_1fr_1fr]"
                >
                  <div>
                    <p className="font-medium">{identity.label}</p>
                    <code className="text-xs text-slate-500">
                      {identity.source_id}
                    </code>
                  </div>
                  <input
                    disabled={!editable}
                    value={String(manufacturer.name ?? "")}
                    onChange={(event) =>
                      updateCategory({
                        manufacturer: {
                          ...manufacturer,
                          name: event.target.value,
                          approved: true,
                        },
                      })
                    }
                    className={input}
                    placeholder={t("mapping.manufacturer")}
                  />
                  <input
                    disabled={!editable}
                    value={String(deviceType.model ?? "")}
                    onChange={(event) =>
                      updateCategory({
                        device_type: {
                          ...deviceType,
                          model: event.target.value,
                          approved: true,
                        },
                      })
                    }
                    className={input}
                    placeholder={t("mapping.device_type")}
                  />
                  <input
                    disabled={!editable}
                    value={String(category.interface_type ?? "")}
                    onChange={(event) =>
                      updateCategory({ interface_type: event.target.value })
                    }
                    className={input}
                    placeholder={t("mapping.interface_type")}
                  />
                </div>
              );
            })}
            {categoryIdentities.length === 0 && (
              <p className="text-sm text-slate-500">
                {t("mapping.no_device_categories")}
              </p>
            )}
          </div>
          <div className="mt-6 border-t border-slate-800 pt-5">
            <h3 className="font-semibold">{t("mapping.device_exceptions")}</h3>
            <p className="mt-1 text-sm text-slate-400">
              {t("mapping.device_exceptions_help")}
            </p>
            <div className="mt-4 grid gap-3">
              {deviceIdentities.map((identity) => {
                const override = deviceOverrides[identity.source_id] ?? {};
                const inheritedRole = identity.hints?.category_source_id ?? "";
                const effectiveRole = String(
                  override.role_source_id ?? inheritedRole,
                );
                const baseCategory = categories[effectiveRole] ?? {};
                const manufacturer = (
                  override.manufacturer &&
                  typeof override.manufacturer === "object" &&
                  !Array.isArray(override.manufacturer)
                    ? override.manufacturer
                    : baseCategory.manufacturer &&
                        typeof baseCategory.manufacturer === "object" &&
                        !Array.isArray(baseCategory.manufacturer)
                      ? baseCategory.manufacturer
                      : {}
                ) as Record<string, JsonValue>;
                const deviceType = (
                  override.device_type &&
                  typeof override.device_type === "object" &&
                  !Array.isArray(override.device_type)
                    ? override.device_type
                    : baseCategory.device_type &&
                        typeof baseCategory.device_type === "object" &&
                        !Array.isArray(baseCategory.device_type)
                      ? baseCategory.device_type
                      : {}
                ) as Record<string, JsonValue>;
                const hardwareOverride =
                  override.manufacturer !== undefined ||
                  override.device_type !== undefined;
                const updateDevice = (update: Record<string, JsonValue>) =>
                  setSettings("device_defaults", {
                    ...deviceSettings,
                    devices: {
                      ...deviceOverrides,
                      [identity.source_id]: { ...override, ...update },
                    },
                  });
                const toggleHardware = (enabled: boolean) => {
                  const next = { ...override };
                  if (enabled) {
                    next.manufacturer = {
                      ...manufacturer,
                      approved: true,
                    };
                    next.device_type = {
                      ...deviceType,
                      approved: true,
                    };
                  } else {
                    delete next.manufacturer;
                    delete next.device_type;
                  }
                  setSettings("device_defaults", {
                    ...deviceSettings,
                    devices: {
                      ...deviceOverrides,
                      [identity.source_id]: next,
                    },
                  });
                };
                return (
                  <div
                    key={identity.source_id}
                    className="grid gap-2 rounded border border-slate-800 bg-slate-950 p-3 lg:grid-cols-[1fr_180px_160px_1fr_1fr_1fr]"
                  >
                    <div>
                      <p className="font-medium">{identity.label}</p>
                      <code className="text-xs text-slate-500">
                        {identity.source_id}
                      </code>
                    </div>
                    <select
                      disabled={!editable}
                      value={effectiveRole}
                      onChange={(event) =>
                        updateDevice({ role_source_id: event.target.value })
                      }
                      className={input}
                      aria-label={t("mapping.device_role")}
                    >
                      <option value="">{t("mapping.choose")}</option>
                      {categoryIdentities.map((category) => (
                        <option
                          key={category.source_id}
                          value={category.source_id}
                        >
                          {category.label}
                        </option>
                      ))}
                    </select>
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        disabled={!editable}
                        checked={hardwareOverride}
                        onChange={(event) =>
                          toggleHardware(event.target.checked)
                        }
                      />
                      {t("mapping.override_hardware")}
                    </label>
                    <input
                      disabled={!editable || !hardwareOverride}
                      value={String(manufacturer.name ?? "")}
                      onChange={(event) =>
                        updateDevice({
                          manufacturer: {
                            ...manufacturer,
                            name: event.target.value,
                            approved: true,
                          },
                        })
                      }
                      className={input}
                      placeholder={t("mapping.override_manufacturer")}
                    />
                    <input
                      disabled={!editable || !hardwareOverride}
                      value={String(deviceType.model ?? "")}
                      onChange={(event) =>
                        updateDevice({
                          device_type: {
                            ...deviceType,
                            model: event.target.value,
                            approved: true,
                          },
                        })
                      }
                      className={input}
                      placeholder={t("mapping.override_device_type")}
                    />
                    <input
                      disabled={!editable}
                      value={String(override.interface_type ?? "")}
                      onChange={(event) =>
                        updateDevice({ interface_type: event.target.value })
                      }
                      className={input}
                      placeholder={t("mapping.override_interface_type")}
                    />
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      )}

      {Boolean(contactRule.enabled) && (
        <div className={card}>
          <h2 className="text-lg font-semibold">{t("mapping.contact_role")}</h2>
          <p className="mt-1 text-sm text-slate-400">
            {t("mapping.contact_help")}
          </p>
          <div className="mt-4 grid gap-2 md:grid-cols-2">
            <input
              disabled={!editable}
              value={String(contactRole.name ?? "")}
              onChange={(event) =>
                setSettings("customer_contacts", {
                  ...contactSettings,
                  contact_role: {
                    ...contactRole,
                    id: "customer",
                    name: event.target.value,
                    approved: true,
                  },
                })
              }
              className={input}
              placeholder={t("mapping.contact_role_name")}
            />
            <input
              disabled={!editable}
              value={String(contactRole.slug ?? "")}
              onChange={(event) =>
                setSettings("customer_contacts", {
                  ...contactSettings,
                  contact_role: {
                    ...contactRole,
                    id: "customer",
                    slug: event.target.value,
                    approved: true,
                  },
                })
              }
              className={input}
              placeholder="slug"
            />
          </div>
        </div>
      )}

      {Boolean(asnRule.enabled) && (
        <div className={card}>
          <h2 className="text-lg font-semibold">{t("mapping.rir_defaults")}</h2>
          <p className="mt-1 text-sm text-slate-400">{t("mapping.rir_help")}</p>
          <div className="mt-4 grid gap-2 md:grid-cols-[1fr_1fr_auto]">
            <input
              disabled={!editable}
              value={String(rir.name ?? "")}
              onChange={(event) =>
                setSettings("asn_defaults", {
                  ...asnSettings,
                  rir: {
                    ...rir,
                    id: "phpipam-rir",
                    name: event.target.value,
                    approved: true,
                  },
                })
              }
              className={input}
              placeholder={t("mapping.rir_name")}
            />
            <input
              disabled={!editable}
              value={String(rir.slug ?? "")}
              onChange={(event) =>
                setSettings("asn_defaults", {
                  ...asnSettings,
                  rir: {
                    ...rir,
                    id: "phpipam-rir",
                    slug: event.target.value,
                    approved: true,
                  },
                })
              }
              className={input}
              placeholder="slug"
            />
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                disabled={!editable}
                checked={Boolean(rir.is_private ?? true)}
                onChange={(event) =>
                  setSettings("asn_defaults", {
                    ...asnSettings,
                    rir: {
                      ...rir,
                      id: "phpipam-rir",
                      is_private: event.target.checked,
                      approved: true,
                    },
                  })
                }
              />
              {t("mapping.private_rir")}
            </label>
          </div>
        </div>
      )}

      {Boolean(terminationRule.enabled) && (
        <div className={card}>
          <h2 className="text-lg font-semibold">
            {t("mapping.circuit_terminations")}
          </h2>
          <p className="mt-1 text-sm text-slate-400">
            {t("mapping.circuit_help")}
          </p>
          <div className="mt-4 grid gap-2">
            {(catalog.source.circuit?.identities ?? []).map((identity) => (
              <label
                key={identity.source_id}
                className="flex items-center gap-3 rounded border border-slate-800 bg-slate-950 p-3 text-sm"
              >
                <input
                  type="checkbox"
                  disabled={!editable}
                  checked={confirmedCircuits.includes(identity.source_id)}
                  onChange={(event) => {
                    const ids = event.target.checked
                      ? [...confirmedCircuits, identity.source_id]
                      : confirmedCircuits.filter(
                          (id) => id !== identity.source_id,
                        );
                    setSettings("circuit_terminations", {
                      ...terminationSettings,
                      circuit_ids: ids,
                    });
                  }}
                />
                <span>
                  {identity.label}{" "}
                  <code className="text-slate-500">{identity.source_id}</code>
                </span>
              </label>
            ))}
          </div>
        </div>
      )}

      {Boolean(natRule.enabled) && (
        <div className={card}>
          <h2 className="text-lg font-semibold">
            {t("mapping.nat_confirmation")}
          </h2>
          <p className="mt-1 text-sm text-slate-400">{t("mapping.nat_help")}</p>
          <div className="mt-4 grid gap-2">
            {(catalog.source.nat?.identities ?? []).map((identity) => (
              <label
                key={identity.source_id}
                className="flex items-center gap-3 rounded border border-slate-800 bg-slate-950 p-3 text-sm"
              >
                <input
                  type="checkbox"
                  disabled={!editable}
                  checked={confirmedNat.includes(identity.source_id)}
                  onChange={(event) => {
                    const ids = event.target.checked
                      ? [...confirmedNat, identity.source_id]
                      : confirmedNat.filter((id) => id !== identity.source_id);
                    setSettings("nat_1to1", {
                      ...natSettings,
                      confirmed: true,
                      relation_ids: ids,
                    });
                  }}
                />
                <span>
                  {identity.label}{" "}
                  <code className="text-slate-500">{identity.source_id}</code>
                </span>
              </label>
            ))}
          </div>
        </div>
      )}

      <details className={card}>
        <summary className="cursor-pointer text-sm text-sky-300">
          {t("mapping.advanced_relations")}
        </summary>
        <div className="mt-4">
          <RulesEditor
            kind="relation"
            rules={rules}
            editable={editable}
            onChange={onChange}
            t={t}
          />
        </div>
      </details>
    </div>
  );
}

function StatusUpdates({
  mapping,
  rules,
  catalog,
  editable,
  onChange,
  t,
}: {
  mapping: Mapping;
  rules: Rule[];
  catalog: Catalog;
  editable: boolean;
  onChange: (mapping: Mapping) => void;
  t: (key: string) => string;
}) {
  const add = () =>
    onChange({
      ...mapping,
      status_rules: [
        ...rules,
        {
          id: ruleId("status"),
          source_type: "ip_address",
          source_value: "*",
          target_value: "active",
        },
      ],
    });
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <div className={card}>
        <div className="flex justify-between">
          <h2 className="text-lg font-semibold">{t("mapping.status_rules")}</h2>
          <button
            type="button"
            disabled={!editable}
            onClick={add}
            className="rounded border border-sky-500 p-2 text-sky-300 disabled:opacity-40"
          >
            <Plus size={17} />
          </button>
        </div>
        <div className="mt-4 grid gap-3">
          {rules.map((rule, index) => {
            const sourceType = String(rule.source_type ?? "");
            const targetValue = String(rule.target_value ?? "");
            const choices = (catalog.target_choices[sourceType]?.status ?? [])
              .map(netBoxChoice)
              .filter(
                (choice): choice is { value: string; label: string } =>
                  choice !== null,
              );
            const statusTypes = Object.entries(catalog.target_choices)
              .filter(([, fields]) => (fields.status ?? []).length > 0)
              .map(([type]) => type);
            return (
              <div
                key={rule.id}
                className="grid gap-2 rounded border border-slate-800 bg-slate-950 p-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
              >
                <select
                  disabled={!editable}
                  value={sourceType}
                  onChange={(event) =>
                    onChange({
                      ...mapping,
                      status_rules: rules.map((item, itemIndex) =>
                        itemIndex === index
                          ? { ...item, source_type: event.target.value }
                          : item,
                      ),
                    })
                  }
                  className={input}
                >
                  {!statusTypes.includes(sourceType) && (
                    <option value={sourceType}>{sourceType}</option>
                  )}
                  {statusTypes.map((type) => (
                    <option key={type} value={type}>
                      {type}
                    </option>
                  ))}
                </select>
                <input
                  disabled={!editable}
                  value={String(rule.source_value ?? "")}
                  onChange={(event) =>
                    onChange({
                      ...mapping,
                      status_rules: rules.map((item, itemIndex) =>
                        itemIndex === index
                          ? { ...item, source_value: event.target.value }
                          : item,
                      ),
                    })
                  }
                  className={input}
                />
                <select
                  disabled={!editable}
                  value={targetValue}
                  onChange={(event) =>
                    onChange({
                      ...mapping,
                      status_rules: rules.map((item, itemIndex) =>
                        itemIndex === index
                          ? { ...item, target_value: event.target.value }
                          : item,
                      ),
                    })
                  }
                  className={input}
                >
                  {!choices.some((choice) => choice.value === targetValue) && (
                    <option value={targetValue}>{targetValue}</option>
                  )}
                  {choices.map((choice) => (
                    <option key={choice.value} value={choice.value}>
                      {choice.label}
                    </option>
                  ))}
                </select>
                <button
                  type="button"
                  disabled={!editable}
                  onClick={() =>
                    onChange({
                      ...mapping,
                      status_rules: rules.filter(
                        (_, itemIndex) => itemIndex !== index,
                      ),
                    })
                  }
                  className="rounded border border-red-800 p-2 text-red-300 disabled:opacity-40"
                >
                  <X size={16} />
                </button>
              </div>
            );
          })}
        </div>
      </div>
      <div className={card}>
        <h2 className="text-lg font-semibold">{t("mapping.update_rules")}</h2>
        <p className="mt-1 text-sm text-slate-400">
          {t("mapping.update_help")}
        </p>
        <div className="mt-4 grid gap-3">
          {Object.entries(catalog.natural_keys).map(([type]) => (
            <label key={type} className="block text-sm">
              <span className="text-slate-300">{type}</span>
              <input
                disabled={!editable}
                value={(mapping.update_rules?.[type] ?? []).join(", ")}
                onChange={(event) =>
                  onChange({
                    ...mapping,
                    update_rules: {
                      ...(mapping.update_rules ?? {}),
                      [type]: event.target.value
                        .split(",")
                        .map((value) => value.trim())
                        .filter(Boolean),
                    },
                  })
                }
                className={`${input} mt-1`}
                placeholder={t("mapping.update_fields")}
              />
            </label>
          ))}
        </div>
      </div>
    </div>
  );
}

function PreviewPanel({
  project,
  preview,
  editable,
  dirty,
  setPreview,
  t,
}: {
  project: Project;
  preview: Preview | null;
  editable: boolean;
  dirty: boolean;
  setPreview: (preview: Preview | null) => void;
  t: (key: string, options?: Record<string, unknown>) => string;
}) {
  const queue = () =>
    router.post(
      `/projects/${project.id}/mapping/preview`,
      {},
      {
        preserveScroll: true,
        onSuccess: (page) =>
          setPreview(
            (page.props as unknown as { latestPreview?: Preview })
              .latestPreview ?? null,
          ),
      },
    );
  const result = preview?.result;
  return (
    <div className={card}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">{t("mapping.preview")}</h2>
          <p className="text-sm text-slate-400">{t("mapping.preview_help")}</p>
          {dirty && (
            <p className="mt-2 text-sm text-amber-300">
              {t("mapping.preview_save_first")}
            </p>
          )}
        </div>
        <button
          type="button"
          disabled={
            !editable ||
            dirty ||
            preview?.status === "queued" ||
            preview?.status === "running"
          }
          onClick={queue}
          className="inline-flex items-center gap-2 rounded border border-sky-500 px-4 py-2 text-sky-300 disabled:opacity-40"
        >
          <CloudCog size={17} />
          {t("mapping.run_preview")}
        </button>
      </div>
      {preview && (
        <div className="mt-5">
          <p className="text-sm text-slate-300">
            {t("mapping.preview_status")}:{" "}
            {t(`mapping.preview_state.${preview.status}`)}
          </p>
          {preview.last_error && (
            <p className="mt-3 text-red-300">{preview.last_error}</p>
          )}
          {result && (
            <>
              <div className="mt-4 grid gap-3 sm:grid-cols-3">
                <Metric
                  label={t("mapping.actions")}
                  value={result.summary.actions}
                />
                <Metric
                  label={t("mapping.conflicts")}
                  value={result.summary.conflicts}
                />
                <Metric
                  label={t("mapping.warnings")}
                  value={result.summary.warnings}
                />
              </div>
              {result.conflicts.length > 0 && (
                <details className="mt-4 rounded border border-red-900 bg-red-950/30 p-3 text-sm text-red-100">
                  <summary className="cursor-pointer font-medium">
                    {t("show.conflicts")}
                  </summary>
                  <ul className="mt-3 list-disc space-y-1 pl-5" data-testid="preview-conflicts">
                    {result.conflicts.map((conflict, index) => (
                      <li key={`${String(conflict.reason ?? "conflict")}-${index}`}>
                        {String(conflict.reason ?? "unknown_conflict")}
                      </li>
                    ))}
                  </ul>
                </details>
              )}
              <p className="mt-4 rounded border border-amber-800 bg-amber-950/30 p-3 text-sm text-amber-200">
                {t("mapping.preview_non_applicable")}
              </p>
              <div className="mt-4 grid gap-4 md:grid-cols-2">
                <RecordList
                  title={t("mapping.coverage")}
                  values={result.coverage.source}
                />
                <RecordList
                  title={t("mapping.preserved")}
                  values={result.coverage.preserved}
                />
              </div>
            </>
          )}
        </div>
      )}
      {!preview && (
        <p className="mt-5 text-sm text-slate-500">{t("mapping.no_preview")}</p>
      )}
    </div>
  );
}

function CatalogBrowser({
  catalog,
  t,
}: {
  catalog: Record<string, CatalogType>;
  t: (key: string) => string;
}) {
  return (
    <aside className={card}>
      <h2 className="text-lg font-semibold">{t("mapping.catalog")}</h2>
      <p className="mt-1 text-sm text-slate-400">{t("mapping.catalog_help")}</p>
      <div className="mt-4 max-h-[680px] space-y-3 overflow-auto">
        {Object.entries(catalog).map(([type, definition]) => (
          <details
            key={type}
            className="rounded border border-slate-800 bg-slate-950 p-3"
          >
            <summary className="cursor-pointer font-medium">
              {type}{" "}
              <span className="text-xs text-slate-500">
                ({definition.count})
              </span>
            </summary>
            <div className="mt-3 space-y-2">
              {Object.entries(definition.fields).map(([field, stats]) => (
                <div
                  key={field}
                  className="border-t border-slate-800 pt-2 text-xs"
                >
                  <p className="font-mono text-sky-300">{field}</p>
                  <p className="text-slate-500">
                    {stats.type} · {Math.round(stats.fill_rate * 100)}% ·{" "}
                    {stats.cardinality}
                    {stats.cardinality_limited ? "+" : ""}
                  </p>
                  {stats.examples.length > 0 && (
                    <p className="mt-1 break-words text-slate-400">
                      {stats.examples.join(" · ")}
                    </p>
                  )}
                </div>
              ))}
            </div>
          </details>
        ))}
      </div>
    </aside>
  );
}

function Metric({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded border border-slate-800 bg-slate-950 p-4">
      <p className="text-sm text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-bold">{value}</p>
    </div>
  );
}

function netBoxChoice(
  choice: unknown,
): { value: string; label: string } | null {
  if (typeof choice === "string" || typeof choice === "number") {
    const value = String(choice);
    return { value, label: value };
  }
  if (choice === null || typeof choice !== "object" || Array.isArray(choice)) {
    return null;
  }
  const record = choice as Record<string, unknown>;
  if (typeof record.value !== "string" && typeof record.value !== "number") {
    return null;
  }
  const value = String(record.value);
  const label =
    typeof record.display_name === "string"
      ? record.display_name
      : typeof record.label === "string"
        ? record.label
        : value;
  return { value, label };
}

function RecordList({
  title,
  values,
}: {
  title: string;
  values: Record<string, number>;
}) {
  return (
    <div>
      <h3 className="font-medium">{title}</h3>
      <dl className="mt-2 space-y-1 text-sm">
        {Object.entries(values).map(([key, value]) => (
          <div
            key={key}
            className="flex justify-between border-b border-slate-800 py-1"
          >
            <dt>{key}</dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
