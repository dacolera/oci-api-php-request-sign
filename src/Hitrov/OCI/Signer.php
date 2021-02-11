<?php

namespace Hitrov\OCI;

use Hitrov\OCI\Exception\PrivateKeyFileNotFoundException;
use Hitrov\OCI\Exception\SignerValidateException;
use Hitrov\OCI\Exception\SigningValidationFailedException;
use Hitrov\OCI\KeyProvider\KeyProviderInterface;

class Signer
{
    const OCI_TENANCY_ID = 'OCI_TENANCY_ID';
    const OCI_USER_ID = 'OCI_USER_ID';
    const OCI_KEY_FINGERPRINT = 'OCI_KEY_FINGERPRINT';
    const OCI_PRIVATE_KEY_FILENAME = 'OCI_PRIVATE_KEY_FILENAME';
    const OCI_PRIVATE_KEY = 'OCI_PRIVATE_KEY';

    const SIGNING_HEADER_DATE = 'date';
    const SIGNING_HEADER_REQUEST_TARGET = '(request-target)';
    const SIGNING_HEADER_HOST = 'host';
    const SIGNING_HEADER_CONTENT_LENGTH = 'content-length';
    const SIGNING_HEADER_CONTENT_TYPE = 'content-type';
    const SIGNING_HEADER_X_CONTENT_SHA256 = 'x-content-sha256';

    const CONTENT_TYPE_APPLICATION_JSON = 'application/json';

    private ?string $ociUserId;
    private ?string $ociTenancyId;
    private ?string $ociKeyFingerPrint;
    private ?string $ociPrivateKeyFilename;

    private array $headersToSign;

    private KeyProviderInterface $keyProvider;

    /**
     * Signer constructor.
     * @param string|null $ociTenancyId
     * @param string|null $ociUserId
     * @param string|null $keyFingerPrint
     * @param string|null $privateKeyFilename
     */
    public function __construct(?string $ociTenancyId = null, ?string $ociUserId = null, ?string $keyFingerPrint = null, ?string $privateKeyFilename = null)
    {
        $this->ociTenancyId = $ociTenancyId ?: getenv(self::OCI_TENANCY_ID);
        $this->ociUserId = $ociUserId ?: getenv(self::OCI_USER_ID);
        $this->ociKeyFingerPrint = $keyFingerPrint ?: getenv(self::OCI_KEY_FINGERPRINT);
        $this->ociPrivateKeyFilename = $privateKeyFilename ?: getenv(self::OCI_PRIVATE_KEY_FILENAME);
    }

    /**
     * @param string|null $url
     * @param string $method
     * @param string|null $body
     * @param string|null $contentType
     * @param string|null $dateString
     * @return array
     * @throws PrivateKeyFileNotFoundException
     * @throws SignerValidateException
     * @throws SigningValidationFailedException
     */
    public function getHeaders(string $url, string $method = 'GET', ?string $body = null, ?string $contentType = self::CONTENT_TYPE_APPLICATION_JSON, string $dateString = null): array
    {
        $this->validateParameters($url);

        $headersToSign = $this->getHeadersToSign($url, $method, $body, $contentType, $dateString);
        $signingString = $this->getSigningString($url, $method, $body, $contentType, $dateString);
        $privateKey = $this->getPrivateKey();
        $signature = $this->calculateSignature($signingString, $privateKey);

        $headers = [];
        foreach ($headersToSign as $headerName => $value) {
            if ($headerName === self::SIGNING_HEADER_REQUEST_TARGET) {
                continue;
            }
            $headers[] = "$headerName: $value";
        }
        $keyId = $this->getKeyId();
        $headers[] = $this->getAuthorizationHeader($keyId, implode(' ', array_keys($headersToSign)), $signature);

        return $headers;
    }

    /**
     * @param string $signingString
     * @param string $privateKey
     * @return string
     * @throws SigningValidationFailedException
     */
    public function calculateSignature(string $signingString, string $privateKey): string
    {
        // fetch private key from file and ready it
        $privateKeyId = openssl_pkey_get_private($privateKey);

        // compute signature
        $result = openssl_sign($signingString, $binarySignature, $privateKeyId, OPENSSL_ALGO_SHA256); // sha256WithRSAEncryption
        if (!$result) {
            throw new SigningValidationFailedException('Cannot generate signature.');
        }

        $signatureBase64 = base64_encode($binarySignature);

        // verify
        $details = openssl_pkey_get_details($privateKeyId);
        $public_key_pem = $details['key'];
        $r = openssl_verify($signingString, $binarySignature, $public_key_pem, "sha256WithRSAEncryption");
        if ($r !== 1) {
            throw new SigningValidationFailedException('Cannot verify signature.');
        }

        // deprecated
        openssl_free_key($privateKeyId);

        return $signatureBase64;
    }

    /**
     * @param string|null $url
     * @param string $method
     * @param string|null $body
     * @param string|null $contentType
     * @param string|null $dateString
     * @return string
     */
    public function getSigningString(
        string $url,
        string $method = 'GET',
        ?string $body = null,
        ?string $contentType = self::CONTENT_TYPE_APPLICATION_JSON,
        ?string $dateString = null
    ): string
    {
        $headersToSign = $this->getHeadersToSign($url, $method, $body, $contentType, $dateString);
        $signingHeaders = [];
        foreach ($headersToSign as $header => $value) {
            $signingHeaders[] = "$header: $value";
        }

        return implode("\n", $signingHeaders);
    }

    /**
     * @return string
     */
    public function getKeyId(): string
    {
        if (isset($this->keyProvider)) {
            return $this->keyProvider->getKeyId();
        }

        return implode('/', [
            $this->ociTenancyId,
            $this->ociUserId,
            $this->ociKeyFingerPrint,
        ]);
    }

