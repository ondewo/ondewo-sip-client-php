<?php
// GENERATED CODE -- DO NOT EDIT!

// Original file comments:
// Copyright 2021 - 2026 ONDEWO GmbH
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
namespace Ondewo\Sip;

/**
 * <p>ONDEWO-SIP API available at <a href="https://github.com/ondewo/ondewo-sip-api">GitHub</a></p>
 */
class SipClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * <p>Starts a new SIP session for an account registered at a SIP server. <code>RegisterAccount</code> need to be called before.</p>
     * @param \Ondewo\Sip\SipStartSessionRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipStartSession(\Ondewo\Sip\SipStartSessionRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipStartSession',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Ends a SIP session for an account registered at a SIP server</p>
     * @param \Google\Protobuf\GPBEmpty $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipEndSession(\Google\Protobuf\GPBEmpty $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipEndSession',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Starts a call in an active SIP session for an account registered at a SIP server</p>
     * @param \Ondewo\Sip\SipStartCallRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipStartCall(\Ondewo\Sip\SipStartCallRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipStartCall',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Ends a call in an active SIP session for an account registered at a SIP server</p>
     * @param \Ondewo\Sip\SipEndCallRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipEndCall(\Ondewo\Sip\SipEndCallRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipEndCall',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Transfers a call in an active SIP session for an account registered at a SIP server to another SIP account or phone number specified by <code>transfer_id</code></p>
     * <p>Call scoping: when the gRPC metadatum <code>x-ondewo-expected-call-id</code> is present it must equal
     * <code>SipStatus.call_id</code> of the ongoing call, otherwise the request is refused with
     * <code>exception_name=CallScopeMismatch</code> and nothing is assigned to the status. When it is absent the request is
     * accepted for backward compatibility (unless the server requires call scoping).</p>
     * <p>With <code>outcome_timeout_ms = 0</code> the call is transferred as before (REFER, then an immediate hangup).
     * With <code>outcome_timeout_ms &gt; 0</code> see <code>SipTransferCallRequest.outcome_timeout_ms</code>.</p>
     * <p>Refused while invited participants are present (see
     * <code>SipSetCallMediaControlRequest.participants_present</code>): a REFER into a conference bridge transfers every
     * party in it, the invited participant included. The refusal is RETURNED as <code>TRANSFER_CALL_FAILED</code> with
     * <code>exception_name=ParticipantsPresent</code> and <code>description = reason=participants-present</code>; nothing
     * is sent and the call is kept.</p>
     * @param \Ondewo\Sip\SipTransferCallRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipTransferCall(\Ondewo\Sip\SipTransferCallRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipTransferCall',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Registers s SIP account at a SIP server</p>
     * @param \Ondewo\Sip\SipRegisterAccountRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipRegisterAccount(\Ondewo\Sip\SipRegisterAccountRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipRegisterAccount',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Gets the current SIP status</p>
     * @param \Google\Protobuf\GPBEmpty $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipGetSipStatus(\Google\Protobuf\GPBEmpty $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipGetSipStatus',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Gets the history of SIP status</p>
     * @param \Google\Protobuf\GPBEmpty $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipGetSipStatusHistory(\Google\Protobuf\GPBEmpty $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipGetSipStatusHistory',
        $argument,
        ['\Ondewo\Sip\SipStatusHistoryResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Plays wav files during an ongoing call of an active SIP session</p>
     * <p>Call scoping as for <code>SipTransferCall</code>: a present <code>x-ondewo-expected-call-id</code> metadatum must
     * match <code>SipStatus.call_id</code>.</p>
     * @param \Ondewo\Sip\SipPlayWavFilesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipPlayWavFiles(\Ondewo\Sip\SipPlayWavFilesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipPlayWavFiles',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Mutes the microphone in an ongoing call of an active SIP session</p>
     * <p>Call scoping as for <code>SipTransferCall</code>. Sent by the in-container speech-to-speech pipeline it mutes only
     * the bot's own mixer slot; sent by a remote client it sets the operator mute of
     * <code>SipSetCallMediaControl</code>, which the pipeline cannot undo.</p>
     * @param \Google\Protobuf\GPBEmpty $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipMute(\Google\Protobuf\GPBEmpty $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipMute',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Un-mutes the microphone in an ongoing call of an active SIP session</p>
     * <p>Call scoping and the split between the pipeline's own mute and the operator mute as for <code>SipMute</code>.</p>
     * @param \Google\Protobuf\GPBEmpty $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipUnMute(\Google\Protobuf\GPBEmpty $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipUnMute',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Reports that answering machine detection reached a verdict on the ongoing outgoing call. Sets the status
     * <code>OUTGOING_CALL_ANSWERING_MACHINE_DETECTED</code> carrying <code>amd_result</code>; the call stays up.</p>
     * <p>Called by the speech-to-speech pipeline (ONDEWO-CSI) inside the same container, i.e. over loopback only.
     * Refused, and the current status left untouched, when no outgoing call is connected: the returned
     * <code>SipStatus</code> then carries the refusal in <code>exception_name</code> and <code>description</code></p>
     * @param \Ondewo\Sip\SipReportAnsweringMachineDetectedRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipReportAnsweringMachineDetected(\Ondewo\Sip\SipReportAnsweringMachineDetectedRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipReportAnsweringMachineDetected',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Call-scoped operator media control of the ongoing call: mute the bot and/or pause its listening.</p>
     * <p>Metadata REQUIRED: <code>x-ondewo-expected-call-id</code> (must equal <code>SipStatus.call_id</code> of the ongoing
     * call) and <code>x-ondewo-sip-call-control-token</code> (the per-container call-control token).</p>
     * <p>Every request sets a desired level per owner and never toggles; a repeat leaves the level unchanged. The bot is
     * muted while ANY owner holds a mute, and its listening is paused while ANY owner holds a pause.</p>
     * <p>Returns the live status with <code>call_id</code>, <code>bot_muted</code>, <code>listening_paused</code> and
     * <code>call_audio_streams</code> filled. Refusals are RETURNED in <code>exception_name</code> /
     * <code>description</code> (<code>CallScopeMismatch</code>, <code>CallControlUnauthenticated</code>,
     * <code>NoOngoingCall</code>, <code>AmdInProgress</code>, <code>CsiMediaControlFailed</code>) and never assigned to
     * the shared status. When the pipeline refuses or fails, a requested pause is rolled back and a requested mute is
     * kept (the safe direction); the returned fields carry the actual level.</p>
     * @param \Ondewo\Sip\SipSetCallMediaControlRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SipSetCallMediaControl(\Ondewo\Sip\SipSetCallMediaControlRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/ondewo.sip.Sip/SipSetCallMediaControl',
        $argument,
        ['\Ondewo\Sip\SipStatus', 'decode'],
        $metadata, $options);
    }

    /**
     * <p>Bidirectional live audio of the ongoing call.</p>
     * <p>The first request MUST be <code>config</code> and must arrive within 2 seconds. Metadata as for
     * <code>SipSetCallMediaControl</code>.</p>
     * <p>LISTEN receives the caller (plus any conference participants) mixed with the bot. TALK sends the agent's audio to
     * the caller; it REQUIRES <code>take_over</code>, i.e. the bot is muted and does not listen while the stream is
     * connected, and in TALK the agent hears the caller only. Audio is LINEAR16 little-endian mono in 20 ms frames.</p>
     * <p>gRPC status codes: <code>UNAUTHENTICATED</code> (token), <code>FAILED_PRECONDITION</code> (call id mismatch, no
     * connected call, answering machine detection in progress, bot still speaking at TALK start),
     * <code>INVALID_ARGUMENT</code> (missing or invalid <code>config</code>, wrong frame size),
     * <code>RESOURCE_EXHAUSTED</code> (stream cap reached, a second TALK). A normal end sends one <code>ended</code>
     * message and then OK.</p>
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\BidiStreamingCall
     */
    public function SipStreamCallAudio($metadata = [], $options = []) {
        return $this->_bidiRequest('/ondewo.sip.Sip/SipStreamCallAudio',
        ['\Ondewo\Sip\SipCallAudioResponse','decode'],
        $metadata, $options);
    }

}
