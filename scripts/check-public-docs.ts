import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const sets = [
  ['README.md', 'docs/pt-BR/README.md', 'docs/es/README.md'],
  ['CHANGELOG.md', 'docs/pt-BR/CHANGELOG.md', 'docs/es/CHANGELOG.md'],
  ['docs/README.md', 'docs/pt-BR/DOCUMENTATION.md', 'docs/es/DOCUMENTATION.md'],
  ['docs/ARCHITECTURE.md', 'docs/pt-BR/ARCHITECTURE.md', 'docs/es/ARCHITECTURE.md'],
  ['docs/DEVELOPMENT.md', 'docs/pt-BR/DEVELOPMENT.md', 'docs/es/DEVELOPMENT.md'],
  ['docs/MAPPING-STUDIO.md', 'docs/pt-BR/MAPPING-STUDIO.md', 'docs/es/MAPPING-STUDIO.md'],
  ['docs/RELEASE.md', 'docs/pt-BR/RELEASE.md', 'docs/es/RELEASE.md'],
  ['docs/PLAN.md', 'docs/pt-BR/PLAN.md', 'docs/es/PLAN.md'],
  ['docs/adr/0001-immutable-plans-api-only.md', 'docs/pt-BR/adr/0001-immutable-plans-api-only.md', 'docs/es/adr/0001-immutable-plans-api-only.md'],
  ['docs/adr/0002-mapping-v2-plan-v3.md', 'docs/pt-BR/adr/0002-mapping-v2-plan-v3.md', 'docs/es/adr/0002-mapping-v2-plan-v3.md'],
  ['docs/adr/0003-cli-password-recovery.md', 'docs/pt-BR/adr/0003-cli-password-recovery.md', 'docs/es/adr/0003-cli-password-recovery.md'],
] as const;

const root = process.cwd();
let failed = false;

for (const set of sets) {
  for (const file of set) {
    const absolute = resolve(root, file);
    if (!existsSync(absolute)) {
      console.error(`Missing public documentation mirror: ${file}`);
      failed = true;
      continue;
    }

    const content = readFileSync(absolute, 'utf8');
    const links = [...content.matchAll(/\[[^\]]+\]\(([^)]+)\)/g)].map((match) => match[1]);
    const languageLinks = links.filter((link) => /(?:README\.md|DOCUMENTATION\.md|CHANGELOG\.md|ARCHITECTURE\.md|DEVELOPMENT\.md|MAPPING-STUDIO\.md|RELEASE\.md|PLAN\.md|000[1-3]-.*\.md)$/.test(link));
    if (languageLinks.length < 3) {
      console.error(`Missing language bar links: ${file}`);
      failed = true;
      continue;
    }

    for (const link of languageLinks.slice(0, 3)) {
      if (!existsSync(resolve(dirname(absolute), link))) {
        console.error(`Broken language link in ${file}: ${link}`);
        failed = true;
      }
    }
  }
}

if (failed) process.exit(1);

console.log(`Validated ${sets.length} English, Portuguese (Brazil), and Spanish documentation sets.`);
