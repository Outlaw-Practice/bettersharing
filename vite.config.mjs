import { createAppConfig } from '@nextcloud/vite-config'

// Builds js/bettersharing-init.mjs (loaded as an init script in the Files app)
// and its lazily loaded chunks.
export default createAppConfig({
	init: 'src/init.js',
}, {
	inlineCSS: false,
	extractLicenseInformation: false,
})
