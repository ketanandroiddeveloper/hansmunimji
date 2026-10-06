<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Core\HttpException;

final class GatewayRegistry
{
    /** @param array<string, PaymentGateway> $gateways */
    public function __construct(private array $gateways)
    {
    }

    public function get(string $name): PaymentGateway
    {
        $gateway = $this->gateways[$name] ?? null;
        if ($gateway === null) {
            throw HttpException::validation(['gateway' => ['Unknown payment method.']]);
        }

        return $gateway;
    }

    /**
     * Gateways that are configured and allowed for a currency, ordered by routing preference.
     *
     * @param array<string, list<string>> $routing currency => preferred gateway names
     * @return list<PaymentGateway>
     */
    public function forCurrency(string $currency, array $routing = []): array
    {
        $preferred = $routing[$currency] ?? array_keys($this->gateways);
        $out = [];
        foreach ($preferred as $name) {
            $gateway = $this->gateways[$name] ?? null;
            if ($gateway && $gateway->isConfigured() && in_array($currency, $gateway->supportedCurrencies(), true)) {
                $out[] = $gateway;
            }
        }

        return $out;
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }
}
