<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdminAudit\Listener;

use OCA\AdminAudit\Actions\Action;
use OCA\Files_Versions\Events\VersionRestoredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Preview\BeforePreviewFetchedEvent;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<BeforePreviewFetchedEvent|VersionRestoredEvent>
 */
class FileEventListener extends Action implements IEventListener {
	public function handle(Event $event): void {
		if ($event instanceof BeforePreviewFetchedEvent) {
			$this->beforePreviewFetched($event);
		} elseif ($event instanceof VersionRestoredEvent) {
			$this->versionRestored($event);
		}
	}

	/**
	 * Logs preview access to a file
	 */
	private function beforePreviewFetched(BeforePreviewFetchedEvent $event): void {
		try {
			$file = $event->getNode();
			$params = [
				'id' => $file->getId(),
				'width' => $event->getWidth(),
				'height' => $event->getHeight(),
				'crop' => $event->isCrop(),
				'mode' => $event->getMode(),
				'path' => $file->getPath(),
			];
			$this->log(
				'Preview accessed: (id: "%s", width: "%s", height: "%s" crop: "%s", mode: "%s", path: "%s")',
				$params,
				array_keys($params)
			);
		} catch (InvalidPathException|NotFoundException $e) {
			Server::get(LoggerInterface::class)->error(
				'Exception thrown in file preview: ' . $e->getMessage(), ['app' => 'admin_audit', 'exception' => $e]
			);
			return;
		}
	}

	/**
	 * Logs when a version is restored
	 */
	private function versionRestored(VersionRestoredEvent $event): void {
		$version = $event->getVersion();
		$this->log('Version "%s" of "%s" was restored.',
			[
				'version' => $version->getRevisionId(),
				'path' => $version->getVersionPath()
			],
			['version', 'path']
		);
	}
}
