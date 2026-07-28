import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
    testDir: './research',
    testMatch: 'phase27-wordpress.spec.ts',
    outputDir: './artifacts/phase27-wordpress-results',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    reporter: [ [ 'list' ] ],
    globalSetup: require.resolve( './global-setup' ),
    use: {
        baseURL: process.env.WP_BASE_URL ?? 'https://localhost:9443',
        headless: true,
        ignoreHTTPSErrors: true,
        storageState: './tests/e2e/artifacts/storage-states/admin.json',
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
