// Configuração Playwright do SGI. URLs vêm de variáveis de ambiente definidas por run-all.sh.
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  timeout: 180000,
  expect: { timeout: 15000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['json', { outputFile: process.env.SGI_REPORT || 'results/resultado.json' }]],
  outputDir: process.env.SGI_ARTEFACTOS || 'results/artefactos',
  use: {
    baseURL: process.env.SGI_URL || 'http://127.0.0.1:8090/sgi/',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'pt-PT',
    timezoneId: 'Africa/Luanda',
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {},
  },
});
