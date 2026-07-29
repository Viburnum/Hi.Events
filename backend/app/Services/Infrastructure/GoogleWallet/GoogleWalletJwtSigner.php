<?php

namespace HiEvents\Services\Infrastructure\GoogleWallet;

use HiEvents\Exceptions\Wallet\WalletPassGenerationException;

/**
 * Produces a signed "Save to Google Wallet" link. The event ticket class and
 * object are embedded directly in a signed JWT (the "skinny JWT" flow), so no
 * server-to-server API calls are required to create the pass.
 */
class GoogleWalletJwtSigner
{
    private const SAVE_URL_PREFIX = 'https://pay.google.com/gp/v/save/';

    public function __construct(
        private readonly GoogleWalletConfigurationService $configuration,
    ) {}

    /**
     * @param  array<string, mixed>  $eventTicketClass
     * @param  array<string, mixed>  $eventTicketObject
     *
     * @throws WalletPassGenerationException
     */
    public function createSaveUrl(array $eventTicketClass, array $eventTicketObject): string
    {
        $serviceAccount = $this->configuration->getServiceAccount();

        $origins = array_values(array_filter([$this->configuration->getOrigin()]));

        $claims = [
            'iss' => $serviceAccount['client_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'origins' => $origins,
            'payload' => [
                'eventTicketClasses' => [$eventTicketClass],
                'eventTicketObjects' => [$eventTicketObject],
            ],
        ];

        $jwt = $this->sign($claims, (string) $serviceAccount['private_key']);

        return self::SAVE_URL_PREFIX.$jwt;
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws WalletPassGenerationException
     */
    private function sign(array $claims, string $privateKey): string
    {
        $segments = [
            $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];

        $signingInput = implode('.', $segments);

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new WalletPassGenerationException(
                __('Failed to sign the Google Wallet pass. Check the service account private key.')
            );
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
