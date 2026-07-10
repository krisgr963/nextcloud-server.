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
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn() }))

import UnifiedSearch from '../../views/UnifiedSearch.vue'

function factory() {
	return shallowMount(UnifiedSearch, {
		global: { mocks: { t: (_: string, s: string) => s, OCP: {} } },
	})
}

beforeEach(() => {
	mobile.value = false
	window.OCP = { Accessibility: { disableKeyboardShortcuts: () => true } }
})
afterEach(() => vi.clearAllMocks())

describe('UnifiedSearch open-state model', () => {
	it('desktop: typing opens, clearing closes', async () => {
		const wrapper = factory()
		wrapper.vm.queryText = 'abc'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showUnifiedSearch).toBe(true)
		wrapper.vm.queryText = ''
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showUnifiedSearch).toBe(false)
	})

	it('mobile: typing does NOT open the modal', async () => {
		mobile.value = true
		const wrapper = factory()
		wrapper.vm.queryText = 'abc'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showUnifiedSearch).toBe(false)
	})

	it('mobile: header button click opens the modal', async () => {
		mobile.value = true
		const wrapper = factory()
		wrapper.findComponent({ name: 'UnifiedSearchInput' }).vm.$emit('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showUnifiedSearch).toBe(true)
	})

	it('mobile: clearing the query does NOT close an open modal', async () => {
		mobile.value = true
		const wrapper = factory()
		wrapper.vm.showUnifiedSearch = true
		wrapper.vm.queryText = ''
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showUnifiedSearch).toBe(true)
	})
})
