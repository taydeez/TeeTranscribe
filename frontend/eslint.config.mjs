import withNuxt from './.nuxt/eslint.config.mjs'

export default withNuxt({
  ignores: [
    '.nuxt/**',
    '.output/**',
    'dist/**',
    'node_modules/**',
  ],
  rules: {
    'no-console': ['warn', { allow: ['log', 'warn', 'error'] }],
    'no-debugger': 'error',
    'vue/component-name-in-template-casing': ['error', 'PascalCase'],
    'vue/no-mutating-props': 'error',
    'vue/no-multiple-template-root': 'off',
    'vue/no-unused-components': 'error',
  },
})
