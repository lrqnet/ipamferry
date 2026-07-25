import eslint from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import globals from 'globals';
import typescript from 'typescript-eslint';

export default typescript.config(
  {
    ignores: ['node_modules', 'public/build', 'storage', 'vendor'],
  },
  eslint.configs.recommended,
  ...typescript.configs.recommended,
  {
    files: ['resources/js/**/*.{ts,tsx}'],
    languageOptions: {
      globals: globals.browser,
    },
    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
    },
  },
  {
    files: ['*.{js,ts}', 'scripts/**/*.ts', 'tests/E2E/**/*.ts'],
    languageOptions: {
      globals: globals.node,
    },
  },
);
