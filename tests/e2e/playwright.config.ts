import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:8889';

export default defineConfig( {
    testDir: './specs',
    outputDir: './artifacts/test-results',
    snapshotPathTemplate: '{testDir}/{testFileDir}/__snapshots__/{arg}-{projectName}{ext}',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 2 : 0,
    timeout: 60_000,
    forbidOnly: !! process.env.CI,
    reporter: process.env.CI
        ? [ [ 'github' ], [ 'html', { open: 'never' } ] ]
        : [ [ 'list' ] ],
    globalSetup: require.resolve( './global-setup' ),
    use: {
        baseURL,
        headless: true,
        viewport: { width: 1280, height: 900 },
        locale: 'en-US',
        storageState: './tests/e2e/artifacts/storage-states/admin.json',
        actionTimeout: 15_000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            testIgnore: [ '**/visual/**' ],
            use: { ...devices[ 'Desktop Chrome' ] },
        },
        {
            name: 'chromium-visual',
            testMatch: [ '**/visual/**/*.spec.ts' ],
            use: { ...devices[ 'Desktop Chrome' ] },
        },
    ],
} );
