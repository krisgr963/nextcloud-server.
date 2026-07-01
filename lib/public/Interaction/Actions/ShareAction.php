<?php

/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCP\Interaction\Actions;

use OC\Sharing\Permission\ReshareSharePermissionType;
use OCA\Files\Sharing\Permission\NodeCreateSharePermissionType;
use OCA\Files\Sharing\Permission\NodeDeleteSharePermissionType;
use OCA\Files\Sharing\Permission\NodeReadSharePermissionType;
use OCA\Files\Sharing\Permission\NodeUpdateSharePermissionType;
use OCP\AppFramework\Attribute\Consumable;
use OCP\Constants;
use OCP\Interaction\InteractionAction;
use OCP\Sharing\Permission\ISharePermissionType;

/**
 * Used when a user wants to share a resource to a receiver.
 *
 * @since 34.0.2
 */
#[Consumable(since: '34.0.2')]
final readonly class ShareAction implements InteractionAction {
	/**
	 * @since 34.0.2
	 */
	public function __construct(
		/** @var ?int-mask-of<Constants::PERMISSION_*> */
		public ?int $filesSharingPermissions = null,
		/** @var ?list<class-string<ISharePermissionType>> */
		public ?array $unifiedSharingPermissions = null,
	) {
	}

	/**
	 * @return ?int-mask-of<Constants::PERMISSION_*>
	 * @since 35.0.0
	 */
	public function unifiedSharingPermissionsAsFilesSharingPermission(): ?int {
		if ($this->unifiedSharingPermissions === null) {
			return null;
		}

		$permissions = 0;
		if (in_array(NodeReadSharePermissionType::class, $this->unifiedSharingPermissions, true)) {
			$permissions |= Constants::PERMISSION_READ;
		}

		if (in_array(NodeUpdateSharePermissionType::class, $this->unifiedSharingPermissions, true)) {
			$permissions |= Constants::PERMISSION_UPDATE;
		}

		if (in_array(NodeCreateSharePermissionType::class, $this->unifiedSharingPermissions, true)) {
			$permissions |= Constants::PERMISSION_CREATE;
		}

		if (in_array(NodeDeleteSharePermissionType::class, $this->unifiedSharingPermissions, true)) {
			$permissions |= Constants::PERMISSION_DELETE;
		}

		if (in_array(ReshareSharePermissionType::class, $this->unifiedSharingPermissions, true)) {
			$permissions |= Constants::PERMISSION_SHARE;
		}

		return $permissions;
	}
}
