<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2017 Kai Schröer <git@schroeer.co>
 *
 * @author Kai Schröer <git@schroeer.co>
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

namespace OCA\Polls\Tests\Unit\Db;

use OCA\Polls\Tests\Unit\UnitTestCase;

use OCA\Polls\Db\PollMapper;
use OCA\Polls\Db\Vote;
use OCA\Polls\Db\VoteMapper;
use OCP\ISession;
use OCP\Server;

class VoteMapperTest extends UnitTestCase {
	private ISession $session;
	private OptionMapper $optionMapper;
	private PollMapper $pollMapper;
	private VoteMapper $voteMapper;
	/** @var Poll[] $polls */
	private array $polls = [];
	/** @var Option[] $options */
	private array $options = [];
	/** @var Vote[] $votes */
	private array $votes = [];

	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->session = Server::get(ISession::class);
		$this->session->set('ncPollsUserId', 'TestUser');

		$this->pollMapper = Server::get(PollMapper::class);
		$this->voteMapper = Server::get(VoteMapper::class);
		$this->optionMapper = Server::get(OptionMapper::class);

		$this->polls = [
			$this->fm->instance('OCA\Polls\Db\Poll')
		];

		foreach ($this->polls as &$poll) {
			$poll = $this->pollMapper->insert($poll);

			for ($optionsCount = 0; $optionsCount < 2; $optionsCount++) {
				$option = $this->fm->instance('OCA\Polls\Db\Option');
				$option->setPollId($poll->getId());
				$option->syncOption();
				array_push($this->options, $this->optionMapper->insert($option));
				$vote = $this->fm->instance('OCA\Polls\Db\Vote');
				$vote->setPollId($option->getPollId());
				$vote->setUserId('voter');
				$vote->setVoteOptionText($option->getPollOptionText());
				array_push($this->votes, $this->voteMapper->insert($vote));
			}
		}
		unset($poll);
	}


	/**
	 * testFindByPoll
	 */
	public function testFindByPoll() {
		foreach ($this->polls as $poll) {
			$this->assertTrue(count($this->voteMapper->findByPoll($poll->getId())) > 0);
		}
	}

	/**
	 * testFindByPollAndUser
	 */
	public function testFindByPollAndUser() {
		foreach ($this->polls as $poll) {
			$this->assertTrue(count($this->voteMapper->findByPollAndUser($poll->getId(), 'voter')) > 0);
		}
	}

	/**
	 * testFindSingleVote
	 */
	public function testFindSingleVote() {
		foreach ($this->votes as $vote) {
			$this->assertInstanceOf(Vote::class, $this->voteMapper->findSingleVote($vote->getPollId(), $vote->getVoteOptionText(), $vote->getUserId()));
		}
	}

	/**
	 * testParticipantsByPoll
	 */
	public function testParticipantsByPoll() {
		foreach ($this->polls as $poll) {
			$this->assertTrue(count($this->voteMapper->findParticipantsByPoll($poll->getId())) > 0);
		}
	}

	/**
	 * testUpdate
	 */
	public function testUpdate() {
		foreach ($this->votes as &$vote) {
			$vote->setVoteAnswer('no');
			$this->assertInstanceOf(Vote::class, $this->voteMapper->update($vote));
		}
		unset($vote);
	}

	/**
	 * testDeleteByPollAndUser
	 */
	public function testDeleteByPollAndUserId() {
		foreach ($this->polls as $poll) {
			$this->assertNull($this->voteMapper->deleteByPollAndUserId($poll->getId(), 'voter'));
		}
	}

	/**
	 * tearDown
	 */
	public function tearDown(): void {
		parent::tearDown();
		foreach ($this->options as $option) {
			$this->optionMapper->delete($option);
		}
		foreach ($this->polls as $poll) {
			$this->pollMapper->delete($poll);
		}
	}
}
