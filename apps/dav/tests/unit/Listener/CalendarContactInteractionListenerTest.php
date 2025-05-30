<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\Tests\unit\Listener;

use OCA\DAV\Connector\Sabre\Principal;
use OCA\DAV\Events\CalendarShareUpdatedEvent;
use OCA\DAV\Listener\CalendarContactInteractionListener;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Contacts\Events\ContactInteractedWithEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class CalendarContactInteractionListenerTest extends TestCase {
	private IEventDispatcher&MockObject $eventDispatcher;
	private IUserSession&MockObject $userSession;
	private Principal&MockObject $principalConnector;
	private LoggerInterface&MockObject $logger;
	private IMailer&MockObject $mailer;
	private CalendarContactInteractionListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->principalConnector = $this->createMock(Principal::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new CalendarContactInteractionListener(
			$this->eventDispatcher,
			$this->userSession,
			$this->principalConnector,
			$this->mailer,
			$this->logger
		);
	}

	public function testParseUnrelated(): void {
		$event = new Event();
		$this->eventDispatcher->expects(self::never())->method('dispatchTyped');

		$this->listener->handle($event);
	}

	public function testHandleWithoutAnythingInteresting(): void {
		$event = new CalendarShareUpdatedEvent(123, [], [], [], []);
		$user = $this->createMock(IUser::class);
		$this->userSession->expects(self::once())->method('getUser')->willReturn($user);
		$this->eventDispatcher->expects(self::never())->method('dispatchTyped');

		$this->listener->handle($event);
	}

	public function testParseInvalidData(): void {
		$event = new CalendarObjectCreatedEvent(123, [], [], ['calendardata' => 'BEGIN:FOO']);
		$user = $this->createMock(IUser::class);
		$this->userSession->expects(self::once())->method('getUser')->willReturn($user);
		$this->eventDispatcher->expects(self::never())->method('dispatchTyped');
		$this->logger->expects(self::once())->method('warning');

		$this->listener->handle($event);
	}

	public function testParseCalendarEventWithInvalidEmail(): void {
		$event = new CalendarObjectCreatedEvent(123, [], [], ['calendardata' => <<<EVENT
BEGIN:VCALENDAR
VERSION:2.0
CALSCALE:GREGORIAN
PRODID:-//IDN nextcloud.com//Calendar app 2.1.3//EN
BEGIN:VTIMEZONE
TZID:Europe/Vienna
BEGIN:DAYLIGHT
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
DTSTART:19700329T020000
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
END:DAYLIGHT
BEGIN:STANDARD
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
DTSTART:19701025T030000
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
CREATED:20210202T091151Z
DTSTAMP:20210203T130231Z
LAST-MODIFIED:20210203T130231Z
SEQUENCE:9
UID:b74a0c8e-93b0-447f-aed5-b679b19e874a
DTSTART;TZID=Europe/Vienna:20210202T103000
DTEND;TZID=Europe/Vienna:20210202T133000
SUMMARY:tes
ORGANIZER;CN=admin:mailto:christoph.wurst@nextcloud.com
ATTENDEE;CN=somethingbutnotanemail;CUTYPE=INDIVIDUAL;PARTSTAT=NEEDS-ACTION;
 ROLE=REQ-PARTICIPANT;RSVP=FALSE:mailto:somethingbutnotanemail
DESCRIPTION:test
END:VEVENT
END:VCALENDAR
EVENT]);
		$user = $this->createMock(IUser::class);
		$this->userSession->expects(self::once())->method('getUser')->willReturn($user);
		$this->eventDispatcher->expects(self::never())->method('dispatchTyped');
		$this->logger->expects(self::never())->method('warning');

		$this->listener->handle($event);
	}

	public function testParseCalendarEvent(): void {
		$event = new CalendarObjectCreatedEvent(123, [], [], ['calendardata' => <<<EVENT
BEGIN:VCALENDAR
VERSION:2.0
CALSCALE:GREGORIAN
PRODID:-//IDN nextcloud.com//Calendar app 2.1.3//EN
BEGIN:VTIMEZONE
TZID:Europe/Vienna
BEGIN:DAYLIGHT
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
DTSTART:19700329T020000
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
END:DAYLIGHT
BEGIN:STANDARD
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
DTSTART:19701025T030000
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
CREATED:20210202T091151Z
DTSTAMP:20210203T130231Z
LAST-MODIFIED:20210203T130231Z
SEQUENCE:9
UID:b74a0c8e-93b0-447f-aed5-b679b19e874a
DTSTART;TZID=Europe/Vienna:20210202T103000
DTEND;TZID=Europe/Vienna:20210202T133000
SUMMARY:tes
ORGANIZER;CN=admin:mailto:christoph.wurst@nextcloud.com
ATTENDEE;CN=user@domain.tld;CUTYPE=INDIVIDUAL;PARTSTAT=NEEDS-ACTION;
 ROLE=REQ-PARTICIPANT;RSVP=FALSE:mailto:user@domain.tld
DESCRIPTION:test
END:VEVENT
END:VCALENDAR
EVENT]);
		$user = $this->createMock(IUser::class);
		$this->userSession->expects(self::once())->method('getUser')->willReturn($user);
		$this->mailer->expects(self::once())->method('validateMailAddress')->willReturn(true);
		$this->eventDispatcher->expects(self::once())
			->method('dispatchTyped')
			->with(self::equalTo((new ContactInteractedWithEvent($user))->setEmail('user@domain.tld')));
		$this->logger->expects(self::never())->method('warning');

		$this->listener->handle($event);
	}
}
