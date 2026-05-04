<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Test\Sharing;

use Exception;
use OC\Sharing\Permission\ReshareSharePermissionType;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\Interaction\Actions\ShareAction;
use OCP\Interaction\InteractionRestrictedException;
use OCP\Interaction\RestrictInteractionEvent;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\Sharing\ISharingManager;
use OCP\Sharing\ISharingRegistry;
use OCP\Sharing\Permission\SharePermission;
use OCP\Sharing\Property\ShareProperty;
use OCP\Sharing\Recipient\ShareRecipient;
use OCP\Sharing\Share;
use OCP\Sharing\ShareAccessContext;
use OCP\Sharing\ShareState;
use OCP\Sharing\Source\ShareSource;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

/**
 * @psalm-import-type SharingShare from Share
 * @psalm-import-type SharingRecipient from Share
 */
abstract class AbstractSharingManagerTests extends TestCase {
	abstract protected function searchRecipients(ShareAccessContext $accessContext, ?array $recipientTypeClasses, string $query, int $limit, int $offset): array;

	abstract protected function createShare(ShareAccessContext $accessContext): array;

	abstract protected function updateShareState(ShareAccessContext $accessContext, string $id, ShareState $state): array;

	abstract protected function addShareSource(ShareAccessContext $accessContext, string $id, ShareSource $source): array;

	abstract protected function removeShareSource(ShareAccessContext $accessContext, string $id, ShareSource $source): array;

	abstract protected function addShareRecipient(ShareAccessContext $accessContext, string $id, ShareRecipient $recipient): array;

	abstract protected function removeShareRecipient(ShareAccessContext $accessContext, string $id, ShareRecipient $recipient): array;

	abstract protected function updateShareRecipientSecret(ShareAccessContext $accessContext, string $id, ShareRecipient $recipient, string $secret): array;

	abstract protected function updateShareProperty(ShareAccessContext $accessContext, string $id, ShareProperty $property): array;

	abstract protected function updateSharePermission(ShareAccessContext $accessContext, string $id, SharePermission $permission): array;

	abstract protected function selectSharePermissionPreset(ShareAccessContext $accessContext, string $id, string $permissionPresetClass): array;

	abstract protected function deleteShare(ShareAccessContext $accessContext, string $id): void;

	abstract protected function getShare(ShareAccessContext $accessContext, string $id): array;

	abstract protected function listShares(ShareAccessContext $accessContext, ?string $filterSourceTypeClass, ?string $filterSourceTypeValue, ?string $lastShareID, ?int $limit): array;

	protected ISharingManager $manager;

	protected ISharingRegistry $registry;

	protected IUser $owner;

	protected IUser $user1;

	protected IUser $user2;

