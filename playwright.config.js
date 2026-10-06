import { defineConfig } from '@playwright/test'

// End-to-end tests against the local dev instance (dev/compose.yaml). Never N5.
export default defineConfig({
	testDir: './e2e',
	timeout: 90_000,
	workers: 1,
	use: {
		baseURL: 'http://127.0.0.1:8180',
		viewport: { width: 1280, height: 860 },
		screenshot: 'only-on-failure',
	},
	reporter: [['list']],
})
