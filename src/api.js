import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'

const base = '/apps/bettersharing/api/v1'

/** Error whose message can be shown to the user as is. */
export class ApiError extends Error {}

async function call(method, path, data, params) {
	try {
		const response = await axios.request({
			method,
			url: generateOcsUrl(base + path),
			data,
			params,
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		return response.data.ocs.data
	} catch (error) {
		const message = error?.response?.data?.ocs?.data?.message
		throw new ApiError(message || t('bettersharing', 'Could not reach the server. Check your connection and try again.'))
	}
}

export const api = {
	status: () => call('GET', '/status'),
	searchRecipients: (search) => call('GET', '/recipients', undefined, { search }),
	presets: () => call('GET', '/presets'),
	createPreset: (data) => call('POST', '/presets', data),
	updatePreset: (id, data) => call('PUT', `/presets/${id}`, data),
	deletePreset: (id) => call('DELETE', `/presets/${id}`),
	createBundle: (data) => call('POST', '/bundles', data),
	bundles: (fileId) => call('GET', '/bundles', undefined, { fileId }),
	bundle: (id) => call('GET', `/bundles/${id}`),
	updateBundle: (id, data) => call('PUT', `/bundles/${id}`, data),
	retry: (id) => call('POST', `/bundles/${id}/retry`),
	stopSharing: (id, versions) => call('POST', `/bundles/${id}/stop`, { versions }),
	removeRecipient: (id, recipientId, version) => call('POST', `/bundles/${id}/recipients/${recipientId}/remove`, { version }),
	newPassword: (id, recipientId) => call('POST', `/bundles/${id}/recipients/${recipientId}/password`),
	access: (fileId) => call('GET', '/access', undefined, { fileId }),
}
