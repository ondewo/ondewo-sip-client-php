<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Generated;

use Google\Protobuf\Timestamp;
use Ondewo\Sip\SipStartSessionRequest;
use Ondewo\Sip\SipStatus;
use Ondewo\Sip\SipStatus\StatusType;
use Ondewo\Sip\SipStatusHistoryResponse;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Wire-level exercise of the generated messages. These are the assertions that catch a broken
 * generator: a field that is declared but never written, a sub-message that loses its presence
 * bit, an enum whose zero constant moved.
 *
 * PRODUCT-SPECIFIC: the message and enum names below come from ondewo-sip-api.
 *
 * There is deliberately NO proto3 explicit-presence case here: ondewo-sip-api declares no
 * `optional` field anywhere in its protos, so the corresponding case of the pilot suite
 * (ondewo-nlu-client-php) has nothing to assert against and was dropped rather than faked.
 */
final class MessageSerializationTest extends TestCase
{
    public function testAMessageSurvivesABinaryRoundTrip(): void
    {
        $status = new SipStatus();
        $status->setAccountName('sip:4242@ondewo.com');
        $status->setCalleeId('sip:1234@ondewo.com');
        $status->setTransferCallId('transfer-7');
        $status->setStatusType(StatusType::OUTGOING_CALL_CONNECTED);
        $status->setDescription('call connected');
        $status->setNluSessionName('projects/6b2c8e5a/agent/sessions/1');
        $status->setHeaders(['X-Ondewo-Request-Id' => '42']);
        $status->setTimestamp(new Timestamp(['seconds' => 1700000000, 'nanos' => 123]));

        $history = new SipStatusHistoryResponse();
        $history->setStatusHistory([$status]);

        $bytes = $history->serializeToString();
        self::assertNotSame('', $bytes, 'a populated message serialised to zero bytes');

        $parsed = new SipStatusHistoryResponse();
        $parsed->mergeFromString($bytes);

        $entries = iterator_to_array($parsed->getStatusHistory());
        self::assertCount(1, $entries);

        $entry = $entries[0];
        self::assertSame('sip:4242@ondewo.com', $entry->getAccountName());
        self::assertSame('sip:1234@ondewo.com', $entry->getCalleeId());
        self::assertSame('transfer-7', $entry->getTransferCallId());
        self::assertSame(StatusType::OUTGOING_CALL_CONNECTED, $entry->getStatusType());
        self::assertSame('call connected', $entry->getDescription());
        self::assertSame('projects/6b2c8e5a/agent/sessions/1', $entry->getNluSessionName());
        self::assertSame('42', $entry->getHeaders()['X-Ondewo-Request-Id']);

        self::assertTrue($entry->hasTimestamp());
        self::assertSame(1700000000, $entry->getTimestamp()->getSeconds());
        self::assertSame(123, $entry->getTimestamp()->getNanos());

        // Byte-for-byte stability, which field-by-field getters alone would not prove.
        self::assertSame($bytes, $parsed->serializeToString());
    }

    public function testAnUnsetSubMessageStaysUnset(): void
    {
        $status = new SipStatus();
        $status->setAccountName('sip:4242@ondewo.com');

        self::assertFalse($status->hasTimestamp());
        self::assertNull($status->getTimestamp());

        $status->setTimestamp(new Timestamp(['seconds' => 1]));
        self::assertTrue($status->hasTimestamp());

        $status->clearTimestamp();
        self::assertFalse($status->hasTimestamp());
    }

    /**
     * `auto_answer_interval` is an int32, and google/protobuf's PURE-PHP JSON parser range-checks
     * every integer with bccomp(): without ext-bcmath this dies with "Call to undefined function
     * Google\Protobuf\Internal\bccomp()". The extension is a `suggest` of google/protobuf, not a
     * `require`, so nothing else would surface that - which is why CI installs it explicitly.
     */
    public function testAMessageSurvivesAJsonRoundTrip(): void
    {
        $request = new SipStartSessionRequest();
        $request->setAccountName('sip:4242@ondewo.com');
        $request->setAutoAnswerInterval(5);

        $json = $request->serializeToJsonString();
        self::assertJson($json);

        $parsed = new SipStartSessionRequest();
        $parsed->mergeFromJsonString($json);

        self::assertSame('sip:4242@ondewo.com', $parsed->getAccountName());
        self::assertSame(5, $parsed->getAutoAnswerInterval());
    }

    public function testTheEnumZeroValueIsTheDefaultOfAFieldTypedByIt(): void
    {
        self::assertSame(0, StatusType::NO_SESSION);
        self::assertSame('NO_SESSION', StatusType::name(StatusType::NO_SESSION));
        self::assertSame(StatusType::READY, StatusType::value('READY'));

        // The zero value must be requestable, i.e. it must be the DEFAULT of a field typed by it.
        self::assertSame(StatusType::NO_SESSION, (new SipStatus())->getStatusType());
    }

    public function testAnUnknownEnumMemberIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        StatusType::name(4242);
    }
}
