import { json } from "@codemirror/lang-json";
import CodeMirror from "@uiw/react-codemirror";

export default function JsonExpertEditor({
  value,
  dirty,
  editable,
  onChange,
  onApply,
  onDiscard,
  t,
}: {
  value: string;
  dirty: boolean;
  editable: boolean;
  onChange: (value: string) => void;
  onApply: () => void;
  onDiscard: () => void;
  t: (key: string) => string;
}) {
  return (
    <div className="rounded-xl border border-slate-800 bg-slate-900 p-5">
      <h2 className="text-lg font-semibold">{t("mapping.json_expert")}</h2>
      <p className="mt-1 text-sm text-slate-400">{t("mapping.json_help")}</p>
      <div className="mt-4 overflow-hidden rounded border border-slate-700">
        <CodeMirror
          value={value}
          height="620px"
          extensions={[json()]}
          theme="dark"
          editable={editable}
          onChange={onChange}
          basicSetup={{
            lineNumbers: true,
            foldGutter: true,
            autocompletion: true,
            bracketMatching: true,
            closeBrackets: true,
            highlightActiveLine: true,
          }}
        />
      </div>
      <div className="mt-4 flex gap-2">
        <button
          type="button"
          disabled={!editable || !dirty}
          onClick={onApply}
          className="rounded bg-sky-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-40"
        >
          {t("mapping.apply_json")}
        </button>
        <button
          type="button"
          disabled={!dirty}
          onClick={onDiscard}
          className="rounded border border-slate-600 px-4 py-2 text-slate-200 disabled:opacity-40"
        >
          {t("mapping.discard_json")}
        </button>
      </div>
    </div>
  );
}
