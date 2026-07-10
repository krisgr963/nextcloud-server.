/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { REVEAL_INTERVAL_MS, UnifiedSearchController } from '../../services/UnifiedSearchController.ts'

const service = vi.hoisted(() => ({
	search: vi.fn(),
	getProviders: vi.fn(),
	getContacts: vi.fn(),
}))
vi.mock('../../services/UnifiedSearchService.js', () => service)

/**
 * Deferred stand-in for a provider's `search()` return value. Resolve it to
 * make that provider arrive on demand.
 */
function deferredProvider() {
	const { promise, resolve, reject } = Promise.withResolvers<{ entries: unknown[] }>()
	return {
		cancel: vi.fn(),
		request: async () => {
			const { entries } = await promise
			return { data: { ocs: { data: { entries } } } }
		},
		resolve: (entries: unknown[] = []) => resolve({ entries }),
		reject,
	}
}

/**
 * Deferred stand-in that serves successive pages. Each `request()` call takes
 * the next page; a test resolves page N on demand with `resolvePage(n, data)`,
 * where `data` is the full `{ entries, cursor, hasMore }` payload.
 */
function pagedProvider() {
	const pages: ReturnType<typeof Promise.withResolvers<{ entries: unknown[], cursor: string | null, hasMore: boolean }>>[] = []
	const pageAt = (index: number) => (pages[index] ??= Promise.withResolvers())
	let call = 0
	return {
		cancel: vi.fn(),
		request: async () => {
			const data = await pageAt(call++).promise
			return { data: { ocs: { data } } }
		},
		resolvePage: (index: number, data: { entries: unknown[], cursor: string | null, hasMore: boolean }) => pageAt(index).resolve(data),
		rejectPage: (index: number, reason?: unknown) => pageAt(index).reject(reason),
	}
}

/**
 * Register one deferred provider per category type on the mocked service.
 * Returns the map so a test can resolve/reject a specific category on demand,
 * e.g. `providers.deck.resolve(['a result'])`.
 */
function mockProviders(types: string[]) {
	const providers = Object.fromEntries(types.map((type) => [type, deferredProvider()]))
	service.search.mockImplementation(({ type }: { type: string }) => providers[type])
	return providers
}

/**
 * The initial per-category state before any provider has resolved. Identical
 * for every pending category, so tests assert against this shared shape.
 */
const loading = { status: 'loading', entries: [], cursor: null, hasMore: false, loadMoreFailed: false }

beforeEach(() => {
	vi.useFakeTimers()
})

afterEach(() => {
	vi.clearAllMocks()
	vi.useRealTimers()
})

