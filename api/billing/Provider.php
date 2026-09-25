<?php
/**
 * Billing provider abstraction.
 * 'demo' works with zero keys and can simulate failures (force_fail).
 * 'stripe' is a ready skeleton — plug live keys + webhook endpoint to go live.
 */
declare(strict_types=1);

interface BillingProvider
{
    /** @return array{success:bool, provider:string, ref:string, message?:string} */
    public function charge(array $user, string $description, float $amount, array $options = []): array;

    public function name(): string;
}

final class DemoProvider implements BillingProvider
{
    public function name(): string { return 'demo'; }

    public function charge(array $user, string $description, float $amount, array $options = []): array
    {
        if (!empty($options['force_fail'])) {
            return ['success' => false, 'provider' => 'demo', 'ref' => 'pay_' . bin2hex(random_bytes(6)),
                    'message' => 'Card declined (simulated). Update your payment method.'];
        }
        return ['success' => true, 'provider' => 'demo', 'ref' => 'pay_' . bin2hex(random_bytes(6))];
    }
}

/**
 * Stripe-ready skeleton. Uncomment + add key in config to enable.
 * Endpoints needed: POST /v1/payment_intents (create), webhook for payment_intent.succeeded.
 */
final class StripeProvider implements BillingProvider
{
    public function name(): string { return 'stripe'; }

    public function charge(array $user, string $description, float $amount, array $options = []): array
    {
        $key = $GLOBALS['config']['stripe_secret_key'] ?? '';
        if ($key === '') {
            return ['success' => false, 'provider' => 'stripe', 'ref' => '', 'message' => 'Stripe not configured.'];
        }
        // curl -s https://api.stripe.com/v1/payment_intents \
        //   -u "$key:" -d amount=$cents -d currency=usd -d "description=$description" \
        //   -d "payment_method_types[]=card"
        return ['success' => false, 'provider' => 'stripe', 'ref' => '', 'message' => 'Stripe skeleton — implement webhook.'];
    }
}

final class BillingProvider
{
    public static function make(array $config): BillingProvider
    {
        $mode = strtolower((string)($config['billing_provider'] ?? 'demo'));
        return $mode === 'stripe' ? new StripeProvider() : new DemoProvider();
    }
}