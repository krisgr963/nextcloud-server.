/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const mobile = ref(false)
vi.mock('@nextcloud/vue/composables/useIsMobile', () => ({
	useIsSmallMobile: () => mobile,
	useIsMobile: () => ref(false),
}))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: vi.fn(), emit: vi.fn() }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: vi.fn(() => ([])) }))
vi.mock('../../services/UnifiedSearchService.js', () => ({
	getProviders: vi.fn(() => Promise.resolve([])),
	getContacts: vi.fn(() => Promise.resolve([])),
	search: vi.fn(() => ({ request: Promise.resolve({ data: { ocs: { data: { entries: [] } } } }), cancel: vi.fn() })),
}))
vi.mock('../../store/unified-search-external-filters.js', () => ({
	useSearchStore: () => ({ externalFilters: [], scopeToApp: false }),
}))

import UnifiedSearchModal from '../../components/UnifiedSearch/UnifiedSearchModal.vue'

function factory(open = true) {
	return shallowMount(UnifiedSearchModal, {
		propsData: { open, query: '', localSearch: false },
		global: { mocks: { t: (_: string, s: string) => s, n: (_: string, s: string) => s } },
	})
}

beforeEach(() => {
	mobile.value = false
})
afterEach(() => vi.clearAllMocks())

describe('UnifiedSearchModal mobile input', () => {
	it('renders the search field only on mobile', () => {
		mobile.value = true
		expect(factory().findComponent({ name: 'NcTextField' }).exists()).toBe(true)
	})

	it('has no in-modal search field on desktop', () => {
		expect(factory().findComponent({ name: 'NcTextField' }).exists()).toBe(false)
	})

	it('mobile close button requests close', async () => {
		mobile.value = true
		const wrapper = factory()
		wrapper.find('.unified-search-modal__mobile-input').findComponent({ name: 'NcButton' }).vm.$emit('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false])
	})

	// Guards the kebab event binding: Vue 2.7 does not normalize v-on names, so a
	// camelCase listener would silently miss NcTextField's `trailing-button-click`.
	it('mobile clear (trailing) button empties the query', async () => {
		mobile.value = true
		const wrapper = factory()
		wrapper.vm.searchQuery = 'hello'
		await wrapper.vm.$nextTick()
		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('trailing-button-click')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.searchQuery).toBe('')
	})

	it('onMobileSearchInput normalises the emitted value to a string', () => {
		mobile.value = true
		const wrapper = factory()
		wrapper.vm.onMobileSearchInput(5)
		expect(wrapper.vm.searchQuery).toBe('5')
	})
})
