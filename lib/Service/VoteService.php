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

use OCA\Polls\Db\Option;
use OCA\Polls\Db\OptionMapper;
use OCA\Polls\Db\Vote;
use OCA\Polls\Db\VoteMapper;
use OCA\Polls\Event\VoteSetEvent;
use OCA\Polls\Exceptions\InvalidPollIdException;
use OCA\Polls\Exceptions\VoteLimitExceededException;
use OCA\Polls\Model\Acl;
use OCA\Polls\UserSession;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;

class VoteService {
	/**
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private Acl $acl,
		private IEventDispatcher $eventDispatcher,
		private OptionMapper $optionMapper,
		private Vote $vote,
		private VoteMapper $voteMapper,
		private UserSession $userSession,
	) {
	}

	/**
	 * Read all votes of a poll based on the poll id and return list as array
	 *
	 * @return Vote[]
	 */
	public function list(?int $pollId = null): array {
		$this->acl->setPollId($pollId);

		try {
			if (!$this->acl->getIsAllowed(Acl::PERMISSION_POLL_RESULTS_VIEW)) {
				// Just return the participants votes, no further anoymizing or obfuscating is nessecary
				return $this->voteMapper->findByPollAndUser($this->acl->getpollId(), ($this->userSession->getCurrentUserId()));
			}

			$votes = $this->voteMapper->findByPoll($this->acl->getpollId());

		} catch (DoesNotExistException $e) {
			$votes = [];
		}
		return $votes;
	}

	private function checkLimits(Option $option): void {
		// check, if the optionlimit is reached or exceeded, if one is set
		if ($option->getIsLockedByOptionLimit()) {
			throw new VoteLimitExceededException;
		}

		if ($option->getIsLockedByVotesLimit()) {
			throw new VoteLimitExceededException;
		}
		return;
	}

	/**
	 * Set vote
	 */
	public function set(int $optionId, string $setTo): ?Vote {
		$option = $this->optionMapper->find($optionId);
		$this->acl->setPollId($option->getPollId(), Acl::PERMISSION_VOTE_EDIT);
		
		if ($setTo === Vote::VOTE_YES) {
			$this->checkLimits($option);
		}

		try {
			$this->vote = $this->voteMapper->findSingleVote($this->acl->getPollId(), $option->getPollOptionText(), $this->userSession->getCurrentUserId());

			if (in_array(trim($setTo), [Vote::VOTE_NO, '']) && !$this->acl->getPoll()->getUseNo()) {
				$this->vote->setVoteAnswer('');
				$this->voteMapper->delete($this->vote);
			} else {
				$this->vote->setVoteAnswer($setTo);
				$this->vote = $this->voteMapper->update($this->vote);
			}
		} catch (DoesNotExistException $e) {
			// Vote does not exist, insert as new Vote
			$this->vote = new Vote();

			$this->vote->setPollId($this->acl->getPollId());
			$this->vote->setUserId($this->userSession->getCurrentUserId());
			$this->vote->setVoteOptionText($option->getPollOptionText());
			$this->vote->setVoteOptionId($option->getId());
			$this->vote->setVoteAnswer($setTo);
			$this->vote = $this->voteMapper->insert($this->vote);
		}

		$this->eventDispatcher->dispatchTyped(new VoteSetEvent($this->vote));
		return $this->vote;
	}

	/**
	 * Remove current user from poll
	 * @param bool $deleteOnlyOrphaned - false deletes all votes of the current user, true only the orphaned votes aka votes without an option
	 */
	public function deleteCurrentUserFromPoll(bool $deleteOnlyOrphaned = false): string {
		$this->acl->request(Acl::PERMISSION_VOTE_EDIT);
		$pollId = $this->acl->getPoll()->getId();
		$userId = $this->userSession->getCurrentUserId();
		return $this->delete($pollId, $userId, $deleteOnlyOrphaned);
	}

	/**
	 * Remove user from poll
	 * @param int $pollId poll id of the poll the votes get deleted from
	 * @param string $userId user id of the user, the votes get deleted from. No user affects the current user
	 * @param bool $deleteOnlyOrphaned - false deletes all votes of the specified user, true only the orphaned votes aka votes without an option
	 */
	public function delete(?int $pollId = null, ?string $userId = null, bool $deleteOnlyOrphaned = false): string {
		$this->acl->setPollId($pollId);
		
		if (!$this->acl->getPollId()) {
			throw new InvalidPollIdException('Poll id is missing');
		}

		// if no user id is given, reset votes of current user
		if (!$userId) {
			$userId = $this->userSession->getCurrentUserId();
			$this->acl->request(Acl::PERMISSION_VOTE_EDIT);
		} else {
			// otherwise edit rights must exist
			$this->acl->request(Acl::PERMISSION_POLL_EDIT);
		}

		if ($deleteOnlyOrphaned) {
			$votes = $this->voteMapper->findOrphanedByPollandUser($pollId, $userId);
			foreach ($votes as $vote) {
				$this->voteMapper->delete($vote);
			}
			return $userId;
		}
		// fake a vote so that the event can be triggered
		// suppress logging of this action
		$this->vote = new Vote();
		$this->vote->setPollId($this->acl->getPollId());
		$this->vote->setUserId($userId);
		$this->voteMapper->deleteByPollAndUserId($pollId, $userId);

		$this->eventDispatcher->dispatchTyped(new VoteSetEvent($this->vote, false));
		return $userId;
	}
}