    /**
     * @param string|null $body
     * @return string
     */
    public function getBodyHashBase64(?string $body): string
    {
        $bodyHashBinary = hash('sha256', $body, true);

        return base64_encode($bodyHashBinary);
    }

    /**
     * @param string $method
     * @return string[]
     */
    public function getSigningHeadersNames(string $method): array
    {
        $signingHeaders = $this->getGenericHeadersNames();
        if ($this->shouldHashBody($method)) {
            $bodyHeaders = $this->getBodyHeadersNames();
            $signingHeaders = array_merge($signingHeaders, $bodyHeaders);
        }

        return $signingHeaders;
    }

    /**
     * @param KeyProviderInterface $keyProvider
     */
    public function setKeyProvider(KeyProviderInterface $keyProvider)
    {
        $this->keyProvider = $keyProvider;
    }

    /**
     * @param string $keyId
     * @param string $signedHeaders
     * @param string $signatureBase64
     * @return string
     */
    public function getAuthorizationHeader(string $keyId, string $signedHeaders, string $signatureBase64): string
    {
        return "Authorization: Signature version=\"1\",keyId=\"$keyId\",algorithm=\"rsa-sha256\",headers=\"$signedHeaders\",signature=\"$signatureBase64\"";
    }

    /**
     * @return string
     * @throws PrivateKeyFileNotFoundException
     */
    private function getPrivateKey(): ?string
    {
        if (isset($this->keyProvider)) {
            return $this->keyProvider->getPrivateKey();
        }

        if ($this->ociPrivateKeyFilename) {
            if (!file_exists($this->ociPrivateKeyFilename)) {
                throw new PrivateKeyFileNotFoundException();
            }

            return file_get_contents($this->ociPrivateKeyFilename);
        }

        return null;
    }

    /**
     * @param string $method
     * @return bool
     */
    private function shouldHashBody(string $method): bool
    {
        return in_array($method, ['POST', 'PUT', 'PATCH']);
    }

    /**
     * @param string $url
     * @param string $method
     * @param string|null $body
     * @param string|null $contentType
     * @param string|null $dateString
     * @return array
     */
    private function getHeadersToSign(string $url, string $method, ?string $body, ?string $contentType, string $dateString = null): array
    {
        if (isset($this->headersToSign)) {
            return $this->headersToSign;
        }

        $parsed = parse_url($url);

        $headersMap = [];
        $headersNames = $this->getSigningHeadersNames($method);
        foreach ($headersNames as $headerName) {
            switch ($headerName) {
                case self::SIGNING_HEADER_DATE:
                    if (!$dateString) {
                        $dateString = gmdate(DATE_RFC7231);
                    }
                    $headersMap[self::SIGNING_HEADER_DATE] = $dateString;
                    break;
                case self::SIGNING_HEADER_REQUEST_TARGET:
                    $uri = $parsed['path'] ?? '';
                    if (!empty($parsed['query'])) {
                        $uri .= '?' . $parsed['query'];
                    }
                    $headersMap[self::SIGNING_HEADER_REQUEST_TARGET] = strtolower($method) . " $uri";
                    break;
                case self::SIGNING_HEADER_HOST:
                    $headersMap[self::SIGNING_HEADER_HOST] = $parsed['host'] ?? '';
                    break;
                case self::SIGNING_HEADER_CONTENT_LENGTH:
                    $contentLength = $this->getContentLength($body);
                    $headersMap[self::SIGNING_HEADER_CONTENT_LENGTH] = $contentLength;
                    break;
                case self::SIGNING_HEADER_CONTENT_TYPE:
                    $headersMap[self::SIGNING_HEADER_CONTENT_TYPE] = $contentType;
                    break;
                case self::SIGNING_HEADER_X_CONTENT_SHA256:
                    $bodyHashBase64 = $this->getBodyHashBase64($body);
                    $headersMap[self::SIGNING_HEADER_X_CONTENT_SHA256] = $bodyHashBase64;
                    break;
                default:
                    break;
            }
        }

        $this->headersToSign = $headersMap;

        return $this->headersToSign;
    }

    /**
     * @return string[]
     */
    private function getGenericHeadersNames(): array
    {
        return [
            self::SIGNING_HEADER_DATE,
            self::SIGNING_HEADER_REQUEST_TARGET,
            self::SIGNING_HEADER_HOST,
        ];
    }

    /**
     * @return string[]
     */
    private function getBodyHeadersNames(): array
    {
        return [
            self::SIGNING_HEADER_CONTENT_LENGTH,
            self::SIGNING_HEADER_CONTENT_TYPE,
            self::SIGNING_HEADER_X_CONTENT_SHA256,
        ];
    }

    /**
     * @param string $url
     * @throws PrivateKeyFileNotFoundException
     * @throws SignerValidateException
     */
    private function validateParameters(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new SignerValidateException("URL is invalid: $url");
        }

        if (isset($this->keyProvider)) {
            return;
        }

        if (
            empty($this->ociUserId) ||
            empty($this->ociTenancyId) ||
            empty($this->ociKeyFingerPrint) ||
            empty($this->ociPrivateKeyFilename)
        ) {
            throw new SignerValidateException('OCI User ID, tenancy ID, key fingerprint and private key filename are required.');
        }

        if ($this->ociPrivateKeyFilename && !file_exists($this->ociPrivateKeyFilename)) {
            throw new PrivateKeyFileNotFoundException("Private key file does not exist: {$this->ociPrivateKeyFilename}");
        }
    }

    /**
     * @param string|null $body
     * @return int
     */
    private function getContentLength(?string $body)
    {
        return strlen($body);
    }
}