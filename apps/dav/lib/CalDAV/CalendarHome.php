<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\CalDAV;

use OCA\DAV\AppInfo\PluginManager;
use OCA\DAV\CalDAV\Integration\ExternalCalendar;
use OCA\DAV\CalDAV\Integration\ICalendarProvider;
use OCA\DAV\CalDAV\Trashbin\TrashbinHome;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Sabre\CalDAV\Backend\BackendInterface;
use Sabre\CalDAV\Backend\NotificationSupport;
use Sabre\CalDAV\Backend\SchedulingSupport;
use Sabre\CalDAV\Backend\SubscriptionSupport;
use Sabre\CalDAV\Schedule\Inbox;
use Sabre\CalDAV\Subscriptions\Subscription;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\INode;
use Sabre\DAV\MkCol;

class CalendarHome extends \Sabre\CalDAV\CalendarHome {

	/** @var IL10N */
	private $l10n;

	/** @var IConfig */
	private $config;

	/** @var PluginManager */
	private $pluginManager;
	private ?array $cachedChildren = null;

	public function __construct(
		BackendInterface $caldavBackend,
		array $principalInfo,
		private LoggerInterface $logger,
		private bool $returnCachedSubscriptions,
	) {
		parent::__construct($caldavBackend, $principalInfo);
		$this->l10n = \OC::$server->getL10N('dav');
		$this->config = Server::get(IConfig::class);
		$this->pluginManager = new PluginManager(
			\OC::$server,
			Server::get(IAppManager::class)
		);
	}

	/**
	 * @return BackendInterface
	 */
	public function getCalDAVBackend() {
		return $this->caldavBackend;
	}

	/**
	 * @inheritdoc
	 */
	public function createExtendedCollection($name, MkCol $mkCol): void {
		$reservedNames = [
			BirthdayService::BIRTHDAY_CALENDAR_URI,
			TrashbinHome::NAME,
		];

		if (\in_array($name, $reservedNames, true) || ExternalCalendar::doesViolateReservedName($name)) {
			throw new MethodNotAllowed('The resource you tried to create has a reserved name');
		}

		parent::createExtendedCollection($name, $mkCol);
	}

	/**
	 * @inheritdoc
	 */
	public function getChildren() {
		if ($this->cachedChildren) {
			return $this->cachedChildren;
		}
		$calendars = $this->caldavBackend->getCalendarsForUser($this->principalInfo['uri']);
		$objects = [];
		foreach ($calendars as $calendar) {
			$objects[] = new Calendar($this->caldavBackend, $calendar, $this->l10n, $this->config, $this->logger);
		}

		if ($this->caldavBackend instanceof SchedulingSupport) {
			$objects[] = new Inbox($this->caldavBackend, $this->principalInfo['uri']);
			$objects[] = new Outbox($this->config, $this->principalInfo['uri']);
		}

		// We're adding a notifications node, if it's supported by the backend.
		if ($this->caldavBackend instanceof NotificationSupport) {
			$objects[] = new \Sabre\CalDAV\Notifications\Collection($this->caldavBackend, $this->principalInfo['uri']);
		}

		if ($this->caldavBackend instanceof CalDavBackend) {
			$objects[] = new TrashbinHome($this->caldavBackend, $this->principalInfo);
		}

		// If the backend supports subscriptions, we'll add those as well,
		if ($this->caldavBackend instanceof SubscriptionSupport) {
			foreach ($this->caldavBackend->getSubscriptionsForUser($this->principalInfo['uri']) as $subscription) {
				if ($this->returnCachedSubscriptions) {
					$objects[] = new CachedSubscription($this->caldavBackend, $subscription);
				} else {
					$objects[] = new Subscription($this->caldavBackend, $subscription);
				}
			}
		}

		foreach ($this->pluginManager->getCalendarPlugins() as $calendarPlugin) {
			/** @var ICalendarProvider $calendarPlugin */
			$calendars = $calendarPlugin->fetchAllForCalendarHome($this->principalInfo['uri']);
			foreach ($calendars as $calendar) {
				$objects[] = $calendar;
			}
		}

		$this->cachedChildren = $objects;
		return $objects;
	}

	/**
	 * @param string $name
	 *
	 * @return INode
	 */
	public function getChild($name) {
		// Special nodes
		if ($name === 'inbox' && $this->caldavBackend instanceof SchedulingSupport) {
			return new Inbox($this->caldavBackend, $this->principalInfo['uri']);
		}
		if ($name === 'outbox' && $this->caldavBackend instanceof SchedulingSupport) {
			return new Outbox($this->config, $this->principalInfo['uri']);
		}
		if ($name === 'notifications' && $this->caldavBackend instanceof NotificationSupport) {
			return new \Sabre\CalDAV\Notifications\Collection($this->caldavBackend, $this->principalInfo['uri']);
		}
		if ($name === TrashbinHome::NAME && $this->caldavBackend instanceof CalDavBackend) {
			return new TrashbinHome($this->caldavBackend, $this->principalInfo);
		}

		// Calendar - this covers all "regular" calendars, but not shared
		// only check if the method is available
		if ($this->caldavBackend instanceof CalDavBackend) {
			$calendar = $this->caldavBackend->getCalendarByUri($this->principalInfo['uri'], $name);
			if (!empty($calendar)) {
				return new Calendar($this->caldavBackend, $calendar, $this->l10n, $this->config, $this->logger);
			}
		}

		// Fallback to cover shared calendars
		foreach ($this->caldavBackend->getCalendarsForUser($this->principalInfo['uri']) as $calendar) {
			if ($calendar['uri'] === $name) {
				return new Calendar($this->caldavBackend, $calendar, $this->l10n, $this->config, $this->logger);
			}
		}

		if ($this->caldavBackend instanceof SubscriptionSupport) {
			foreach ($this->caldavBackend->getSubscriptionsForUser($this->principalInfo['uri']) as $subscription) {
				if ($subscription['uri'] === $name) {
					if ($this->returnCachedSubscriptions) {
						return new CachedSubscription($this->caldavBackend, $subscription);
					}

					return new Subscription($this->caldavBackend, $subscription);
				}
			}
		}

		if (ExternalCalendar::isAppGeneratedCalendar($name)) {
			[$appId, $calendarUri] = ExternalCalendar::splitAppGeneratedCalendarUri($name);

			foreach ($this->pluginManager->getCalendarPlugins() as $calendarPlugin) {
				/** @var ICalendarProvider $calendarPlugin */
				if ($calendarPlugin->getAppId() !== $appId) {
					continue;
				}

				if ($calendarPlugin->hasCalendarInCalendarHome($this->principalInfo['uri'], $calendarUri)) {
					return $calendarPlugin->getCalendarInCalendarHome($this->principalInfo['uri'], $calendarUri);
				}
			}
		}

		throw new NotFound('Node with name \'' . $name . '\' could not be found');
	}

	/**
	 * @param array $filters
	 * @param integer|null $limit
	 * @param integer|null $offset
	 */
	public function calendarSearch(array $filters, $limit = null, $offset = null) {
		$principalUri = $this->principalInfo['uri'];
		return $this->caldavBackend->calendarSearch($principalUri, $filters, $limit, $offset);
	}
}
