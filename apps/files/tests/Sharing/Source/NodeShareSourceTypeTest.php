<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OC\Files\Filesystem;
use OC\User\Database;
use OCA\Files\Sharing\Source\NodeShareSourceType;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\Sharing\Icon\ShareIconURL;
use OCP\Sharing\ISharingManager;
use OCP\Sharing\ISharingRegistry;
use OCP\Sharing\ShareAccessContext;
use OCP\Sharing\Source\ShareSource;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

#[Group(name: 'DB')]
final class NodeShareSourceTypeTest extends TestCase {
	private ISharingManager $manager;

	private IUser $user1;

	private Node $node;

	private NodeShareSourceType $sourceType;

	private function getTimestamp(): int {
		/** @psalm-suppress MixedReturnStatement */
		return self::invokePrivate($this->manager, 'generateLastUpdated');
	}

	#[\Override]
	public function setUp(): void {
		parent::setUp();

		$this->manager = Server::get(ISharingManager::class);

		$userManager = Server::get(IUserManager::class);
		$userManager->clearBackends();
		$userManager->registerBackend(new Database());

		$user1 = $userManager->createUser('user1', 'password');
		$this->assertNotFalse($user1);
		$this->user1 = $user1;

		$userFolder = Server::get(IRootFolder::class)->getUserFolder($this->user1->getUID());
		$this->node = $userFolder->newFile('foo.txt', 'bar');

		$this->sourceType = new NodeShareSourceType(Server::get(IEventDispatcher::class), $this->manager);
	}

	#[\Override]
	protected function tearDown(): void {
		$this->user1->delete();

		Filesystem::tearDown();

		parent::tearDown();
	}

	public function testValidateSource(): void {
		$this->assertTrue($this->sourceType->validateSource((string)$this->node->getId()));
		$this->assertFalse($this->sourceType->validateSource('-1'));
	}

	public function testGetSourceDisplayName(): void {
		$this->assertEquals('foo.txt', $this->sourceType->getSourceDisplayName((string)$this->node->getId()));
	}

	public function testGetSourceIcon(): void {
		$source = (string)$this->node->getId();

		$this->assertEquals(
			new ShareIconURL(
				'http://localhost/index.php/core/preview?fileId=' . $source . '&x=64&y=64',
				'http://localhost/index.php/core/preview?fileId=' . $source . '&x=64&y=64',
			),
			$this->sourceType->getSourceIcon($source),
		);
	}

	public function testDelete(): void {
		$registry = Server::get(ISharingRegistry::class);
		$registry->clear();
		$registry->registerSourceType($this->sourceType);

		$accessContext = new ShareAccessContext(currentUser: $this->user1);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource($this->sourceType::class, (string)$this->node->getId()));

		$before = $this->getTimestamp();
		$this->node->delete();
		$after = $this->getTimestamp();

		$share = $this->manager->getShare($accessContext, $id);
		$this->assertGreaterThanOrEqual($before, $share->lastUpdated);
		$this->assertLessThanOrEqual($after, $share->lastUpdated);
		$this->assertEquals([], $share->sources);

		$this->manager->deleteShare($accessContext, $id);
		$registry->clear();
	}
}