describe('UnifiedSearchController', () => {
	describe('loading state', () => {
		it('sets loading state on all categories when a search is started', async () => {
			service.search.mockImplementation(() => deferredProvider())

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files', 'talk'])

			expect(searchController.getSnapshot()).toEqual({
				files: loading,
				talk: loading,
			})
		})

		it('returns the results for a single category', async () => {
			const results = deferredProvider()
			service.search.mockReturnValueOnce(results)

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files'])

			results.resolve(['Some result'])

			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot()).toEqual({
				files: { status: 'loaded', entries: ['Some result'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
			})
		})
	})

	describe('ordering and blocking', () => {
		it('marks category as blocked if it arrived out of order', async () => {
			const providers = mockProviders(['files', 'talk', 'deck'])

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files', 'talk', 'deck'])

			providers.deck.resolve(['Deck result'])

			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot()).toEqual({
				files: loading,
				talk: loading,
				deck: { status: 'blocked', entries: ['Deck result'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
			})
		})

		it('marks category as loaded if it is unblocked (i.e., previous categories are loaded)', async () => {
			const providers = mockProviders(['files', 'talk', 'deck'])

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files', 'talk', 'deck'])

			providers.deck.resolve(['Deck result'])

			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot()).toEqual({
				files: loading,
				talk: loading,
				deck: { status: 'blocked', entries: ['Deck result'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
			})

			providers.files.resolve(['Files result'])
			providers.talk.resolve(['Talk result'])

			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot()).toEqual({
				files: { status: 'loaded', entries: ['Files result'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
				talk: { status: 'loaded', entries: ['Talk result'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
				deck: { status: 'loaded', entries: ['Deck result'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
			})
		})

		it('does not change the status of a category that has failed', async () => {
			const providers = mockProviders(['files', 'talk', 'deck'])

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files', 'talk', 'deck'])

			providers.talk.resolve(['Talk result'])
			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot()).toEqual({
				files: loading,
				talk: { status: 'blocked', entries: ['Talk result'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
				deck: loading,
			})

			// Ensure that we also reconcile status on failure.
			// This is important because a category that has failed may have been blocking
			// other categories, and if it fails, those categories should be unblocked.
			providers.files.reject(['Files result'])
			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot()).toEqual({
				files: { status: 'failed', entries: [], cursor: null, hasMore: false, loadMoreFailed: false },
				talk: { status: 'loaded', entries: ['Talk result'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
				deck: loading,
			})
		})
	})

	describe('stale search guard', () => {
		it('ignores a stale response from a superseded search', async () => {
			const first = mockProviders(['files', 'talk'])

			const searchController = new UnifiedSearchController()
			searchController.search('first', ['files', 'talk'])

			// A newer search supersedes the first before it resolves.
			const second = mockProviders(['files', 'talk'])
			searchController.search('second', ['files', 'talk'])

			// The stale (first) responses arrive late and must be ignored.
			first.files.resolve(['Stale files'])
			first.talk.resolve(['Stale talk'])
			await vi.advanceTimersByTimeAsync(0)

			// State still reflects the second search: both categories pending.
			expect(searchController.getSnapshot()).toEqual({
				files: loading,
				talk: loading,
			})

			// The live (second) search still resolves normally.
			second.files.resolve(['Live files'])
			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot().files).toEqual({
				status: 'loaded',
				entries: ['Live files'],
				cursor: undefined,
				hasMore: undefined,
				loadMoreFailed: false,
			})
		})

		it('ignores a stale response for a category the newer search dropped', async () => {
			const first = mockProviders(['files', 'talk'])

			const searchController = new UnifiedSearchController()
			searchController.search('first', ['files', 'talk'])

			// A newer search with a completely different category set supersedes it.
			const second = mockProviders(['deck'])
			searchController.search('second', ['deck'])

			// The stale response is for 'files', which no longer exists in the
			// current search. Reconciling it must not throw on the missing category.
			first.files.resolve(['Stale files'])
			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot()).toEqual({
				deck: loading,
			})

			// The live search still resolves normally.
			second.deck.resolve(['Live deck'])
			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot()).toEqual({
				deck: { status: 'loaded', entries: ['Live deck'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
			})
		})
	})

	describe('resetting between searches', () => {
		it('drops categories that are not part of a newer, narrower search', async () => {
			mockProviders(['files', 'talk', 'deck'])

			const searchController = new UnifiedSearchController()
			searchController.search('first', ['files', 'talk', 'deck'])

			// A narrower search replaces the first. The dropped categories must
			// not linger in the snapshot.
			mockProviders(['files'])
			searchController.search('second', ['files'])

			expect(searchController.getSnapshot()).toEqual({
				files: loading,
			})
		})
	})

	describe('cancellation', () => {
		it('cancels the previous search\'s in-flight requests when a new search starts', () => {
			const first = mockProviders(['files', 'talk'])

			const searchController = new UnifiedSearchController()
			searchController.search('first', ['files', 'talk'])

			// A new search supersedes the first while its requests are in flight.
			mockProviders(['files', 'talk'])
			searchController.search('second', ['files', 'talk'])

			expect(first.files.cancel).toHaveBeenCalledOnce()
			expect(first.talk.cancel).toHaveBeenCalledOnce()
		})
	})

	describe('dispose', () => {
		it('cancels in-flight requests when disposed', () => {
			const providers = mockProviders(['files', 'talk'])

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files', 'talk'])

			searchController.dispose()

			expect(providers.files.cancel).toHaveBeenCalledOnce()
			expect(providers.talk.cancel).toHaveBeenCalledOnce()
		})

		it('stops the reveal timer when disposed', () => {
			mockProviders(['files', 'talk'])

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files', 'talk'])

			// A search arms the reveal timer.
			expect(vi.getTimerCount()).toBe(1)

			searchController.dispose()

			expect(vi.getTimerCount()).toBe(0)
		})
	})

	describe('pagination', () => {
		it('appends the next page of results when loadMore is called', async () => {
			const files = pagedProvider()
			service.search.mockReturnValue(files)

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files'])

			files.resolvePage(0, { entries: ['a'], cursor: 'cursor-1', hasMore: true })
			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot().files).toEqual({
				status: 'loaded',
				entries: ['a'],
				cursor: 'cursor-1',
				hasMore: true,
				loadMoreFailed: false,
			})

			searchController.loadMore('files')

			files.resolvePage(1, { entries: ['b'], cursor: 'cursor-2', hasMore: false })
			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot().files).toEqual({
				status: 'loaded',
				entries: ['a', 'b'],
				cursor: 'cursor-2',
				hasMore: false,
				loadMoreFailed: false,
			})
		})

		it('re-dispatches with the stored cursor', async () => {
			const files = pagedProvider()
			service.search.mockReturnValue(files)

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files'])

			files.resolvePage(0, { entries: ['a'], cursor: 'cursor-1', hasMore: true })
			await vi.advanceTimersByTimeAsync(0)

			searchController.loadMore('files')

			expect(service.search).toHaveBeenLastCalledWith(expect.objectContaining({ type: 'files', query: 'query', cursor: 'cursor-1' }))
		})

		it('flags a page-load failure without dropping the results already loaded', async () => {
			const files = pagedProvider()
			service.search.mockReturnValue(files)

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files'])

			files.resolvePage(0, { entries: ['a'], cursor: 'cursor-1', hasMore: true })
			await vi.advanceTimersByTimeAsync(0)

			searchController.loadMore('files')
			files.rejectPage(1, new Error('network'))
			await vi.advanceTimersByTimeAsync(0)

			// Results stay put, the category is still loaded, and hasMore stays true
			// so the next loadMore retries. The failure is surfaced on its own flag.
			expect(searchController.getSnapshot().files).toEqual({
				status: 'loaded',
				entries: ['a'],
				cursor: 'cursor-1',
				hasMore: true,
				loadMoreFailed: true,
			})
		})

		it('clears the failure flag when a later page loads successfully', async () => {
			const files = pagedProvider()
			service.search.mockReturnValue(files)

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files'])

			files.resolvePage(0, { entries: ['a'], cursor: 'cursor-1', hasMore: true })
			await vi.advanceTimersByTimeAsync(0)

			// A first loadMore fails and raises the flag.
			searchController.loadMore('files')
			files.rejectPage(1, new Error('network'))
			await vi.advanceTimersByTimeAsync(0)
			expect(searchController.getSnapshot().files.loadMoreFailed).toBe(true)

			// Retrying succeeds and must clear the stale flag.
			searchController.loadMore('files')
			files.resolvePage(2, { entries: ['b'], cursor: 'cursor-2', hasMore: false })
			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot().files).toEqual({
				status: 'loaded',
				entries: ['a', 'b'],
				cursor: 'cursor-2',
				hasMore: false,
				loadMoreFailed: false,
			})
		})

		it('does nothing when the category has no more pages', async () => {
			const files = pagedProvider()
			service.search.mockReturnValue(files)

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files'])

			files.resolvePage(0, { entries: ['a'], cursor: 'cursor-1', hasMore: false })
			await vi.advanceTimersByTimeAsync(0)

			searchController.loadMore('files')

			// The initial search is the only dispatch; loadMore must not fire another.
			expect(service.search).toHaveBeenCalledTimes(1)
		})
	})

	describe('reveal timer', () => {
		it('marks blocked categories as loaded after a certain amount of time has elapsed', async () => {
			const providers = mockProviders(['files', 'talk', 'deck'])

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files', 'talk', 'deck'])

			providers.deck.resolve(['Deck result'])

			await vi.advanceTimersByTimeAsync(0)

			expect(searchController.getSnapshot()).toEqual({
				files: loading,
				talk: loading,
				deck: { status: 'blocked', entries: ['Deck result'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
			})

			await vi.advanceTimersByTimeAsync(REVEAL_INTERVAL_MS)

			expect(searchController.getSnapshot()).toEqual({
				files: loading,
				talk: loading,
				deck: { status: 'loaded', entries: ['Deck result'], cursor: undefined, hasMore: undefined, loadMoreFailed: false },
			})
		})

		it('keeps flushing on later timer cycles while categories are still loading', async () => {
			const providers = mockProviders(['files', 'talk', 'deck'])

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files', 'talk', 'deck'])

			// deck arrives out of order and is revealed by the first flush.
			providers.deck.resolve(['Deck result'])
			await vi.advanceTimersByTimeAsync(REVEAL_INTERVAL_MS)
			expect(searchController.getSnapshot().deck.status).toBe('loaded')

			// A later flush passes with nothing blocked while files/talk keep loading.
			await vi.advanceTimersByTimeAsync(REVEAL_INTERVAL_MS)

			// talk now arrives out of order (files still loading) and is blocked.
			providers.talk.resolve(['Talk result'])
			await vi.advanceTimersByTimeAsync(0)
			expect(searchController.getSnapshot().talk.status).toBe('blocked')

			// The timer must still be running to flush talk on a later cycle.
			await vi.advanceTimersByTimeAsync(REVEAL_INTERVAL_MS)
			expect(searchController.getSnapshot().talk.status).toBe('loaded')
		})

		it('stops the reveal timer once every category has resolved', async () => {
			const providers = mockProviders(['files', 'talk'])

			const searchController = new UnifiedSearchController()
			searchController.search('query', ['files', 'talk'])

			providers.files.resolve(['Files result'])
			providers.talk.resolve(['Talk result'])
			await vi.advanceTimersByTimeAsync(0)

			// Nothing is loading or blocked, so the next flush should not re-arm.
			await vi.advanceTimersByTimeAsync(REVEAL_INTERVAL_MS)
			expect(vi.getTimerCount()).toBe(0)
		})

		it('does not let a previous search\'s reveal timer fire against a new search', async () => {
			const first = mockProviders(['files', 'talk', 'deck'])

			const searchController = new UnifiedSearchController()
			searchController.search('first', ['files', 'talk', 'deck'])

			// First search: deck is blocked and its reveal timer is pending.
			first.deck.resolve(['First deck'])
			await vi.advanceTimersByTimeAsync(REVEAL_INTERVAL_MS - 500)
			expect(searchController.getSnapshot().deck.status).toBe('blocked')

			// A new search starts before the first timer fires. It must clear that
			// timer, otherwise the stale flush would reveal the new search's deck early.
			const second = mockProviders(['files', 'talk', 'deck'])
			searchController.search('second', ['files', 'talk', 'deck'])

			second.deck.resolve(['Second deck'])
			// Advance past when the first search's timer would have fired (500ms from
			// now) but before the second search's timer is due.
			await vi.advanceTimersByTimeAsync(REVEAL_INTERVAL_MS - 500)

			expect(searchController.getSnapshot().deck.status).toBe('blocked')
		})
	})
})
