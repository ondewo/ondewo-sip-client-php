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

}
