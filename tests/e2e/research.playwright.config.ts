import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
    testDir: './research',
    outputDir: './artifacts/research-test-results',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 30_000,
    reporter: [ [ 'list' ] ],
    use: {
        headless: true,
        viewport: { width: 1280, height: 900 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices[ 'Desktop Chrome' ] },
        },
    ],
} );
