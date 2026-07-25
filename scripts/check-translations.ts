import { localeCodes, resources } from '../resources/js/i18n';

const baseKeys = Object.keys(resources.en.translation).sort();
for (const locale of localeCodes) {
  const messages = resources[locale].translation as Record<string, string>;
  if (JSON.stringify(Object.keys(messages).sort()) !== JSON.stringify(baseKeys)) throw new Error(`Translation keys differ for ${locale}.`);
  for (const key of baseKeys) {
    if (!messages[key]?.trim()) throw new Error(`Empty translation: ${key} (${locale}).`);
    const base = [...resources.en.translation[key].matchAll(/{{\s*([^}\s]+)\s*}}/g)].map(match => match[1]).sort().join(',');
    const next = [...messages[key].matchAll(/{{\s*([^}\s]+)\s*}}/g)].map(match => match[1]).sort().join(',');
    if (base !== next) throw new Error(`Interpolation mismatch: ${key} (${locale}).`);
  }
}
process.stdout.write(`${baseKeys.length} translation keys validated in ${localeCodes.length} locales.\n`);
