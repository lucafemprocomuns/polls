<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2017 Vinzenz Rosenkranz <vinzenz.rosenkranz@gmail.com>
 *
 * @author René Gieling <github@dartcafe.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Polls\Service;

use Exception;
use OCA\Polls\Db\Preferences;
use OCA\Polls\Db\PreferencesMapper;
use OCA\Polls\Exceptions\NotAuthorizedException;
use OCA\Polls\UserSession;

class PreferencesService {

	/**
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private PreferencesMapper $preferencesMapper,
		private Preferences $preferences,
		private UserSession $userSession,
	) {
		$this->load();
	}

	public function load(): void {
		try {
			$this->preferences = $this->preferencesMapper->find($this->userSession->getCurrentUserId());
		} catch	(Exception $e) {
			$this->preferences = new Preferences;
		}
	}

	public function get(): Preferences {
		return $this->preferences;
	}
	
	/**
	 * Write references
	 */
	public function write(array $preferences): Preferences {
		if (!$this->userSession->getCurrentUserId()) {
			throw new NotAuthorizedException();
		}

		$this->preferences->setPreferences(json_encode($preferences));
		$this->preferences->setTimestamp(time());
		$this->preferences->setUserId($this->userSession->getCurrentUserId());
		
		if ($this->preferences->getId() > 0) {
			return $this->preferencesMapper->update($this->preferences);
		} else {
			return $this->preferencesMapper->insert($this->preferences);
			
		}

	}
}