	#[\Override]
	public function setUp(): void {
		parent::setUp();

		$this->manager = Server::get(ISharingManager::class);

		$userManager = Server::get(IUserManager::class);

		$owner = $userManager->createUser('owner', 'password');
		$this->assertNotFalse($owner);
		$this->owner = $owner;
		$this->owner->setDisplayName('Owner');

		$user1 = $userManager->createUser('user1', 'password');
		$this->assertNotFalse($user1);
		$this->user1 = $user1;
		$this->user1->setDisplayName('User 1');

		$user2 = $userManager->createUser('user2', 'password');
		$this->assertNotFalse($user2);
		$this->user2 = $user2;
		$this->user2->setDisplayName('User 2');

		$this->registry = Server::get(ISharingRegistry::class);
		$this->registry->clear();
		$this->registry->registerSourceType(new TestShareSourceType1(['source1' => 'Source 1']));
		$this->registry->registerSourceType(new TestShareSourceType2(['source2' => 'Source 2']));
		$this->registry->registerRecipientType(new TestShareRecipientType1(
			[
				'recipient1' => 'Recipient 1',
			],
			[
				$this->user1->getUID() => ['recipient1'],
			],
			[],
		));
		$this->registry->registerRecipientType(new TestShareRecipientType2(
			[
				'recipient2' => 'Recipient 2',
			],
			[
				$this->user2->getUID() => ['recipient2'],
			],
			[],
		));
		$this->registry->registerPropertyType(new TestSharePropertyType1(['valid1']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyType1::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyType1::class, TestShareRecipientType1::class);
		$this->registry->registerPropertyType(new TestSharePropertyType2(['valid2']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyType2::class, TestShareSourceType2::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyType2::class, TestShareRecipientType2::class);
		$this->registry->registerPermissionPreset(new TestSharePermissionPreset1());
		$this->registry->registerPermissionPreset(new TestSharePermissionPreset2());
		$this->registry->registerPermissionType(TestShareSourceType1::class, new TestSharePermissionType1());
		$this->registry->markPermissionTypeCompatibleWithPermissionPreset(TestSharePermissionType1::class, TestSharePermissionPreset1::class);
		$this->registry->markPermissionTypeCompatibleWithPermissionPreset(TestSharePermissionType1::class, TestSharePermissionPreset2::class);
		$this->registry->registerPermissionType(TestShareSourceType2::class, new TestSharePermissionType2());
		$this->registry->markPermissionTypeCompatibleWithPermissionPreset(TestSharePermissionType2::class, TestSharePermissionPreset2::class);
		$this->registry->registerPermissionType(null, new ReshareSharePermissionType());
	}

	#[\Override]
	protected function tearDown(): void {
		$accessContext = new ShareAccessContext(overrideChecks: true);

		foreach ($this->manager->listShares($accessContext, null, null, null, null) as $share) {
			$this->manager->deleteShare($accessContext, $share->id);
		}

		$connection = Server::get(IDBConnection::class);
		foreach ([
			'sharing_share',
			'sharing_share_permissions',
			'sharing_share_properties',
			'sharing_share_recipients',
			'sharing_share_sources',
		] as $table) {
			$qb = $connection->getQueryBuilder();
			$qb
				->select($qb->func()->count('*'))
				->from($table);
			$this->assertEquals(0, $qb->executeQuery()->fetchOne(), $table);
		}

		$this->registry->clear();

		$this->owner->delete();
		$this->user1->delete();
		$this->user2->delete();

		parent::tearDown();
	}

	private function getTimestamp(): int {
		/** @psalm-suppress MixedReturnStatement */
		return self::invokePrivate($this->manager, 'generateLastUpdated');
	}

	public function testSearchRecipients(): void {
		$this->registry->clear();
		$this->registry->registerRecipientType(new TestShareRecipientType1(
			[
				'recipient1a' => 'Recipient 1A',
				'recipient1b' => 'Recipient 1B',
				'recipient1c' => 'Recipient 1C',
			],
			[],
			[
				new ShareRecipient(TestShareRecipientType1::class, 'recipient1a', null),
				new ShareRecipient(TestShareRecipientType1::class, 'recipient1b', null),
				new ShareRecipient(TestShareRecipientType1::class, 'recipient1c', null),
			],
		));
		$this->registry->registerRecipientType(new TestShareRecipientType2(
			[
				'recipient2a' => 'Recipient 2A',
				'recipient2b' => 'Recipient 2B',
				'recipient2c' => 'Recipient 2C',
			],
			[],
			[
				new ShareRecipient(TestShareRecipientType2::class, 'recipient2a', null),
				new ShareRecipient(TestShareRecipientType2::class, 'recipient2b', null),
				new ShareRecipient(TestShareRecipientType2::class, 'recipient2c', null),
			],
		));

		$accessContext = new ShareAccessContext($this->owner);

		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1a',
				'instance' => null,
				'display_name' => 'Recipient 1A',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1b',
				'instance' => null,
				'display_name' => 'Recipient 1B',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1c',
				'instance' => null,
				'display_name' => 'Recipient 1C',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient2a',
				'instance' => null,
				'display_name' => 'Recipient 2A',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient2b',
				'instance' => null,
				'display_name' => 'Recipient 2B',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient2c',
				'instance' => null,
				'display_name' => 'Recipient 2C',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
		], $this->searchRecipients($accessContext, null, 'recipient', 10, 0));

		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1a',
				'instance' => null,
				'display_name' => 'Recipient 1A',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1b',
				'instance' => null,
				'display_name' => 'Recipient 1B',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1c',
				'instance' => null,
				'display_name' => 'Recipient 1C',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
		], $this->searchRecipients($accessContext, [TestShareRecipientType1::class], 'recipient', 10, 0));

		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1a',
				'instance' => null,
				'display_name' => 'Recipient 1A',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
		], $this->searchRecipients($accessContext, [TestShareRecipientType1::class], 'recipient', 1, 0));

		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1b',
				'instance' => null,
				'display_name' => 'Recipient 1B',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1c',
				'instance' => null,
				'display_name' => 'Recipient 1C',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
		], $this->searchRecipients($accessContext, [TestShareRecipientType1::class], 'recipient', 10, 1));
	}

	public function testSearchRecipientsUniqueDisplayNames(): void {
		$this->registry->clear();
		$this->registry->registerRecipientType(new TestShareRecipientType1(
			[
				'recipient1' => 'Recipient',
			],
			[],
			[
				new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null),
			],
		));
		$this->registry->registerRecipientType(new TestShareRecipientType2(
			[
				'recipient2' => 'Recipient',
				'recipient3' => 'Other',
			],
			[],
			[
				new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null),
				new ShareRecipient(TestShareRecipientType2::class, 'recipient3', null),
			],
		));

		$accessContext = new ShareAccessContext($this->owner);

		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1',
				'instance' => null,
				'display_name' => 'Recipient (TestShareRecipientType1: recipient1)',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient2',
				'instance' => null,
				'display_name' => 'Recipient (TestShareRecipientType2: recipient2)',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient3',
				'instance' => null,
				'display_name' => 'Other',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
		], $this->searchRecipients($accessContext, null, 'recipient', 10, 0));
	}

	public function testSearchRecipientsIcons(): void {
		$this->registry->clear();
		$this->registry->registerRecipientType(new TestShareRecipientType1(
			[
				'svg' => 'SVG',
				'url' => 'URL',
			],
			[],
			[
				new ShareRecipient(TestShareRecipientType1::class, 'svg', null),
				new ShareRecipient(TestShareRecipientType1::class, 'url', null),
			],
		));

		$accessContext = new ShareAccessContext($this->owner);

		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'svg',
				'instance' => null,
				'display_name' => 'SVG',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'url',
				'instance' => null,
				'display_name' => 'URL',
				'icon' => [
					'light' => 'https://example.com/light.png',
					'dark' => 'https://example.com/dark.png',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => null,
			],
		], $this->searchRecipients($accessContext, null, 'icon', 10, 0));
	}

	public function testCreateShare(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$share = $this->createShare($accessContext);
		$after = $this->getTimestamp();
		unset($share['id']);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'owner' => [
				'user_id' => 'owner',
				'instance' => null,
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [],
			'recipients' => [],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'source_class' => null,
					'display_name' => 'Share with others',
					'hint' => null,
					'presets' => [],
					'enabled' => false,
					'priority' => 90,
				],
			],
			'permission_preset' => null,
		], $share);
	}

	/**
	 * @return list<array{list<ShareSource>, list<ShareRecipient>, list<ShareProperty>, list<SharePermission>, ?string}>
	 */
	public static function dataProviderUpdateShareState(): array {
		return [
			[
				[new ShareSource(TestShareSourceType1::class, 'source1')],
				[new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null)],
				[new ShareProperty(TestSharePropertyTypeRequired::class, 'valid1')],
				[new SharePermission(ReshareSharePermissionType::class, true)],
				null,
			],
			[
				[],
				[new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null)],
				[],
				[new SharePermission(ReshareSharePermissionType::class, true)],
				'No source set.',
			],
			[
				[new ShareSource(TestShareSourceType1::class, 'source1')],
				[],
				[],
				[new SharePermission(ReshareSharePermissionType::class, true)],
				'No recipient set.',
			],
			[
				[new ShareSource(TestShareSourceType1::class, 'source1')],
				[new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null)],
				[new ShareProperty(TestSharePropertyTypeRequired::class, null)],
				[new SharePermission(ReshareSharePermissionType::class, true)],
				'Missing value for required property: ' . TestSharePropertyTypeRequired::class,
			],
			[
				[new ShareSource(TestShareSourceType1::class, 'source1')],
				[new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null)],
				[new ShareProperty(TestSharePropertyTypeRequired::class, 'valid1')],
				[new SharePermission(ReshareSharePermissionType::class, false)],
				'No permission given.',
			],
		];
	}

	/**
	 * @param list<ShareSource> $sources
	 * @param list<ShareRecipient> $recipients
	 * @param list<ShareProperty> $properties
	 * @param list<SharePermission> $permissions
	 */
	#[DataProvider('dataProviderUpdateShareState')]
	public function testUpdateShareState(array $sources, array $recipients, array $properties, array $permissions, ?string $errorMessage): void {
		$this->registry->registerPropertyType(new TestSharePropertyTypeRequired(['valid1']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyTypeRequired::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyTypeRequired::class, TestShareRecipientType1::class);

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		foreach ($sources as $source) {
			$this->manager->addShareSource($accessContext, $id, $source);
		}

		foreach ($recipients as $recipient) {
			$this->manager->addShareRecipient($accessContext, $id, $recipient);
		}

		$this->manager->getShare($accessContext, $id);

		foreach ($properties as $property) {
			$this->manager->updateShareProperty($accessContext, $id, $property);
		}

		foreach ($permissions as $permission) {
			$this->manager->updateSharePermission($accessContext, $id, $permission);
		}

		if ($errorMessage !== null) {
			$this->expectExceptionMessage($errorMessage);
			$this->updateShareState($accessContext, $id, ShareState::Active);
		} else {
			$before = $this->getTimestamp();
			$share = $this->updateShareState($accessContext, $id, ShareState::Active);
			$after = $this->getTimestamp();
			$this->assertGreaterThanOrEqual($before, $share['last_updated']);
			$this->assertLessThanOrEqual($after, $share['last_updated']);
			$this->assertEquals(ShareState::Active->value, $share['state']);
		}
	}

	public function testAddShareSource(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);

		$before = $this->getTimestamp();
		$share = $this->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals([
			[
				'class' => TestShareSourceType1::class,
				'value' => 'source1',
				'display_name' => 'Source 1',
				'icon' => [
					'svg' => '<svg/>',
				],
			],
		], $share ['sources']);
	}

	public function testAddShareSourceInteractionRestricted(): void {
		$listener = function (RestrictInteractionEvent $event): void {
			foreach ($event->resources as $resource) {
				if ($resource instanceof TestInteractionResource && $resource->getID() === 'source1') {
					throw new InteractionRestrictedException('Source not allowed.', 'You are not allowed to add this source.');
				}
			}
		};
		$eventDispatcher = Server::get(IEventDispatcher::class);
		$eventDispatcher->addListener(RestrictInteractionEvent::class, $listener);

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));

		try {
			$this->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
			$this->fail('Not restricted.');
		} catch (Exception $exception) {
			$this->assertEquals('Invalid share: You are not allowed to add this source.', $exception->getMessage());
		}

		$eventDispatcher->removeListener(RestrictInteractionEvent::class, $listener);
	}

	public function testRemoveShareSource(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType2::class, 'source2'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$before = $this->getTimestamp();
		$share = $this->removeShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(ShareState::Active->value, $share['state']);
		$this->assertEquals([
			[
				'class' => TestShareSourceType2::class,
				'value' => 'source2',
				'display_name' => 'Source 2',
				'icon' => [
					'svg' => '<svg/>',
				],
			],
		], $share['sources']);

		$before = $this->getTimestamp();
		$share = $this->removeShareSource($accessContext, $id, new ShareSource(TestShareSourceType2::class, 'source2'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(ShareState::Draft->value, $share['state']);
		$this->assertEquals([], $share['sources']);
	}

	public function testAddShareRecipient(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);

		$before = $this->getTimestamp();
		$share = $this->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1',
				'instance' => null,
				'display_name' => 'Recipient 1',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
			],
		], $share['recipients']);
	}

	public function testAddShareRecipientInteractionRestricted(): void {
		$listener = function (RestrictInteractionEvent $event): void {
			foreach ($event->receivers as $receiver) {
				if ($receiver instanceof TestInteractionReceiver && $receiver->getID() === 'recipient1') {
					throw new InteractionRestrictedException('Recipient not allowed.', 'You are not allowed to add this recipient.');
				}
			}
		};
		$eventDispatcher = Server::get(IEventDispatcher::class);
		$eventDispatcher->addListener(RestrictInteractionEvent::class, $listener);

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));

		try {
			$this->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
			$this->fail('Not restricted.');
		} catch (Exception $exception) {
			$this->assertEquals('Invalid share: You are not allowed to add this recipient.', $exception->getMessage());
		}

		$eventDispatcher->removeListener(RestrictInteractionEvent::class, $listener);
	}

	public function testAddChildShareRecipientWithoutResharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
	}

	public function testAddChildShareRecipientWithResharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$before = $this->getTimestamp();
		$share = $this->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1',
				'instance' => null,
				'display_name' => 'Recipient 1',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
			],
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient2',
				'instance' => null,
				'display_name' => 'Recipient 2',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'user1',
					'instance' => null,
					'display_name' => 'User 1',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/user1/64',
						'dark' => 'http://localhost/index.php/avatar/user1/64/dark',
					],
				],
			],
		], $share['recipients']);
	}

	public function testRemoveShareRecipient(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$before = $this->getTimestamp();
		$share = $this->removeShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(ShareState::Active->value, $share['state']);
		$this->assertEquals([
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient2',
				'instance' => null,
				'display_name' => 'Recipient 2',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
			],
		], $share['recipients']);

		$before = $this->getTimestamp();
		$share = $this->removeShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(ShareState::Draft->value, $share['state']);
		$this->assertEquals([], $share['recipients']);
	}

	public function testRemoveSelfShareRecipientWithoutResharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
	}

	public function testRemoveSelfShareRecipientWithResharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
	}

	public function testRemoveChildShareRecipientWithoutResharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, false));

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
	}

	public function testRemoveChildShareRecipientWithResharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));

		$before = $this->getTimestamp();
		$share = $this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1',
				'instance' => null,
				'display_name' => 'Recipient 1',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
			],
		], $share['recipients']);
	}

	public function testRemoveSiblingShareRecipientWithoutResharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
	}

	public function testRemoveSiblingShareRecipientWithResharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
	}

	public function testRemoveParentShareRecipientWithoutResharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, false));

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user2), $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
	}

	public function testRemoveParentShareRecipientWithResharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));

		$this->expectExceptionMessage('Share operation forbidden: ' . $id);
		$this->removeShareRecipient(new ShareAccessContext($this->user2), $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
	}

	/**
	 * @return list<array{bool}>
	 */
	public static function dataUpdateShareRecipientSecret(): array {
		return [
			[true],
			[false],
		];
	}

	#[DataProvider('dataUpdateShareRecipientSecret')]
	public function testUpdateShareRecipientSecret(bool $isSecretUpdatable): void {
		$this->registry->registerRecipientType(new TestShareRecipientTypePublicSecret(
			[
				'recipient1' => 'Recipient 1',
			],
			[],
			true,
			$isSecretUpdatable,
		));

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$recipient = new ShareRecipient(TestShareRecipientTypePublicSecret::class, 'recipient1', null);
		$this->manager->addShareRecipient($accessContext, $id, $recipient);

		if (!$isSecretUpdatable) {
			$this->expectExceptionMessage('Share operation forbidden: ' . $id);
			$this->updateShareRecipientSecret($accessContext, $id, $recipient, 'mysecret');
		} else {
			$before = $this->getTimestamp();
			$share = $this->updateShareRecipientSecret($accessContext, $id, $recipient, 'mysecret');
			$after = $this->getTimestamp();
			$this->assertGreaterThanOrEqual($before, $share['last_updated']);
			$this->assertLessThanOrEqual($after, $share['last_updated']);
			unset($share['last_updated']);
			$this->assertEquals([
				[
					'class' => TestShareRecipientTypePublicSecret::class,
					'value' => 'recipient1',
					'instance' => null,
					'display_name' => 'Recipient 1',
					'icon' => [
						'svg' => '<svg/>'
					],
					'secret' => [
						'updatable' => true,
						'value' => 'mysecret',
						'url' => 'http://localhost/index.php/s/mysecret',
					],
					'initiator' => [
						'user_id' => 'owner',
						'instance' => null,
						'display_name' => 'Owner',
						'icon' => [
							'light' => 'http://localhost/index.php/avatar/owner/64',
							'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
						],
					],
				],
			], $share['recipients']);
		}
	}

	/**
	 * @return list<array{list<?string>}>
	 */
	public static function dataProviderUpdateShareProperty(): array {
		return [
			[[null, 'valid1']],
			[['valid1', null]],
		];
	}

	/**
	 * @param list<?string> $values
	 */
	#[DataProvider('dataProviderUpdateShareProperty')]
	public function testUpdateShareProperty(array $values): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);

		foreach ($values as $value) {
			$before = $this->getTimestamp();
			$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyType1::class, $value));
			$after = $this->getTimestamp();
			$this->assertGreaterThanOrEqual($before, $share['last_updated']);
			$this->assertLessThanOrEqual($after, $share['last_updated']);
			$this->assertEquals([
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'hint' => 'hint TestSharePropertyType1',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => $value,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
			], $share['properties']);
		}
	}

	public function testUpdateSharePropertyRequired(): void {
		$this->registry->registerPropertyType(new TestSharePropertyTypeRequired(['valid1', 'valid2']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyTypeRequired::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyTypeRequired::class, TestShareRecipientType1::class);

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeRequired::class, 'valid1'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(ShareState::Draft->value, $share['state']);
		$this->assertEquals([
			[
				'class' => TestSharePropertyType1::class,
				'display_name' => 'TestSharePropertyType1',
				'hint' => 'hint TestSharePropertyType1',
				'priority' => 1,
				'advanced' => false,
				'required' => false,
				'value' => null,
				'type' => 'enum',
				'valid_values' => ['valid1'],
			],
			[
				'class' => TestSharePropertyTypeRequired::class,
				'display_name' => 'TestSharePropertyTypeRequired',
				'hint' => 'hint TestSharePropertyTypeRequired',
				'priority' => 1,
				'advanced' => false,
				'required' => true,
				'value' => 'valid1',
				'type' => 'enum',
				'valid_values' => ['valid1', 'valid2'],
			],
		], $share['properties']);

		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeRequired::class, 'valid2'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(ShareState::Active->value, $share['state']);
		$this->assertEquals([
			[
				'class' => TestSharePropertyType1::class,
				'display_name' => 'TestSharePropertyType1',
				'hint' => 'hint TestSharePropertyType1',
				'priority' => 1,
				'advanced' => false,
				'required' => false,
				'value' => null,
				'type' => 'enum',
				'valid_values' => ['valid1'],
			],
			[
				'class' => TestSharePropertyTypeRequired::class,
				'display_name' => 'TestSharePropertyTypeRequired',
				'hint' => 'hint TestSharePropertyTypeRequired',
				'priority' => 1,
				'advanced' => false,
				'required' => true,
				'value' => 'valid2',
				'type' => 'enum',
				'valid_values' => ['valid1', 'valid2'],
			],
		], $share['properties']);

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeRequired::class, null));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(ShareState::Draft->value, $share['state']);
		$this->assertEquals([
			[
				'class' => TestSharePropertyType1::class,
				'display_name' => 'TestSharePropertyType1',
				'hint' => 'hint TestSharePropertyType1',
				'priority' => 1,
				'advanced' => false,
				'required' => false,
				'value' => null,
				'type' => 'enum',
				'valid_values' => ['valid1'],
			],
			[
				'class' => TestSharePropertyTypeRequired::class,
				'display_name' => 'TestSharePropertyTypeRequired',
				'hint' => 'hint TestSharePropertyTypeRequired',
				'priority' => 1,
				'advanced' => false,
				'required' => true,
				'value' => null,
				'type' => 'enum',
				'valid_values' => ['valid1', 'valid2'],
			],
		], $share['properties']);
	}

	public function testUpdateSharePropertyModifyProperties(): void {
		$this->registry->registerPropertyType(new TestSharePropertyTypeModifyValue(['old-value', 'modify-on-save-old-value', 'modify-on-save', 'modify-on-load']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyTypeModifyValue::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyTypeModifyValue::class, TestShareRecipientType1::class);

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeModifyValue::class, 'old-value'));

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeModifyValue::class, 'modify-on-save-old-value'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals([
			[
				'class' => TestSharePropertyType1::class,
				'display_name' => 'TestSharePropertyType1',
				'hint' => 'hint TestSharePropertyType1',
				'priority' => 1,
				'advanced' => false,
				'required' => false,
				'value' => null,
				'type' => 'enum',
				'valid_values' => ['valid1'],
			],
			[
				'class' => TestSharePropertyTypeModifyValue::class,
				'display_name' => 'TestSharePropertyTypeModifyValue',
				'hint' => 'hint TestSharePropertyTypeModifyValue',
				'priority' => 1,
				'advanced' => false,
				'required' => false,
				'value' => 'old-value',
				'type' => 'enum',
				'valid_values' => ['old-value', 'modify-on-save-old-value', 'modify-on-save', 'modify-on-load'],
			],
		], $share['properties']);

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeModifyValue::class, 'modify-on-save'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals([
			[
				'class' => TestSharePropertyType1::class,
				'display_name' => 'TestSharePropertyType1',
				'hint' => 'hint TestSharePropertyType1',
				'priority' => 1,
				'advanced' => false,
				'required' => false,
				'value' => null,
				'type' => 'enum',
				'valid_values' => ['valid1'],
			],
			[
				'class' => TestSharePropertyTypeModifyValue::class,
				'display_name' => 'TestSharePropertyTypeModifyValue',
				'hint' => 'hint TestSharePropertyTypeModifyValue',
				'priority' => 1,
				'advanced' => false,
				'required' => false,
				'value' => 'modified-on-save',
				'type' => 'enum',
				'valid_values' => ['old-value', 'modify-on-save-old-value', 'modify-on-save', 'modify-on-load'],
			],
		], $share['properties']);

		$before = $this->getTimestamp();
		$share = $this->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeModifyValue::class, 'modify-on-load'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals([
			[
				'class' => TestSharePropertyType1::class,
				'display_name' => 'TestSharePropertyType1',
				'hint' => 'hint TestSharePropertyType1',
				'priority' => 1,
				'advanced' => false,
				'required' => false,
				'value' => null,
				'type' => 'enum',
				'valid_values' => ['valid1'],
			],
			[
				'class' => TestSharePropertyTypeModifyValue::class,
				'display_name' => 'TestSharePropertyTypeModifyValue',
				'hint' => 'hint TestSharePropertyTypeModifyValue',
				'priority' => 1,
				'advanced' => false,
				'required' => false,
				'value' => 'modified-on-load',
				'type' => 'enum',
				'valid_values' => ['old-value', 'modify-on-save-old-value', 'modify-on-save', 'modify-on-load'],
			],
		], $share['properties']);
	}

	public function testUpdateSharePermission(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);

		$before = $this->getTimestamp();
		$this->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$share = $this->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(ShareState::Draft->value, $share['state']);
		$this->assertEquals([
			[
				'class' => ReshareSharePermissionType::class,
				'source_class' => null,
				'display_name' => 'Share with others',
				'hint' => null,
				'presets' => [],
				'enabled' => true,
				'priority' => 90,
			],
			[
				'class' => TestSharePermissionType1::class,
				'source_class' => TestShareSourceType1::class,
				'display_name' => 'TestSharePermissionType1',
				'hint' => 'hint TestSharePermissionType1',
				'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
		], $share['permissions']);

		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$before = $this->getTimestamp();
		$share = $this->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, false));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(ShareState::Active->value, $share['state']);
		$this->assertEquals([
			[
				'class' => ReshareSharePermissionType::class,
				'source_class' => null,
				'display_name' => 'Share with others',
				'hint' => null,
				'presets' => [],
				'enabled' => false,
				'priority' => 90,
			],
			[
				'class' => TestSharePermissionType1::class,
				'source_class' => TestShareSourceType1::class,
				'display_name' => 'TestSharePermissionType1',
				'hint' => 'hint TestSharePermissionType1',
				'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
		], $share['permissions']);

		$before = $this->getTimestamp();
		$share = $this->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, false));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(ShareState::Draft->value, $share['state']);
		$this->assertEquals([
			[
				'class' => ReshareSharePermissionType::class,
				'source_class' => null,
				'display_name' => 'Share with others',
				'hint' => null,
				'presets' => [],
				'enabled' => false,
				'priority' => 90,
			],
			[
				'class' => TestSharePermissionType1::class,
				'source_class' => TestShareSourceType1::class,
				'display_name' => 'TestSharePermissionType1',
				'hint' => 'hint TestSharePermissionType1',
				'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
				'enabled' => false,
				'priority' => 1,
			],
		], $share['permissions']);
	}

	public function testUpdateSharePermissionInteractionRestricted(): void {
		$listener = function (RestrictInteractionEvent $event): void {
			if ($event->action instanceof ShareAction && $event->action->unifiedSharingPermissions !== null && in_array(TestSharePermissionType1::class, $event->action->unifiedSharingPermissions, true)) {
				throw new InteractionRestrictedException('Permission not allowed.', 'You are not allowed to enable this permission.');
			}
		};
		$eventDispatcher = Server::get(IEventDispatcher::class);
		$eventDispatcher->addListener(RestrictInteractionEvent::class, $listener);

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));

		try {
			$this->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
			$this->fail('Not restricted.');
		} catch (Exception $exception) {
			$this->assertEquals('Invalid share: You are not allowed to enable this permission.', $exception->getMessage());
		}

		$eventDispatcher->removeListener(RestrictInteractionEvent::class, $listener);
	}

	public function testSelectSharePermissionPreset(): void {
		$this->registry->clear();
		$this->registry->registerPermissionPreset(new TestSharePermissionPreset1());
		$this->registry->registerPermissionPreset(new TestSharePermissionPreset2());
		$this->registry->registerPermissionType(null, new TestSharePermissionType1());
		$this->registry->markPermissionTypeCompatibleWithPermissionPreset(TestSharePermissionType1::class, TestSharePermissionPreset1::class);
		$this->registry->markPermissionTypeCompatibleWithPermissionPreset(TestSharePermissionType1::class, TestSharePermissionPreset2::class);
		$this->registry->registerPermissionType(null, new TestSharePermissionType2());
		$this->registry->markPermissionTypeCompatibleWithPermissionPreset(TestSharePermissionType2::class, TestSharePermissionPreset2::class);
		$this->registry->registerPermissionType(null, new TestSharePermissionType3());

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$after = $this->getTimestamp();

		$share = $this->getShare($accessContext, $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertNull($share['permission_preset']);
		$this->assertEquals([
			[
				'class' => TestSharePermissionType1::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType1',
				'hint' => 'hint TestSharePermissionType1',
				'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
				'enabled' => false,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType2::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType2',
				'hint' => 'hint TestSharePermissionType2',
				'presets' => [TestSharePermissionPreset2::class],
				'enabled' => false,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType3::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType3',
				'hint' => 'hint TestSharePermissionType3',
				'presets' => [],
				'enabled' => false,
				'priority' => 1,
			],
		], $share['permissions']);

		$before = $this->getTimestamp();
		$share = $this->selectSharePermissionPreset($accessContext, $id, TestSharePermissionPreset2::class);
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(TestSharePermissionPreset2::class, $share['permission_preset']);
		$this->assertEquals([
			[
				'class' => TestSharePermissionType1::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType1',
				'hint' => 'hint TestSharePermissionType1',
				'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType2::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType2',
				'hint' => 'hint TestSharePermissionType2',
				'presets' => [TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType3::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType3',
				'hint' => 'hint TestSharePermissionType3',
				'presets' => [],
				'enabled' => false,
				'priority' => 1,
			],
		], $share['permissions']);

		$before = $this->getTimestamp();
		$share = $this->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType3::class, true));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertNull($share['permission_preset']);
		$this->assertEquals([
			[
				'class' => TestSharePermissionType1::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType1',
				'hint' => 'hint TestSharePermissionType1',
				'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType2::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType2',
				'hint' => 'hint TestSharePermissionType2',
				'presets' => [TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType3::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType3',
				'hint' => 'hint TestSharePermissionType3',
				'presets' => [],
				'enabled' => true,
				'priority' => 1,
			],
		], $share['permissions']);

		$before = $this->getTimestamp();
		$share = $this->selectSharePermissionPreset($accessContext, $id, TestSharePermissionPreset1::class);
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(TestSharePermissionPreset1::class, $share['permission_preset']);
		$this->assertEquals([
			[
				'class' => TestSharePermissionType1::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType1',
				'hint' => 'hint TestSharePermissionType1',
				'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType2::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType2',
				'hint' => 'hint TestSharePermissionType2',
				'presets' => [TestSharePermissionPreset2::class],
				'enabled' => false,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType3::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType3',
				'hint' => 'hint TestSharePermissionType3',
				'presets' => [],
				'enabled' => false,
				'priority' => 1,
			],
		], $share['permissions']);

		$before = $this->getTimestamp();
		$share = $this->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, false));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertNull($share['permission_preset']);
		$this->assertEquals([
			[
				'class' => TestSharePermissionType1::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType1',
				'hint' => 'hint TestSharePermissionType1',
				'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
				'enabled' => false,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType2::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType2',
				'hint' => 'hint TestSharePermissionType2',
				'presets' => [TestSharePermissionPreset2::class],
				'enabled' => false,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType3::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType3',
				'hint' => 'hint TestSharePermissionType3',
				'presets' => [],
				'enabled' => false,
				'priority' => 1,
			],
		], $share['permissions']);
	}

	public function testSelectSharePermissionPresetCompatible(): void {
		$this->registry->clear();
		$this->registry->registerSourceType(new TestShareSourceType1(['source1' => 'Source 1']));
		$this->registry->registerPermissionPreset(new TestSharePermissionPreset1());
		$this->registry->registerPermissionPreset(new TestSharePermissionPreset2());
		$this->registry->registerPermissionType(TestShareSourceType1::class, new TestSharePermissionType1());
		$this->registry->markPermissionTypeCompatibleWithPermissionPreset(TestSharePermissionType1::class, TestSharePermissionPreset1::class);
		$this->registry->markPermissionTypeCompatibleWithPermissionPreset(TestSharePermissionType1::class, TestSharePermissionPreset2::class);
		$this->registry->registerPermissionType(null, new TestSharePermissionType2());
		$this->registry->markPermissionTypeCompatibleWithPermissionPreset(TestSharePermissionType2::class, TestSharePermissionPreset2::class);

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->getShare($accessContext, $id);
		$after = $this->getTimestamp();

		$share = $this->getShare($accessContext, $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertNull($share['permission_preset']);
		$this->assertEquals([
			[
				'class' => TestSharePermissionType2::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType2',
				'hint' => 'hint TestSharePermissionType2',
				'presets' => [TestSharePermissionPreset2::class],
				'enabled' => false,
				'priority' => 1,
			],
		], $share['permissions']);

		$before = $this->getTimestamp();
		$share = $this->selectSharePermissionPreset($accessContext, $id, TestSharePermissionPreset2::class);
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(TestSharePermissionPreset2::class, $share['permission_preset']);
		$this->assertEquals([
			[
				'class' => TestSharePermissionType2::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType2',
				'hint' => 'hint TestSharePermissionType2',
				'presets' => [TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
		], $share['permissions']);

		$before = $this->getTimestamp();
		$share = $this->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertNull($share['permission_preset']);
		$this->assertEquals([
			[
				'class' => TestSharePermissionType2::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType2',
				'hint' => 'hint TestSharePermissionType2',
				'presets' => [TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType1::class,
				'source_class' => TestShareSourceType1::class,
				'display_name' => 'TestSharePermissionType1',
				'hint' => 'hint TestSharePermissionType1',
				'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
				'enabled' => false,
				'priority' => 1,
			],
		], $share['permissions']);

		$before = $this->getTimestamp();
		$share = $this->selectSharePermissionPreset($accessContext, $id, TestSharePermissionPreset2::class);
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals(TestSharePermissionPreset2::class, $share['permission_preset']);
		$this->assertEquals([
			[
				'class' => TestSharePermissionType2::class,
				'source_class' => null,
				'display_name' => 'TestSharePermissionType2',
				'hint' => 'hint TestSharePermissionType2',
				'presets' => [TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
			[
				'class' => TestSharePermissionType1::class,
				'source_class' => TestShareSourceType1::class,
				'display_name' => 'TestSharePermissionType1',
				'hint' => 'hint TestSharePermissionType1',
				'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
				'enabled' => true,
				'priority' => 1,
			],
		], $share['permissions']);
	}

	public function testDeleteShare(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);

		$this->deleteShare($accessContext, $id);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->manager->getShare(new ShareAccessContext(overrideChecks: true), $id);
	}

	public function testGetShare(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);

		$after = $this->getTimestamp();

		$share = $this->getShare($accessContext, $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'instance' => null,
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Draft->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
					'icon' => [
						'svg' => '<svg/>',
					],
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'instance' => null,
					'display_name' => 'Recipient 1',
					'icon' => [
						'svg' => '<svg/>',
					],
					'secret' => [
						'updatable' => false,
					],
					'initiator' => [
						'user_id' => 'owner',
						'instance' => null,
						'display_name' => 'Owner',
						'icon' => [
							'light' => 'http://localhost/index.php/avatar/owner/64',
							'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
						],
					],
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'hint' => 'hint TestSharePropertyType1',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'source_class' => null,
					'display_name' => 'Share with others',
					'hint' => null,
					'presets' => [],
					'enabled' => false,
					'priority' => 90,
				],
				[
					'class' => TestSharePermissionType1::class,
					'source_class' => TestShareSourceType1::class,
					'display_name' => 'TestSharePermissionType1',
					'hint' => 'hint TestSharePermissionType1',
					'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
					'enabled' => false,
					'priority' => 1,
				],
			],
			'permission_preset' => null,
		], $share);
	}

	public function testGetShareAsRecipientNotActive(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
	}

	public function testGetShareAsRecipientActive(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'instance' => null,
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
					'icon' => [
						'svg' => '<svg/>',
					],
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'instance' => null,
					'display_name' => 'Recipient 1',
					'icon' => [
						'svg' => '<svg/>',
					],
					'secret' => [
						'updatable' => false,
					],
					'initiator' => [
						'user_id' => 'owner',
						'instance' => null,
						'display_name' => 'Owner',
						'icon' => [
							'light' => 'http://localhost/index.php/avatar/owner/64',
							'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
						],
					],
				],
				[
					'class' => TestShareRecipientType2::class,
					'value' => 'recipient2',
					'instance' => null,
					'display_name' => 'Recipient 2',
					'icon' => [
						'svg' => '<svg/>',
					],
					'secret' => [
						'updatable' => false,
					],
					'initiator' => [
						'user_id' => 'owner',
						'instance' => null,
						'display_name' => 'Owner',
						'icon' => [
							'light' => 'http://localhost/index.php/avatar/owner/64',
							'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
						],
					],
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'hint' => 'hint TestSharePropertyType1',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'source_class' => null,
					'display_name' => 'Share with others',
					'hint' => null,
					'presets' => [],
					'enabled' => false,
					'priority' => 90,
				],
				[
					'class' => TestSharePermissionType1::class,
					'source_class' => TestShareSourceType1::class,
					'display_name' => 'TestSharePermissionType1',
					'hint' => 'hint TestSharePermissionType1',
					'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
					'enabled' => true,
					'priority' => 1,
				],
			],
			'permission_preset' => TestSharePermissionPreset1::class,
		], $share);
	}

	public function testGetShareAsRecipientWithArguments(): void {
		$this->registry->registerRecipientType(new TestShareRecipientTypeArguments());

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientTypeArguments::class, 'secret', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->user1, arguments: [TestShareRecipientTypeArguments::class => 'secret']), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'instance' => null,
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
					'icon' => [
						'svg' => '<svg/>',
					],
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientTypeArguments::class,
					'value' => 'secret',
					'instance' => null,
					'display_name' => 'secret',
					'icon' => null,
					'secret' => [
						'updatable' => false,
					],
					'initiator' => [
						'user_id' => 'owner',
						'instance' => null,
						'display_name' => 'Owner',
						'icon' => [
							'light' => 'http://localhost/index.php/avatar/owner/64',
							'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
						],
					],
				],
			],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'source_class' => null,
					'display_name' => 'Share with others',
					'hint' => null,
					'presets' => [],
					'enabled' => false,
					'priority' => 90,
				],
				[
					'class' => TestSharePermissionType1::class,
					'source_class' => TestShareSourceType1::class,
					'display_name' => 'TestSharePermissionType1',
					'hint' => 'hint TestSharePermissionType1',
					'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
					'enabled' => true,
					'priority' => 1,
				],
			],
			'permission_preset' => TestSharePermissionPreset1::class,
		], $share);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
	}

	public function testGetShareWithSecretNotActive(): void {
		$this->registry->registerRecipientType(new TestShareRecipientTypeArguments());

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientTypeArguments::class, 'secret', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));

		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb
			->select('recipient_secret')
			->from('sharing_share_recipients')
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)));
		/** @var false|string $secret */
		$secret = $qb->executeQuery()->fetchOne();
		$this->assertNotFalse($secret);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(secret: $secret), $id);
	}

	public function testGetShareWithSecretActive(): void {
		$this->registry->registerRecipientType(new TestShareRecipientTypeArguments());

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientTypeArguments::class, 'secret', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$after = $this->getTimestamp();

		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb
			->select('recipient_secret')
			->from('sharing_share_recipients')
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)));
		/** @var false|string $secret */
		$secret = $qb->executeQuery()->fetchOne();
		$this->assertNotFalse($secret);

		$share = $this->getShare(new ShareAccessContext(secret: $secret), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'instance' => null,
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
					'icon' => [
						'svg' => '<svg/>',
					],
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientTypeArguments::class,
					'value' => 'secret',
					'instance' => null,
					'display_name' => 'secret',
					'icon' => null,
					'secret' => [
						'updatable' => false,
					],
					'initiator' => [
						'user_id' => 'owner',
						'instance' => null,
						'display_name' => 'Owner',
						'icon' => [
							'light' => 'http://localhost/index.php/avatar/owner/64',
							'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
						],
					],
				],
			],
			'properties' => [],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'source_class' => null,
					'display_name' => 'Share with others',
					'hint' => null,
					'presets' => [],
					'enabled' => false,
					'priority' => 90,
				],
				[
					'class' => TestSharePermissionType1::class,
					'source_class' => TestShareSourceType1::class,
					'display_name' => 'TestSharePermissionType1',
					'hint' => 'hint TestSharePermissionType1',
					'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
					'enabled' => true,
					'priority' => 1,
				],
			],
			'permission_preset' => TestSharePermissionPreset1::class,
		], $share);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(), $id);
	}

	public function testGetShareAsNonRecipient(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
	}

	public function testGetShareAsRecipientFilteredProperties(): void {
		$this->registry->registerPropertyType(new TestSharePropertyTypeFilter(['visible', 'filtered']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyTypeFilter::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyTypeFilter::class, TestShareRecipientType1::class);

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeFilter::class, 'visible'));

		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'instance' => null,
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
					'icon' => [
						'svg' => '<svg/>',
					],
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'instance' => null,
					'display_name' => 'Recipient 1',
					'icon' => [
						'svg' => '<svg/>',
					],
					'secret' => [
						'updatable' => false,
					],
					'initiator' => [
						'user_id' => 'owner',
						'instance' => null,
						'display_name' => 'Owner',
						'icon' => [
							'light' => 'http://localhost/index.php/avatar/owner/64',
							'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
						],
					],
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'hint' => 'hint TestSharePropertyType1',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeFilter::class,
					'display_name' => 'TestSharePropertyTypeFilter',
					'hint' => 'hint TestSharePropertyTypeFilter',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => 'visible',
					'type' => 'enum',
					'valid_values' => ['visible', 'filtered'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'source_class' => null,
					'display_name' => 'Share with others',
					'hint' => null,
					'presets' => [],
					'enabled' => false,
					'priority' => 90,
				],
				[
					'class' => TestSharePermissionType1::class,
					'source_class' => TestShareSourceType1::class,
					'display_name' => 'TestSharePermissionType1',
					'hint' => 'hint TestSharePermissionType1',
					'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
					'enabled' => true,
					'priority' => 1,
				],
			],
			'permission_preset' => TestSharePermissionPreset1::class,
		], $share);

		$before = $this->getTimestamp();
		$this->manager->updateShareProperty($accessContext, $id, new ShareProperty(TestSharePropertyTypeFilter::class, 'filtered'));
		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->owner), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'instance' => null,
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
					'icon' => [
						'svg' => '<svg/>',
					],
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'instance' => null,
					'display_name' => 'Recipient 1',
					'icon' => [
						'svg' => '<svg/>',
					],
					'secret' => [
						'updatable' => false,
					],
					'initiator' => [
						'user_id' => 'owner',
						'instance' => null,
						'display_name' => 'Owner',
						'icon' => [
							'light' => 'http://localhost/index.php/avatar/owner/64',
							'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
						],
					],
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'hint' => 'hint TestSharePropertyType1',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeFilter::class,
					'display_name' => 'TestSharePropertyTypeFilter',
					'hint' => 'hint TestSharePropertyTypeFilter',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => 'filtered',
					'type' => 'enum',
					'valid_values' => ['visible', 'filtered'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'source_class' => null,
					'display_name' => 'Share with others',
					'hint' => null,
					'presets' => [],
					'enabled' => false,
					'priority' => 90,
				],
				[
					'class' => TestSharePermissionType1::class,
					'source_class' => TestShareSourceType1::class,
					'display_name' => 'TestSharePermissionType1',
					'hint' => 'hint TestSharePermissionType1',
					'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
					'enabled' => true,
					'priority' => 1,
				],
			],
			'permission_preset' => TestSharePermissionPreset1::class,
		], $share);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
	}

	public function testGetShareAsRecipientFilteredArguments(): void {
		$this->registry->registerPropertyType(new TestSharePropertyTypeFilter(['visible', 'filtered']));
		$this->registry->markPropertyTypeCompatibleWithSourceType(TestSharePropertyTypeFilter::class, TestShareSourceType1::class);
		$this->registry->markPropertyTypeCompatibleWithRecipientType(TestSharePropertyTypeFilter::class, TestShareRecipientType1::class);

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'instance' => null,
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
					'icon' => [
						'svg' => '<svg/>',
					],
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'instance' => null,
					'display_name' => 'Recipient 1',
					'icon' => [
						'svg' => '<svg/>',
					],
					'secret' => [
						'updatable' => false,
					],
					'initiator' => [
						'user_id' => 'owner',
						'instance' => null,
						'display_name' => 'Owner',
						'icon' => [
							'light' => 'http://localhost/index.php/avatar/owner/64',
							'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
						],
					],
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'hint' => 'hint TestSharePropertyType1',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeFilter::class,
					'display_name' => 'TestSharePropertyTypeFilter',
					'hint' => 'hint TestSharePropertyTypeFilter',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['visible', 'filtered'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'source_class' => null,
					'display_name' => 'Share with others',
					'hint' => null,
					'presets' => [],
					'enabled' => false,
					'priority' => 90,
				],
				[
					'class' => TestSharePermissionType1::class,
					'source_class' => TestShareSourceType1::class,
					'display_name' => 'TestSharePermissionType1',
					'hint' => 'hint TestSharePermissionType1',
					'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
					'enabled' => true,
					'priority' => 1,
				],
			],
			'permission_preset' => TestSharePermissionPreset1::class,
		], $share);

		$share = $this->getShare(new ShareAccessContext(currentUser: $this->owner, arguments: [TestSharePropertyTypeFilter::class => 'filtered']), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertEquals([
			'id' => $id,
			'owner' => [
				'user_id' => 'owner',
				'instance' => null,
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
			'state' => ShareState::Active->value,
			'sources' => [
				[
					'class' => TestShareSourceType1::class,
					'value' => 'source1',
					'display_name' => 'Source 1',
					'icon' => [
						'svg' => '<svg/>',
					],
				],
			],
			'recipients' => [
				[
					'class' => TestShareRecipientType1::class,
					'value' => 'recipient1',
					'instance' => null,
					'display_name' => 'Recipient 1',
					'icon' => [
						'svg' => '<svg/>',
					],
					'secret' => [
						'updatable' => false,
					],
					'initiator' => [
						'user_id' => 'owner',
						'instance' => null,
						'display_name' => 'Owner',
						'icon' => [
							'light' => 'http://localhost/index.php/avatar/owner/64',
							'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
						],
					],
				],
			],
			'properties' => [
				[
					'class' => TestSharePropertyType1::class,
					'display_name' => 'TestSharePropertyType1',
					'hint' => 'hint TestSharePropertyType1',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['valid1'],
				],
				[
					'class' => TestSharePropertyTypeFilter::class,
					'display_name' => 'TestSharePropertyTypeFilter',
					'hint' => 'hint TestSharePropertyTypeFilter',
					'priority' => 1,
					'advanced' => false,
					'required' => false,
					'value' => null,
					'type' => 'enum',
					'valid_values' => ['visible', 'filtered'],
				],
			],
			'permissions' => [
				[
					'class' => ReshareSharePermissionType::class,
					'source_class' => null,
					'display_name' => 'Share with others',
					'hint' => null,
					'presets' => [],
					'enabled' => false,
					'priority' => 90,
				],
				[
					'class' => TestSharePermissionType1::class,
					'source_class' => TestShareSourceType1::class,
					'display_name' => 'TestSharePermissionType1',
					'hint' => 'hint TestSharePermissionType1',
					'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
					'enabled' => true,
					'priority' => 1,
				],
			],
			'permission_preset' => TestSharePermissionPreset1::class,
		], $share);

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(currentUser: $this->user1, arguments: [TestSharePropertyTypeFilter::class => 'filtered']), $id);
	}

	/**
	 * @return list<array{bool}>
	 */
	public static function dataGetShareWithPublicSecret(): array {
		return [
			[true],
			[false],
		];
	}

	#[DataProvider('dataGetShareWithPublicSecret')]
	public function testGetShareWithPublicSecret(bool $isSecretPublic): void {
		$this->registry->clear();
		$this->registry->registerRecipientType(new TestShareRecipientType1(
			[
				'recipient1' => 'Recipient 1',
			],
			[],
			[],
		));
		$this->registry->registerRecipientType(new TestShareRecipientTypePublicSecret(
			[
				'recipient2' => 'Recipient 2',
			],
			[],
			$isSecretPublic,
			false,
		));

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientTypePublicSecret::class, 'recipient2', null));

		$after = $this->getTimestamp();

		$share = $this->getShare($accessContext, $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		unset($share['last_updated']);
		$this->assertIsList($share['recipients']);
		$this->assertCount(2, $share['recipients']);
		$this->assertEquals([
			'class' => TestShareRecipientType1::class,
			'value' => 'recipient1',
			'instance' => null,
			'display_name' => 'Recipient 1',
			'icon' => [
				'svg' => '<svg/>',
			],
			'secret' => [
				'updatable' => false,
			],
			'initiator' => [
				'user_id' => 'owner',
				'instance' => null,
				'display_name' => 'Owner',
				'icon' => [
					'light' => 'http://localhost/index.php/avatar/owner/64',
					'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
				],
			],
		], $share['recipients'][0]);
		$this->assertIsArray($share['recipients'][1]);
		if ($isSecretPublic) {
			$this->assertArrayHasKey('secret', $share['recipients'][1]);
			$this->assertIsArray($share['recipients'][1]['secret']);

			$this->assertArrayHasKey('updatable', $share['recipients'][1]['secret']);
			$this->assertFalse($share['recipients'][1]['secret']['updatable']);

			$this->assertArrayHasKey('value', $share['recipients'][1]['secret']);
			$this->assertIsString($share['recipients'][1]['secret']['value']);
			$this->assertNotEmpty($share['recipients'][1]['secret']['value']);

			$this->assertArrayHasKey('url', $share['recipients'][1]['secret']);
			$this->assertIsString($share['recipients'][1]['secret']['url']);
			$this->assertMatchesRegularExpression('/http:\/\/localhost\/index\.php\/s\/.+/', $share['recipients'][1]['secret']['url']);
		} else {
			$this->assertArrayNotHasKey('url', $share['recipients'][1]);
		}
	}

	public function testGetShareWithSecret(): void {
		$this->registry->clear();
		$this->registry->registerSourceType(new TestShareSourceType1(['source1' => 'Source']));
		$this->registry->registerRecipientType(new TestShareRecipientTypePublicSecret(
			[
				'recipient1' => 'Recipient 1',
				'recipient2' => 'Recipient 2',
				'recipient3' => 'Recipient 3',
				'recipient4' => 'Recipient 4',
			],
			[
				$this->user1->getUID() => ['recipient1'],
				$this->user2->getUID() => ['recipient2'],
			],
			true,
			false,
		));
		$this->registry->registerPermissionType(null, new ReshareSharePermissionType());

		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);

		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientTypePublicSecret::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientTypePublicSecret::class, 'recipient2', null));
		$this->manager->addShareRecipient(new ShareAccessContext($this->user1), $id, new ShareRecipient(TestShareRecipientTypePublicSecret::class, 'recipient3', null));
		$this->manager->addShareRecipient(new ShareAccessContext($this->user2), $id, new ShareRecipient(TestShareRecipientTypePublicSecret::class, 'recipient4', null));

		$after = $this->getTimestamp();

		$share = $this->getShare(new ShareAccessContext($this->user2), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);

		$this->assertArrayHasKey('recipients', $share);
		$this->assertIsArray($share['recipients']);
		$this->assertCount(4, $share['recipients']);

		// Parent - secret not visible
		$this->assertIsArray($share['recipients'][0]);
		$this->assertArrayHasKey('value', $share['recipients'][0]);
		$this->assertEquals('recipient1', $share['recipients'][0]['value']);
		$this->assertArrayHasKey('secret', $share['recipients'][0]);
		$this->assertIsArray($share['recipients'][0]['secret']);
		$this->assertArrayNotHasKey('value', $share['recipients'][0]['secret']);

		// Self - secret visible
		$this->assertIsArray($share['recipients'][1]);
		$this->assertArrayHasKey('value', $share['recipients'][1]);
		$this->assertEquals('recipient2', $share['recipients'][1]['value']);
		$this->assertArrayHasKey('secret', $share['recipients'][1]);
		$this->assertIsArray($share['recipients'][1]['secret']);
		$this->assertNotEmpty($share['recipients'][1]['secret']['value']);

		// Sibling - secret not visible
		$this->assertIsArray($share['recipients'][2]);
		$this->assertArrayHasKey('value', $share['recipients'][2]);
		$this->assertEquals('recipient3', $share['recipients'][2]['value']);
		$this->assertArrayHasKey('secret', $share['recipients'][2]);
		$this->assertIsArray($share['recipients'][2]['secret']);
		$this->assertArrayNotHasKey('value', $share['recipients'][2]['secret']);

		// Child - secret visible
		$this->assertIsArray($share['recipients'][3]);
		$this->assertArrayHasKey('value', $share['recipients'][3]);
		$this->assertEquals('recipient4', $share['recipients'][3]['value']);
		$this->assertArrayHasKey('secret', $share['recipients'][3]);
		$this->assertIsArray($share['recipients'][3]['secret']);
		$this->assertNotEmpty($share['recipients'][3]['secret']['value']);
	}

	public function testGetShareUniqueDisplayNames(): void {
		$this->registry->clear();
		$this->registry->registerSourceType(new TestShareSourceType1(['source1' => 'Source']));
		$this->registry->registerSourceType(new TestShareSourceType2(['source2' => 'Source', 'source3' => 'Other']));
		$this->registry->registerRecipientType(new TestShareRecipientType1(['recipient1' => 'Recipient'], [], []));
		$this->registry->registerRecipientType(new TestShareRecipientType2(['recipient2' => 'Recipient', 'recipient3' => 'Other'], [], []));

		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType2::class, 'source2'));
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType2::class, 'source3'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient3', null));

		$share = $this->getShare($accessContext, $id);
		$this->assertEquals([
			[
				'class' => TestShareSourceType1::class,
				'value' => 'source1',
				'display_name' => 'Source (TestShareSourceType1: source1)',
				'icon' => [
					'svg' => '<svg/>',
				],
			],
			[
				'class' => TestShareSourceType2::class,
				'value' => 'source2',
				'display_name' => 'Source (TestShareSourceType2: source2)',
				'icon' => [
					'svg' => '<svg/>',
				],
			],
			[
				'class' => TestShareSourceType2::class,
				'value' => 'source3',
				'display_name' => 'Other',
				'icon' => [
					'svg' => '<svg/>',
				],
			],
		], $share['sources']);
		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1',
				'instance' => null,
				'display_name' => 'Recipient (TestShareRecipientType1: recipient1)',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
			],
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient2',
				'instance' => null,
				'display_name' => 'Recipient (TestShareRecipientType2: recipient2)',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
			],
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient3',
				'instance' => null,
				'display_name' => 'Other',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
			],
		], $share['recipients']);
	}

	public function testGetShareDisabledOwner(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$after = $this->getTimestamp();

		$this->owner->setEnabled(false);

		try {
			$this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
			$this->fail('Share still visible.');
		} catch (Exception $exception) {
			$this->assertEquals('Share not found: ' . $id, $exception->getMessage());
		}

		$share = $this->getShare(new ShareAccessContext(overrideChecks: true), $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals([
			'user_id' => 'owner',
			'instance' => null,
			'display_name' => 'Owner',
			'icon' => [
				'light' => 'http://localhost/index.php/avatar/owner/64',
				'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
			],
		], $share['owner']);
	}

	public function testGetShareDeletedOwner(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->getShare($accessContext, $id);
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(TestSharePermissionType1::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);

		$this->owner->delete();

		try {
			$this->getShare(new ShareAccessContext(currentUser: $this->user1), $id);
			$this->fail('Share still exists.');
		} catch (Exception $exception) {
			$this->assertEquals('Share not found: ' . $id, $exception->getMessage());
		}

		$this->expectExceptionMessage('Share not found: ' . $id);
		$this->getShare(new ShareAccessContext(overrideChecks: true), $id);
	}

	public function testGetShareDisabledInitiator(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$before = $this->getTimestamp();
		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext(currentUser: $this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));

		$after = $this->getTimestamp();

		$this->user1->setEnabled(false);

		try {
			$this->getShare(new ShareAccessContext(currentUser: $this->user2), $id);
			$this->fail('Share still visible.');
		} catch (Exception $exception) {
			$this->assertEquals('Share not found: ' . $id, $exception->getMessage());
		}

		$share = $this->getShare($accessContext, $id);
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1',
				'instance' => null,
				'display_name' => 'Recipient 1',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
			],
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient2',
				'instance' => null,
				'display_name' => 'Recipient 2',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'user1',
					'instance' => null,
					'display_name' => 'User 1',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/user1/64',
						'dark' => 'http://localhost/index.php/avatar/user1/64/dark',
					],
				],
			],
		], $share['recipients']);
	}

	public function testGetShareDeletedInitiator(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$id = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->updateSharePermission($accessContext, $id, new SharePermission(ReshareSharePermissionType::class, true));
		$this->manager->updateShareState($accessContext, $id, ShareState::Active);
		$this->manager->addShareRecipient(new ShareAccessContext(currentUser: $this->user1), $id, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));

		$this->user1->delete();

		$before = $this->getTimestamp();
		$share = $this->getShare(new ShareAccessContext(currentUser: $this->user2), $id);
		$after = $this->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $share['last_updated']);
		$this->assertLessThanOrEqual($after, $share['last_updated']);
		$this->assertEquals([
			[
				'class' => TestShareRecipientType1::class,
				'value' => 'recipient1',
				'instance' => null,
				'display_name' => 'Recipient 1',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
			],
			[
				'class' => TestShareRecipientType2::class,
				'value' => 'recipient2',
				'instance' => null,
				'display_name' => 'Recipient 2',
				'icon' => [
					'svg' => '<svg/>',
				],
				'secret' => [
					'updatable' => false,
				],
				'initiator' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
			],
		], $share['recipients']);
	}

	public function testListShares(): void {
		$accessContext = new ShareAccessContext($this->owner);

		$before1 = $this->getTimestamp();
		$id1 = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id1, new ShareSource(TestShareSourceType1::class, 'source1'));
		$this->manager->addShareRecipient($accessContext, $id1, new ShareRecipient(TestShareRecipientType1::class, 'recipient1', null));
		$this->manager->getShare($accessContext, $id1);

		$after1 = $this->getTimestamp();

		$before2 = $this->getTimestamp();
		$id2 = $this->manager->createShare($accessContext);
		$this->manager->addShareSource($accessContext, $id2, new ShareSource(TestShareSourceType2::class, 'source2'));
		$this->manager->addShareRecipient($accessContext, $id2, new ShareRecipient(TestShareRecipientType2::class, 'recipient2', null));
		$this->manager->getShare($accessContext, $id2);

		$after2 = $this->getTimestamp();

		$shares = $this->listShares($accessContext, null, null, null, null);
		$this->assertCount(2, $shares);
		$this->assertIsArray($shares[0]);
		$this->assertGreaterThanOrEqual($before1, $shares[0]['last_updated']);
		$this->assertLessThanOrEqual($after1, $shares[0]['last_updated']);
		$this->assertIsArray($shares[1]);
		$this->assertGreaterThanOrEqual($before2, $shares[1]['last_updated']);
		$this->assertLessThanOrEqual($after2, $shares[1]['last_updated']);
		unset($shares[0]['last_updated'], $shares[1]['last_updated']);
		$this->assertEquals([
			[
				'id' => $id1,
				'owner' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType1::class,
						'value' => 'source1',
						'display_name' => 'Source 1',
						'icon' => [
							'svg' => '<svg/>',
						],
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType1::class,
						'value' => 'recipient1',
						'instance' => null,
						'display_name' => 'Recipient 1',
						'icon' => [
							'svg' => '<svg/>',
						],
						'secret' => [
							'updatable' => false,
						],
						'initiator' => [
							'user_id' => 'owner',
							'instance' => null,
							'display_name' => 'Owner',
							'icon' => [
								'light' => 'http://localhost/index.php/avatar/owner/64',
								'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
							],
						],
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType1::class,
						'display_name' => 'TestSharePropertyType1',
						'hint' => 'hint TestSharePropertyType1',
						'priority' => 1,
						'advanced' => false,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid1'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'source_class' => null,
						'display_name' => 'Share with others',
						'hint' => null,
						'presets' => [],
						'enabled' => false,
						'priority' => 90,
					],
					[
						'class' => TestSharePermissionType1::class,
						'source_class' => TestShareSourceType1::class,
						'display_name' => 'TestSharePermissionType1',
						'hint' => 'hint TestSharePermissionType1',
						'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
						'enabled' => false,
						'priority' => 1,
					],
				],
				'permission_preset' => null,
			],
			[
				'id' => $id2,
				'owner' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType2::class,
						'value' => 'source2',
						'display_name' => 'Source 2',
						'icon' => [
							'svg' => '<svg/>',
						],
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType2::class,
						'value' => 'recipient2',
						'instance' => null,
						'display_name' => 'Recipient 2',
						'icon' => [
							'svg' => '<svg/>',
						],
						'secret' => [
							'updatable' => false,
						],
						'initiator' => [
							'user_id' => 'owner',
							'instance' => null,
							'display_name' => 'Owner',
							'icon' => [
								'light' => 'http://localhost/index.php/avatar/owner/64',
								'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
							],
						],
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType2::class,
						'display_name' => 'TestSharePropertyType2',
						'hint' => 'hint TestSharePropertyType2',
						'priority' => 1,
						'advanced' => false,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid2'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'source_class' => null,
						'display_name' => 'Share with others',
						'hint' => null,
						'presets' => [],
						'enabled' => false,
						'priority' => 90,
					],
					[
						'class' => TestSharePermissionType2::class,
						'source_class' => TestShareSourceType2::class,
						'display_name' => 'TestSharePermissionType2',
						'hint' => 'hint TestSharePermissionType2',
						'presets' => [TestSharePermissionPreset2::class],
						'enabled' => false,
						'priority' => 1,
					],
				],
				'permission_preset' => null,
			],
		], $shares);

		$shares = $this->listShares($accessContext, TestShareSourceType1::class, null, null, null);
		$this->assertCount(1, $shares);
		$this->assertIsArray($shares[0]);
		$this->assertGreaterThanOrEqual($before1, $shares[0]['last_updated']);
		$this->assertLessThanOrEqual($after1, $shares[0]['last_updated']);
		unset($shares[0]['last_updated']);
		$this->assertEquals([
			[
				'id' => $id1,
				'owner' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType1::class,
						'value' => 'source1',
						'display_name' => 'Source 1',
						'icon' => [
							'svg' => '<svg/>',
						],
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType1::class,
						'value' => 'recipient1',
						'instance' => null,
						'display_name' => 'Recipient 1',
						'icon' => [
							'svg' => '<svg/>',
						],
						'secret' => [
							'updatable' => false,
						],
						'initiator' => [
							'user_id' => 'owner',
							'instance' => null,
							'display_name' => 'Owner',
							'icon' => [
								'light' => 'http://localhost/index.php/avatar/owner/64',
								'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
							],
						],
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType1::class,
						'display_name' => 'TestSharePropertyType1',
						'hint' => 'hint TestSharePropertyType1',
						'priority' => 1,
						'advanced' => false,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid1'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'source_class' => null,
						'display_name' => 'Share with others',
						'hint' => null,
						'presets' => [],
						'enabled' => false,
						'priority' => 90,
					],
					[
						'class' => TestSharePermissionType1::class,
						'source_class' => TestShareSourceType1::class,
						'display_name' => 'TestSharePermissionType1',
						'hint' => 'hint TestSharePermissionType1',
						'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
						'enabled' => false,
						'priority' => 1,
					],
				],
				'permission_preset' => null,
			],
		], $shares);

		$shares = $this->listShares($accessContext, TestShareSourceType1::class, 'source1', null, null);
		$this->assertCount(1, $shares);
		$this->assertIsArray($shares[0]);
		$this->assertGreaterThanOrEqual($before1, $shares[0]['last_updated']);
		$this->assertLessThanOrEqual($after1, $shares[0]['last_updated']);
		unset($shares[0]['last_updated']);
		$this->assertEquals([
			[
				'id' => $id1,
				'owner' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType1::class,
						'value' => 'source1',
						'display_name' => 'Source 1',
						'icon' => [
							'svg' => '<svg/>',
						],
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType1::class,
						'value' => 'recipient1',
						'instance' => null,
						'display_name' => 'Recipient 1',
						'icon' => [
							'svg' => '<svg/>',
						],
						'secret' => [
							'updatable' => false,
						],
						'initiator' => [
							'user_id' => 'owner',
							'instance' => null,
							'display_name' => 'Owner',
							'icon' => [
								'light' => 'http://localhost/index.php/avatar/owner/64',
								'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
							],
						],
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType1::class,
						'display_name' => 'TestSharePropertyType1',
						'hint' => 'hint TestSharePropertyType1',
						'priority' => 1,
						'advanced' => false,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid1'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'source_class' => null,
						'display_name' => 'Share with others',
						'hint' => null,
						'presets' => [],
						'enabled' => false,
						'priority' => 90,
					],
					[
						'class' => TestSharePermissionType1::class,
						'source_class' => TestShareSourceType1::class,
						'display_name' => 'TestSharePermissionType1',
						'hint' => 'hint TestSharePermissionType1',
						'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
						'enabled' => false,
						'priority' => 1,
					],
				],
				'permission_preset' => null,
			],
		], $shares);

		$shares = $this->listShares($accessContext, TestShareSourceType1::class, 'non-existent', null, null);
		$this->assertCount(0, $shares);

		$shares = $this->listShares($accessContext, null, null, $id1, null);
		$this->assertCount(1, $shares);
		$this->assertIsArray($shares[0]);
		$this->assertGreaterThanOrEqual($before2, $shares[0]['last_updated']);
		$this->assertLessThanOrEqual($after2, $shares[0]['last_updated']);
		unset($shares[0]['last_updated']);
		$this->assertEquals([
			[
				'id' => $id2,
				'owner' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType2::class,
						'value' => 'source2',
						'display_name' => 'Source 2',
						'icon' => [
							'svg' => '<svg/>',
						],
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType2::class,
						'value' => 'recipient2',
						'instance' => null,
						'display_name' => 'Recipient 2',
						'icon' => [
							'svg' => '<svg/>',
						],
						'secret' => [
							'updatable' => false,
						],
						'initiator' => [
							'user_id' => 'owner',
							'instance' => null,
							'display_name' => 'Owner',
							'icon' => [
								'light' => 'http://localhost/index.php/avatar/owner/64',
								'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
							],
						],
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType2::class,
						'display_name' => 'TestSharePropertyType2',
						'hint' => 'hint TestSharePropertyType2',
						'priority' => 1,
						'advanced' => false,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid2'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'source_class' => null,
						'display_name' => 'Share with others',
						'hint' => null,
						'presets' => [],
						'enabled' => false,
						'priority' => 90,
					],
					[
						'class' => TestSharePermissionType2::class,
						'source_class' => TestShareSourceType2::class,
						'display_name' => 'TestSharePermissionType2',
						'hint' => 'hint TestSharePermissionType2',
						'presets' => [TestSharePermissionPreset2::class],
						'enabled' => false,
						'priority' => 1,
					],
				],
				'permission_preset' => null,
			],
		], $shares);

		$shares = $this->listShares($accessContext, null, null, null, 1);
		$this->assertCount(1, $shares);
		$this->assertIsArray($shares[0]);
		$this->assertGreaterThanOrEqual($before1, $shares[0]['last_updated']);
		$this->assertLessThanOrEqual($after1, $shares[0]['last_updated']);
		unset($shares[0]['last_updated']);
		$this->assertEquals([
			[
				'id' => $id1,
				'owner' => [
					'user_id' => 'owner',
					'instance' => null,
					'display_name' => 'Owner',
					'icon' => [
						'light' => 'http://localhost/index.php/avatar/owner/64',
						'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
					],
				],
				'state' => ShareState::Draft->value,
				'sources' => [
					[
						'class' => TestShareSourceType1::class,
						'value' => 'source1',
						'display_name' => 'Source 1',
						'icon' => [
							'svg' => '<svg/>',
						],
					],
				],
				'recipients' => [
					[
						'class' => TestShareRecipientType1::class,
						'value' => 'recipient1',
						'instance' => null,
						'display_name' => 'Recipient 1',
						'icon' => [
							'svg' => '<svg/>',
						],
						'secret' => [
							'updatable' => false,
						],
						'initiator' => [
							'user_id' => 'owner',
							'instance' => null,
							'display_name' => 'Owner',
							'icon' => [
								'light' => 'http://localhost/index.php/avatar/owner/64',
								'dark' => 'http://localhost/index.php/avatar/owner/64/dark',
							],
						],
					],
				],
				'properties' => [
					[
						'class' => TestSharePropertyType1::class,
						'display_name' => 'TestSharePropertyType1',
						'hint' => 'hint TestSharePropertyType1',
						'priority' => 1,
						'advanced' => false,
						'required' => false,
						'value' => null,
						'type' => 'enum',
						'valid_values' => ['valid1'],
					],
				],
				'permissions' => [
					[
						'class' => ReshareSharePermissionType::class,
						'source_class' => null,
						'display_name' => 'Share with others',
						'hint' => null,
						'presets' => [],
						'enabled' => false,
						'priority' => 90,
					],
					[
						'class' => TestSharePermissionType1::class,
						'source_class' => TestShareSourceType1::class,
						'display_name' => 'TestSharePermissionType1',
						'hint' => 'hint TestSharePermissionType1',
						'presets' => [TestSharePermissionPreset1::class, TestSharePermissionPreset2::class],
						'enabled' => false,
						'priority' => 1,
					],
				],
				'permission_preset' => null,
			],
		], $shares);
	}
}
