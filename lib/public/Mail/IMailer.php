<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCP\Mail;

/**
 * Class IMailer provides some basic functions to create a mail message that can be used in combination with
 * \OC\Mail\Message.
 *
 * Example usage:
 *
 * 	$mailer = \OCP\Server::get(\OCP\Mail\IMailer::class);
 * 	$message = $mailer->createMessage();
 * 	$message->setSubject('Your Subject');
 * 	$message->setFrom(['cloud@domain.org' => 'Nextcloud Notifier']);
 * 	$message->setTo(['recipient@domain.org' => 'Recipient']);
 * 	$message->setPlainBody('The message text');
 * 	$message->setHtmlBody('The <strong>message</strong> text');
 * 	$mailer->send($message);
 *
 * This message can then be passed to send() of \OC\Mail\Mailer
 *
 * @since 8.1.0
 */
interface IMailer {
	/**
	 * Creates a new message object that can be passed to send()
	 *
	 * @return IMessage
	 * @since 8.1.0
	 */
	public function createMessage(): IMessage;

	/**
	 * @param string|null $data
	 * @param string|null $filename
	 * @param string|null $contentType
	 * @return IAttachment
	 * @since 13.0.0
	 */
	public function createAttachment($data = null, $filename = null, $contentType = null): IAttachment;

	/**
	 * @param string $path
	 * @param string|null $contentType
	 * @return IAttachment
	 * @since 13.0.0
	 */
	public function createAttachmentFromPath(string $path, $contentType = null): IAttachment;

	/**
	 * Creates a new email template object
	 *
	 * @param string $emailId
	 * @param array $data
	 * @return IEMailTemplate
	 * @since 12.0.0 Parameters added in 12.0.3
	 */
	public function createEMailTemplate(string $emailId, array $data = []): IEMailTemplate;

	/**
	 * Send the specified message. Also sets the from address to the value defined in config.php
	 * if no-one has been passed.
	 *
	 * @param IMessage $message Message to send
	 * @return string[] Array with failed recipients. Be aware that this depends on the used mail backend and
	 *                  therefore should be considered
	 * @throws \Exception In case it was not possible to send the message. (for example if an invalid mail address
	 *                    has been supplied.)
	 * @since 8.1.0
	 */
	public function send(IMessage $message): array;

	/**
	 * @param string $email Email address to be validated
	 * @return bool True if the mail address is valid, false otherwise
	 * @since 8.1.0
	 */
	public function validateMailAddress(string $email): bool;
}
