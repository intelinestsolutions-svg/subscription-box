<?php
/**
 * Notification sender — email + SMS through one interface.
 * mode 'log' writes to storage/logs/notifications.log (works with zero keys).
 * 'twilio' posts to the Twilio API when keys are configured.
 */
declare(strict_types=1);

final class Sender
{
    public static function email(array $config, string $to, string $subject, string $body): array
    {
        $mode = strtolower((string)($config['mail_mode'] ?? 'log'));
        if ($mode === 'smtp') {
            @mail($to, $subject, wordwrap($body, 76));
            return ['channel' => 'email', 'mode' => 'smtp', 'to' => $to, 'subject' => $subject];
        }
        return self::log('email', $config, ['to' => $to, 'subject' => $subject, 'body' => $body]);
    }

    public static function sms(array $config, string $to, string $body): array
    {
        $mode = strtolower((string)($config['sms_mode'] ?? 'log'));
        if ($mode === 'twilio' && $config['twilio_sid'] && $config['twilio_token']) {
            $q = http_build_query(['From' => $config['twilio_from'], 'To' => $to, 'Body' => $body]);
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Authorization: Basic " . base64_encode($config['twilio_sid'] . ':' . $config['twilio_token']) . "\r\n" .
                                "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $q,
                    'timeout' => 15,
                ],
            ]);
            $res = @file_get_contents("https://api.twilio.com/2010-04-01/Accounts/{$config['twilio_sid']}/Messages.json", false, $ctx);
            return ['channel' => 'sms', 'mode' => 'twilio', 'to' => $to, 'raw' => (string)$res];
        }
        return self::log('sms', $config, ['to' => $to, 'body' => $body]);
    }

    private static function log(string $channel, array $config, array $entry): array
    {
        $entry = ['at' => gmdate('c'), 'channel' => $channel, 'mode' => 'log'] + $entry;
        $dir = rtrim((string)($config['storage_dir'] ?? __DIR__ . '/../../storage'), '/') . '/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents($dir . '/notifications.log', json_encode($entry) . PHP_EOL, FILE_APPEND);
        return $entry;
    }
}