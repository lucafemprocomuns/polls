/* jshint esversion: 6 */
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import moment from '@nextcloud/moment'
import { orderBy } from 'lodash'
import { PollsAPI } from '../../Api/index.js'
import { Poll } from './poll.ts'
import { t } from '@nextcloud/l10n'
import { Logger } from '../../helpers/index.ts'
// import { usePreferencesStore } from './preferences.ts'

export type sortType = 'created' | 'title' | 'access' | 'owner' | 'expire'
export type filterType = 'relevant' | 'my' | 'private' | 'participated' | 'open' | 'all' | 'closed' | 'archived'
export interface PollCategory {
	id: filterType
	title: string
	titleExt: string
	description: string
	pinned: boolean
	createDependent: boolean
	filterCondition(poll: Poll): boolean
}

export interface AppPermissions {
	pollCreationAllowed: boolean
	comboAllowed: boolean
}

export interface Meta {
	currentCategoryId: string
	chunksize: number
	loadedChunks: number
	maxPollsInNavigation: number
	permissions: AppPermissions
}

export interface PollList {
	list: Poll[]
	meta: Meta
	pollsLoading: boolean
	sort: {
		by: sortType
		reverse: boolean
	}
	categories: PollCategory[]
}

const sortColumnsMapping = {
	created: 'status.created',
	title: 'configuration.title',
	access: 'configuration.access',
	owner: 'owner.displayName',
	expire: 'configuration.expire',
}

// const preferencesStore = usePreferencesStore()
const filterRelevantCondition = (poll) => !poll.status.deleted
	&& moment().diff(moment.unix(poll.status.relevantThreshold), 'days') < 100
	&& (poll.currentUserStatus.isInvolved || (poll.permissions.view && poll.configuration.access !== 'open'))

const filterMyPolls = (poll) => !poll.status.deleted && poll.currentUserStatus.isOwner
const filterPrivatePolls = (poll) => !poll.status.deleted && poll.configuration.access === 'private'
const filterParticipatedPolls = (poll) => !poll.status.deleted && poll.currentUserStatus.countVotes > 0
const filterOpenPolls = (poll) => !poll.status.deleted && poll.configuration.access === 'open'
const filterAllPolls = (poll) => !poll.status.deleted
const filterClosedPolls = (poll) => !poll.status.deleted && poll.configuration.expire && moment.unix(poll.configuration.expire).diff() < 0
const filterArchivedPolls = (poll) => poll.status.deleted

