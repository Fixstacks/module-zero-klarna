<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Klarna's APIs (Payments, Hosted Payment Page, Order Management): HTTP Basic
 * with the API username and password, JSON both ways, amounts in minor units.
 * The host by region and the Playground (test) setting.
 */
class Api
{
    private const HOSTS = [
        'eu' => 'api.klarna.com',
        'na' => 'api-na.klarna.com',
        'oc' => 'api-oc.klarna.com',
    ];
    private const PLAYGROUND = [
        'eu' => 'api.playground.klarna.com',
        'na' => 'api-na.playground.klarna.com',
        'oc' => 'api-oc.playground.klarna.com',
    ];

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
        private readonly int $timeout = 20
    ) {
    }

    /**
     * POST, answering the JSON body ([] for none) and, for a capture or a
     * refund, the id Klarna gives in its Location header's last segment.
     *
     * @throws LocalizedException
     */
    public function post(string $path, array $body, ?int $storeId): array
    {
        return $this->send('POST', $path, $body, $storeId);
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $path, ?int $storeId): array
    {
        return $this->send('GET', $path, null, $storeId);
    }

    /**
     * @throws LocalizedException
     */
    public function delete(string $path, ?int $storeId): void
    {
        $this->send('DELETE', $path, null, $storeId);
    }

    /**
     * The Payments session's URL, as the Hosted Payment Page wants it.
     */
    public function url(string $path, ?int $storeId): string
    {
        return 'https://' . $this->host($storeId) . $path;
    }

    public function isConfigured(?int $storeId): bool
    {
        return $this->username($storeId) !== '' && $this->password($storeId) !== '';
    }

    /**
     * @throws LocalizedException
     */
    private function send(string $method, string $path, ?array $body, ?int $storeId): array
    {
        if (!$this->isConfigured($storeId)) {
            throw new LocalizedException(__('Klarna is not set up: the API username or password is missing.'));
        }
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout($this->timeout);
            $curl->setCredentials($this->username($storeId), $this->password($storeId));
            $curl->addHeader('Accept', 'application/json');
            if ($method === 'POST') {
                $curl->addHeader('Content-Type', 'application/json');
                $curl->post($this->url($path, $storeId), (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } elseif ($method === 'DELETE') {
                $curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
                $curl->get($this->url($path, $storeId));
            } else {
                $curl->get($this->url($path, $storeId));
            }
            $status = $curl->getStatus();
            $raw = (string) $curl->getBody();
            $headers = array_change_key_case($curl->getHeaders());
        } catch (\Throwable $e) {
            $this->logger->warning('Zero Klarna: ' . $path . ': ' . $e->getMessage());
            throw new LocalizedException(__('Klarna is not answering. Please try again in a minute.'), $e);
        }
        $answer = $raw === '' ? [] : json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($answer)) {
            $messages = is_array($answer) ? implode('; ', (array) ($answer['error_messages'] ?? [])) : '';
            $this->logger->warning(sprintf(
                'Zero Klarna: %s: HTTP %d %s %s',
                $path,
                $status,
                is_array($answer) ? ($answer['error_code'] ?? '') : '',
                $messages
            ));
            throw new LocalizedException(__('Klarna refused the request: %1', $messages !== '' ? $messages : 'HTTP ' . $status));
        }
        $location = (string) ($headers['location'] ?? '');
        if ($location !== '') {
            $answer['_id'] = basename((string) parse_url($location, PHP_URL_PATH));
        }

        return $answer;
    }

    private function host(?int $storeId): string
    {
        $region = (string) $this->scopeConfig->getValue('payment/zero_klarna/region', ScopeInterface::SCOPE_STORE, $storeId);
        $hosts = $this->scopeConfig->isSetFlag('payment/zero_klarna/playground', ScopeInterface::SCOPE_STORE, $storeId)
            ? self::PLAYGROUND
            : self::HOSTS;

        return $hosts[$region] ?? $hosts['eu'];
    }

    private function username(?int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue('payment/zero_klarna/username', ScopeInterface::SCOPE_STORE, $storeId));
    }

    private function password(?int $storeId): string
    {
        $value = trim((string) $this->scopeConfig->getValue('payment/zero_klarna/password', ScopeInterface::SCOPE_STORE, $storeId));

        // Read through the Encrypted backend model its config.xml default names, the
        // value comes decrypted; one stored some other way may still be encrypted.
        return preg_match('/^\d+:\d+:/', $value) ? trim((string) $this->encryptor->decrypt($value)) : $value;
    }
}
