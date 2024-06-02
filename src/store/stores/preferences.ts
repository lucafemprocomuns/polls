/* jshint esversion: 6 */
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { CalendarAPI, UserSettingsAPI } from '../../Api/index.js'
import { Logger } from '../../helpers/index.js'

type ViewMode = 'table-view' | 'list-view'

interface UserPreferences {
	useCommentsAlternativeStyling: boolean
	useAlternativeStyling: boolean
	calendarPeek: boolean
	checkCalendars: [],
	checkCalendarsBefore: number,
	checkCalendarsAfter: number,
	defaultViewTextPoll: ViewMode
	defaultViewDatePoll: ViewMode
	performanceThreshold: number,
	pollCombo: number[],
	relevantOffset: number,
}

export interface SessionSettings {
	manualViewDatePoll: '' | ViewMode
	manualViewTextPoll: '' | ViewMode
}

export interface Preferences {
	user: UserPreferences
	session: SessionSettings,
	availableCalendars: [],
	viewModes: ViewMode[],
}

export const usePreferencesStore = defineStore('preferences', {
	state: (): Preferences => ({
		user: {
			useCommentsAlternativeStyling: false,
			useAlternativeStyling: false,
			calendarPeek: false,
			checkCalendars: [],
			checkCalendarsBefore: 0,
			checkCalendarsAfter: 0,
			defaultViewTextPoll: 'table-view',
			defaultViewDatePoll: 'table-view',
			performanceThreshold: 1000,
			pollCombo: [],
			relevantOffset: 30,
		},
		session: {
			manualViewDatePoll: '',
			manualViewTextPoll: '',
		},
		availableCalendars: [],
		viewModes: [
			'list-view',
			'table-view',
		],
	}),

	getters: {
		viewTextPoll(state): ViewMode {
			if (state.session.manualViewTextPoll) {
				return state.session.manualViewTextPoll
			}
			if (window.innerWidth > 480) {
				return state.user.defaultViewTextPoll
			}
			return 'list-view'
		},

		viewDatePoll(state): ViewMode {
			if (state.session.manualViewDatePoll) {
				return state.session.manualViewDatePoll
			}
			if (window.innerWidth > 480) {
				return state.user.defaultViewDatePoll
			}
			return 'list-view'
	
		},
	},

	actions: {
		setPreference(payload): void {
			Object.keys(payload).filter((key) => key in this.user).forEach((key) => {
				this.user[key] = payload[key]
			})
		},

		setCalendars(payload) {
			this.availableCalendars = payload.calendars
		},
	
		addCheckCalendar(payload) {
			this.user.checkCalendars.push(payload.calendar.key)
		},
	
		setViewDatePoll(payload) {
			this.session.manualViewDatePoll = payload
		},

		setViewTextPoll(payload) {
			this.session.manualViewTextPoll = payload
		},
	
		async get(): Promise<void> {
			try {
				const response = await UserSettingsAPI.getUserSettings()
				this.setPreference(response.data.preferences)
			} catch (error) {
				if (error?.code === 'ERR_CANCELED') return
				this.$reset()
				throw error
			}
		},

		async setPollCombo(payload: { pollCombo: Array<string> }): Promise<void> {
			this.user.pollCombo = payload.pollCombo
			this.write()
		},

		async write(): Promise<void> {
			try {
				const response = await UserSettingsAPI.writeUserSettings(this.user)
				this.setPreference(response.data.preferences)
			} catch (error) {
				if (error?.code === 'ERR_CANCELED') return
				Logger.error('Error writing preferences', { error, preferences: this.user })
				throw error
			}
		},
	
		resetChunks(): void {
			this.meta.loadedChunks = 1
		},

		async setFilter(newCategoryId: string): Promise<void>{
			this.meta.currentCategoryId = newCategoryId
			this.resetChunks()
		},

		async getCalendars() {
			try {
				const response = await CalendarAPI.getCalendars()
				this.setCalendars({ calendars: response.data.calendars })
				return response
			} catch (error) {
				if (error?.code === 'ERR_CANCELED') return
				throw error
			}
		},
	},
})
