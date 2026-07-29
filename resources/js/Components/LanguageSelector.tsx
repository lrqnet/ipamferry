import { router, usePage } from "@inertiajs/react";
import { Check, ChevronDown, Globe2, LoaderCircle } from "lucide-react";
import { useState } from "react";
import { isLocaleCode, type LocaleCode, useI18n } from "../i18n";

type Option = { value: LocaleCode; label: string };

export function LanguageSelector() {
  const { locale, availableLocales } = usePage().props as {
    locale?: unknown;
    availableLocales?: Option[];
  };
  const { t, changeLanguage } = useI18n();
  const [updating, setUpdating] = useState(false);
  const [open, setOpen] = useState(false);
  const current = isLocaleCode(locale) ? locale : "en";
  const options = Array.isArray(availableLocales) ? availableLocales : [];
  const update = (next: LocaleCode) => {
    if (next === current || updating) return;
    setOpen(false);
    setUpdating(true);
    router.put(
      "/locale",
      { locale: next },
      {
        preserveScroll: true,
        onSuccess: () => void changeLanguage(next),
        onFinish: () => setUpdating(false),
      },
    );
  };
  const selected = options.find((option) => option.value === current);
  return (
    <div className="relative" aria-busy={updating}>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        disabled={updating}
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={t("language.change")}
        className="inline-flex items-center gap-2 rounded border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium hover:bg-slate-800 disabled:opacity-70"
      >
        {updating ? (
          <LoaderCircle className="size-4 animate-spin" />
        ) : (
          <Globe2 className="size-4" />
        )}
        <span>{selected?.label ?? "English"}</span>
        <ChevronDown className="size-4" />
      </button>
      {open && (
        <div
          role="menu"
          className="absolute right-0 z-20 mt-2 min-w-48 overflow-hidden rounded border border-slate-700 bg-slate-900 py-1 shadow-xl"
        >
          {options.map((option) => (
            <button
              type="button"
              role="menuitem"
              key={option.value}
              onClick={() => update(option.value)}
              disabled={updating}
              className="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-slate-800 disabled:opacity-70"
            >
              <span className="flex-1">{option.label}</span>
              {option.value === current && (
                <Check className="size-4 text-sky-300" />
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
