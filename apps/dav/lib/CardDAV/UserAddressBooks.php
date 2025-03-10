<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\CardDAV;

use OCA\DAV\AppInfo\PluginManager;
use OCA\DAV\CardDAV\Integration\ExternalAddressBook;
use OCA\DAV\CardDAV\Integration\IAddressBookProvider;
use OCA\Federation\TrustedServers;
use OCP\AppFramework\QueryException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sabre\CardDAV\Backend;
use Sabre\CardDAV\IAddressBook;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\MkCol;
use function array_map;

class UserAddressBooks extends \Sabre\CardDAV\AddressBookHome {
	/** @var IL10N */
	protected $l10n;

	/** @var IConfig */
	protected $config;

	public function __construct(
		Backend\BackendInterface $carddavBackend,
		string $principalUri,
		private PluginManager $pluginManager,
		private ?IUser $user,
		private ?IGroupManager $groupManager,
	) {
		parent::__construct($carddavBackend, $principalUri);
	}

	/**
	 * Returns a list of address books
	 *
	 * @return IAddressBook[]
	 */
	public function getChildren() {
		if ($this->l10n === null) {
			$this->l10n = \OC::$server->getL10N('dav');
		}
		if ($this->config === null) {
			$this->config = Server::get(IConfig::class);
		}

		/** @var string|array $principal */
		$principal = $this->principalUri;
		$addressBooks = $this->carddavBackend->getAddressBooksForUser($this->principalUri);
		// add the system address book
		$systemAddressBook = null;
		$systemAddressBookExposed = $this->config->getAppValue('dav', 'system_addressbook_exposed', 'yes') === 'yes';
		if ($systemAddressBookExposed && is_string($principal) && $principal !== 'principals/system/system' && $this->carddavBackend instanceof CardDavBackend) {
			$systemAddressBook = $this->carddavBackend->getAddressBooksByUri('principals/system/system', 'system');
			if ($systemAddressBook !== null) {
				$systemAddressBook['uri'] = SystemAddressbook::URI_SHARED;
			}
		}
		if (!is_null($systemAddressBook)) {
			$addressBooks[] = $systemAddressBook;
		}

		$objects = [];
		if (!empty($addressBooks)) {
			/** @var IAddressBook[] $objects */
			$objects = array_map(function (array $addressBook) {
				$trustedServers = null;
				$request = null;
				try {
					$trustedServers = Server::get(TrustedServers::class);
					$request = Server::get(IRequest::class);
				} catch (QueryException|NotFoundExceptionInterface|ContainerExceptionInterface $e) {
					// nothing to do, the request / trusted servers don't exist
				}
				if ($addressBook['principaluri'] === 'principals/system/system') {
					return new SystemAddressbook(
						$this->carddavBackend,
						$addressBook,
						$this->l10n,
						$this->config,
						Server::get(IUserSession::class),
						$request,
						$trustedServers,
						$this->groupManager
					);
				}

				return new AddressBook($this->carddavBackend, $addressBook, $this->l10n);
			}, $addressBooks);
		}
		/** @var IAddressBook[][] $objectsFromPlugins */
		$objectsFromPlugins = array_map(function (IAddressBookProvider $plugin): array {
			return $plugin->fetchAllForAddressBookHome($this->principalUri);
		}, $this->pluginManager->getAddressBookPlugins());

		return array_merge($objects, ...$objectsFromPlugins);
	}

	public function createExtendedCollection($name, MkCol $mkCol) {
		if (ExternalAddressBook::doesViolateReservedName($name)) {
			throw new MethodNotAllowed('The resource you tried to create has a reserved name');
		}

		parent::createExtendedCollection($name, $mkCol);
	}

	/**
	 * Returns a list of ACE's for this node.
	 *
	 * Each ACE has the following properties:
	 *   * 'privilege', a string such as {DAV:}read or {DAV:}write. These are
	 *     currently the only supported privileges
	 *   * 'principal', a url to the principal who owns the node
	 *   * 'protected' (optional), indicating that this ACE is not allowed to
	 *      be updated.
	 *
	 * @return array
	 */
	public function getACL() {
		$acl = parent::getACL();
		if ($this->principalUri === 'principals/system/system') {
			$acl[] = [
				'privilege' => '{DAV:}read',
				'principal' => '{DAV:}authenticated',
				'protected' => true,
			];
		}

		return $acl;
	}
}
