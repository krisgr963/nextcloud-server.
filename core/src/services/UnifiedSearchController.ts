/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { search as unifiedSearch } from './UnifiedSearchService.js'

type CategorySearchStatus = 'loading' | 'loaded' | 'failed' | 'blocked'

interface CategorySearchState {
	status: CategorySearchStatus
	entries: unknown[]
	cursor: string | null
	hasMore: boolean
	loadMoreFailed: boolean
}

export const REVEAL_INTERVAL_MS = 1500

/**
 * Runs a unified search across categories in priority order, blocking
 * lower-priority results until their predecessors arrive or a timer reveals them.
 */
export class UnifiedSearchController {
	private query: string = ''
	private searchStates: Record<string, CategorySearchState> = {}
	private searchGeneration: number = 0
	private revealTimer: ReturnType<typeof setTimeout> | null = null
	private pendingCancels: (() => void)[] = []

	/**
	 * Start a search. Cancels and replaces any search already in flight.
	 *
	 * @param query the search term
	 * @param categories category ids in priority order
	 * @return resolves once every category has settled
	 */
	async search(query: string, categories: string[]): Promise<void> {
		this.cancelPendingRequests()
		this.searchStates = {}
		this.searchGeneration++
		const generation = this.searchGeneration
		this.query = query

		this.startRevealTimer()

		await Promise.allSettled(categories.map((category) => this.searchCategory(category, generation, categories)))
	}

	/**
	 * Fetch the next page for one category and append it. A no-op unless the
	 * category is loaded with more pages. On failure the existing results stay
	 * and `loadMoreFailed` is raised, so calling again retries.
	 *
	 * @param category the category id to page
	 */
	async loadMore(category: string): Promise<void> {
		const generation = this.searchGeneration
		const categoryState = this.searchStates[category]
		if (!categoryState || !categoryState.hasMore || categoryState.status !== 'loaded') {
			return
		}
		categoryState.status = 'loading'
		categoryState.loadMoreFailed = false

		const { request, cancel } = unifiedSearch({
			type: category,
			query: this.query,
			cursor: categoryState.cursor,
		})

		this.pendingCancels.push(cancel)

		try {
			const response = await request()
			if (this.searchGeneration !== generation) {
				return
			}
			const { entries, cursor, hasMore } = response.data.ocs.data
			categoryState.entries.push(...entries)
			categoryState.cursor = cursor
			categoryState.hasMore = hasMore
		} catch {
			if (this.searchGeneration !== generation) {
				return
			}
			categoryState.loadMoreFailed = true
		}

		categoryState.status = 'loaded'
	}

	/**
	 * A shallow copy of the current per-category state, safe to read for rendering.
	 *
	 * @return the current search states keyed by category id
	 */
	getSnapshot(): Record<string, CategorySearchState> {
		return { ...this.searchStates }
	}

	/**
	 * Tear down on unmount: cancels in-flight requests and stops the reveal timer.
	 */
	dispose(): void {
		this.cancelPendingRequests()
		this.stopRevealTimer()
	}

	private async searchCategory(category: string, generation: number, categories: string[]): Promise<void> {
		this.searchStates[category] = {
			status: 'loading',
			entries: [],
			cursor: null,
			hasMore: false,
			loadMoreFailed: false,
		}
		const { request, cancel } = unifiedSearch({
			type: category,
			query: this.query,
			cursor: null,
		})

		this.pendingCancels.push(cancel)

		try {
			const response = await request()
			if (this.searchGeneration !== generation) {
				// A new search has been started, ignore this result
				return
			}

			const { entries, cursor, hasMore } = response.data.ocs.data
			this.searchStates[category] = {
				status: 'loaded',
				entries,
				cursor,
				hasMore,
				loadMoreFailed: false,
			}
		} catch {
			if (this.searchGeneration !== generation) {
				return
			}
			this.searchStates[category] = {
				status: 'failed',
				entries: [],
				cursor: null,
				hasMore: false,
				loadMoreFailed: false,
			}
		}

		this.reconcileCategoryStatuses(categories)
	}

	private reconcileCategoryStatuses(categories: string[]): void {
		categories.forEach((category) => {
			if (['loading', 'failed'].includes(this.searchStates[category].status)) {
				return
			}
			this.searchStates[category].status = this.shouldBlockCategory(category, categories) ? 'blocked' : 'loaded'
		})
	}

	private startRevealTimer(): void {
		this.stopRevealTimer()
		this.revealTimer = setTimeout(() => {
			const categories = Object.keys(this.searchStates)
			const hasPendingCategories = categories.some((category) => ['loading', 'blocked'].includes(this.searchStates[category].status))
			this.unblockAllCategories(categories)
			if (hasPendingCategories) {
				this.startRevealTimer()
			}
		}, REVEAL_INTERVAL_MS)
	}

	private stopRevealTimer(): void {
		if (this.revealTimer) {
			clearTimeout(this.revealTimer)
			this.revealTimer = null
		}
	}

	private cancelPendingRequests(): void {
		this.pendingCancels.forEach((cancel) => cancel())
		this.pendingCancels = []
	}

	private unblockAllCategories(categories: string[]): void {
		categories.forEach((category) => {
			if (this.searchStates[category].status === 'blocked') {
				this.searchStates[category].status = 'loaded'
			}
		})
	}

	private shouldBlockCategory(category: string, categories: string[]): boolean {
		if (!this.searchStates[category]) {
			return false
		}

		return categories.slice(0, categories.indexOf(category)).some((c) => {
			const categoryState = this.searchStates[c]
			return categoryState && ['loading', 'blocked'].includes(categoryState.status)
		})
	}
}
