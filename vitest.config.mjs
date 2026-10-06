import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	plugins: [vue()],
	test: {
		include: ['src/**/*.test.js'],
		environment: 'jsdom',
		server: {
			deps: {
				inline: [/@nextcloud\//],
			},
		},
	},
})