export const usePollsStore = defineStore('polls', {
	state: (): PollList => ({
		list: [],
		meta: {
			currentCategoryId: 'relevant',
			chunksize: 20,
			loadedChunks: 1,
			maxPollsInNavigation: 6,
			permissions: {
				pollCreationAllowed: false,
				comboAllowed: false,
			},
		},
		pollsLoading: false,
		sort: {
			by: 'created',
			reverse: true,
		},
		categories: [
			{
				id: 'relevant',
				title: t('polls', 'Relevant'),
				titleExt: t('polls', 'Relevant polls'),
				description: t('polls', 'Relevant polls which are relevant or for you, because you are a participant or the owner or you are invited to.'),
				pinned: false,
				createDependent: false,
				filterCondition: (poll) => filterRelevantCondition(poll),
			},
			{
				id: 'my',
				title: t('polls', 'My polls'),
				titleExt: t('polls', 'My polls'),
				description: t('polls', 'Your polls (in which you are the owner).'),
				pinned: false,
				createDependent: true,
				filterCondition: (poll) => filterMyPolls(poll),
			},
			{
				id: 'private',
				title: t('polls', 'Private polls'),
				titleExt: t('polls', 'Private polls'),
				description: t('polls', 'All private polls, to which you have access.'),
				pinned: false,
				createDependent: true,
				filterCondition: (poll) => filterPrivatePolls(poll),
			},
			{
				id: 'participated',
				title: t('polls', 'Participated'),
				titleExt: t('polls', 'Participated'),
				description: t('polls', 'All polls, where you placed a vote.'),
				pinned: false,
				createDependent: false,
				filterCondition: (poll) => filterParticipatedPolls(poll),
			},
			{
				id: 'open',
				title: t('polls', 'Openly accessible polls'),
				titleExt: t('polls', 'Openly accessible polls'),
				description: t('polls', 'A complete list with all openly accessible polls on this site, regardless who is the owner.'),
				pinned: false,
				createDependent: true,
				filterCondition: (poll) => filterOpenPolls(poll),
			},
			{
				id: 'all',
				title: t('polls', 'All polls'),
				titleExt: t('polls', 'All polls'),
				description: t('polls', 'All polls, where you have access to.'),
				pinned: false,
				createDependent: false,
				filterCondition: (poll) => filterAllPolls(poll),
			},
			{
				id: 'closed',
				title: t('polls', 'Closed polls'),
				titleExt: t('polls', 'Closed polls'),
				description: t('polls', 'All closed polls, where voting is disabled.'),
				pinned: false,
				createDependent: false,
				filterCondition: (poll) => filterClosedPolls(poll),
			},
			{
				id: 'archived',
				title: t('polls', 'Archive'),
				titleExt: t('polls', 'My archived polls'),
				description: t('polls', 'Your archived polls are only accessible to you.'),
				pinned: true,
				createDependent: true,
				filterCondition: (poll) => filterArchivedPolls(poll),
			},
		],
	}),

	getters: {
		// TODO: find out dated references: was cagegories
		navigationCategories(state: PollList): PollCategory[] {
			if (state.meta.permissions.pollCreationAllowed) {
				return state.categories
			}
			return state.categories.filter((category) => (!category.createDependent))
		},

		/*
		* polls list, filtered by category 
		*/
		pollsByCategory: (state: PollList) => (filterId: filterType) => {
			const currentCategory = state.categories.find((category) => category.id === filterId)
			return state.list.filter((poll) => currentCategory.filterCondition(poll))
		},
		
		/*
		* polls list, filtered by current category and sorted
		*/
		pollsFilteredSorted(state: PollList): Poll[] {
			return orderBy(
				this.pollsByCategory(state.meta.currentCategoryId),
				[state.sort.by],
				[state.sort.reverse ? 'desc' : 'asc'],
			)
		},

		/*
		* Chunked filtered and sorted polls for main view
		*/
		chunkedList(): Poll[] {
			return this.pollsFilteredSorted.slice(0, this.loaded)
		},

		/*
		* Sliced filtered and sorted polls for navigation
		*/
		navigationList: (state: PollList) => (filterId: filterType) => {
			const currentCategory = state.categories.find((category) => category.id === filterId)
			return orderBy(
				state.list.filter((poll) => currentCategory.filterCondition(poll)),
				['created'],
				['desc'],
			).slice(0, state.meta.maxPollsInNavigation)
		},

		/*
		* Sliced filtered and sorted polls for dashboard
		*/
		dashboardList(state: PollList): Poll[] {
			const currentCategory = state.categories.find((category) => category.id === 'relevant')
			return orderBy(
				state.list.filter((poll) => currentCategory.filterCondition(poll)),
				['created'],
				['desc'],
			).slice(0, 7)
		},

		count(): number {
			return this.filteredRaw.length
		},

		loaded(state: PollList): number {
			return state.meta.loadedChunks * state.meta.chunksize
		},

		datePolls(state: PollList): Poll[] {
			return state.list.filter((poll) => (poll.type === 'datePoll' && !poll.status.deleted))
		},

		currentCategory(state: PollList): PollCategory {
			return state.categories.find((category) => category.id === state.meta.currentCategoryId)
		},
	
		countByCategory: (state: PollList) => (filterId: string) =>
			state.list.filter((poll: Poll) =>
			state.categories.find((category: PollCategory) =>
				category.id === filterId
			).filterCondition(poll)
		).length,
	},

	actions: {
		async load(): Promise<void> {
			Logger.debug('Loading polls in store')
			try {
				this.setLoadingStatus()
				const response = await PollsAPI.getPolls()
				this.list = response.data.list
				this.meta.permissions = response.data.permissions
				Logger.debug('Polls loaded', { polls: this.list })
			} catch (e) {
				if (e?.code === 'ERR_CANCELED') return
				console.error('Error loading polls', { error: e.response })
				throw e
			} finally {
				this.setLoadingStatus(false)
			}
		},

		async setSort(payload: { sortBy: sortType }): Promise<void> {
			if (this.sort.by === sortColumnsMapping[payload.sortBy]) {
				this.sort.reverse = !this.sort.reverse
			} else {
				this.sort.reverse = true
			}
			this.sort.by = payload.sortBy
		},

		resetChunks(): void {
			this.meta.loadedChunks = 1
		},

		async setFilter(newCategoryId: string): Promise<void>{
			this.meta.currentCategoryId = newCategoryId
			this.resetChunks()
		},

		setLoadingStatus(loading: void|boolean): void {
			this.pollsLoading = loading ?? true
		},
	},
})
