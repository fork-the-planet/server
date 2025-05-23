<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\Files;

use OC\AppFramework\Http\Request;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\MaxDelayReached;
use OCP\Template\ITemplateManager;
use Sabre\DAV\Exception;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

class BrowserErrorPagePlugin extends ServerPlugin {
	/** @var Server */
	private $server;

	/**
	 * This initializes the plugin.
	 *
	 * This function is called by Sabre\DAV\Server, after
	 * addPlugin is called.
	 *
	 * This method should set up the required event subscriptions.
	 *
	 * @param Server $server
	 * @return void
	 */
	public function initialize(Server $server) {
		$this->server = $server;
		$server->on('exception', [$this, 'logException'], 1000);
	}

	/**
	 * @param IRequest $request
	 * @return bool
	 */
	public static function isBrowserRequest(IRequest $request) {
		if ($request->getMethod() !== 'GET') {
			return false;
		}
		return $request->isUserAgent([
			Request::USER_AGENT_IE,
			Request::USER_AGENT_MS_EDGE,
			Request::USER_AGENT_CHROME,
			Request::USER_AGENT_FIREFOX,
			Request::USER_AGENT_SAFARI,
		]);
	}

	/**
	 * @param \Throwable $ex
	 */
	public function logException(\Throwable $ex): void {
		if ($ex instanceof Exception) {
			$httpCode = $ex->getHTTPCode();
			$headers = $ex->getHTTPHeaders($this->server);
		} elseif ($ex instanceof MaxDelayReached) {
			$httpCode = 429;
			$headers = [];
		} else {
			$httpCode = 500;
			$headers = [];
		}
		$this->server->httpResponse->addHeaders($headers);
		$this->server->httpResponse->setStatus($httpCode);
		$body = $this->generateBody($httpCode);
		$this->server->httpResponse->setBody($body);
		$csp = new ContentSecurityPolicy();
		$this->server->httpResponse->addHeader('Content-Security-Policy', $csp->buildPolicy());
		$this->sendResponse();
	}

	/**
	 * @codeCoverageIgnore
	 * @return bool|string
	 */
	public function generateBody(int $httpCode) {
		$request = \OCP\Server::get(IRequest::class);

		$templateName = 'exception';
		if ($httpCode === 403 || $httpCode === 404 || $httpCode === 429) {
			$templateName = (string)$httpCode;
		}

		$content = \OCP\Server::get(ITemplateManager::class)->getTemplate('core', $templateName, TemplateResponse::RENDER_AS_GUEST);
		$content->assign('title', $this->server->httpResponse->getStatusText());
		$content->assign('remoteAddr', $request->getRemoteAddress());
		$content->assign('requestID', $request->getId());
		return $content->fetchPage();
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function sendResponse() {
		$this->server->sapi->sendResponse($this->server->httpResponse);
		exit();
	}
}
