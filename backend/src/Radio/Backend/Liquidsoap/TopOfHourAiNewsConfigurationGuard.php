<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runtime-gates top-hour AI News so it never races the Station ID.
 *
 * The shared `top_of_hour_id_enabled` ref is created here before the TOH runtime
 * wrapper is appended. If TOH is enabled at :59, the cron defers; the TOH runtime
 * queues news after the ID on an open hour. If TOH is disabled, the same cron
 * immediately returns to normal behavior without a station restart.
 */
final class TopOfHourAiNewsConfigurationGuard implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // ConfigWriter writes AI News at 28. Run immediately after it and
            // before the TOH runtime source wrapper at priority 15.
            WriteLiquidsoapConfiguration::class => ['gateTopOfHourNews', 27],
        ];
    }

    public function gateTopOfHourNews(WriteLiquidsoapConfiguration $event): void
    {
        $config = $event->getBackendConfig();
        $enabled = $config->top_of_hour_id_enabled ? 'true' : 'false';

        // This ref is intentionally always emitted because the TOH runtime/API
        // changes it live even when AI News itself is disabled.
        $event->appendBlock(
            <<<LIQ
            top_of_hour_id_enabled = ref({$enabled})
            LIQ
        );

        if (!$config->ai_news_enabled || !$config->ai_news_top_of_hour) {
            return;
        }

        $lines = explode("\n", $event->buildConfiguration());
        foreach ($lines as $index => $line) {
            if (
                !str_contains($line, 'cron.add("')
                || !str_contains($line, 'queue_news_bulletin()')
            ) {
                continue;
            }

            if (!preg_match('/cron\.add\("([^"]+)"/', $line, $matches)) {
                continue;
            }

            $expression = trim($matches[1]);
            $parts = preg_split('/\s+/', $expression);
            if (!is_array($parts) || count($parts) < 5) {
                continue;
            }

            $minutes = array_values(array_map('trim', explode(',', $parts[0])));
            if (!in_array('59', $minutes, true)) {
                continue;
            }

            // Keep every non-top-hour minute exactly as ConfigWriter generated it.
            $otherMinutes = array_values(array_filter(
                $minutes,
                static fn(string $minute): bool => '59' !== $minute
            ));

            if ([] === $otherMinutes) {
                $event->replaceLine(
                    $index,
                    '# AI News :59 cron is dynamically gated by automatic TOH ID.'
                );
            } else {
                $otherParts = $parts;
                $otherParts[0] = implode(',', $otherMinutes);
                $otherExpression = implode(' ', $otherParts);
                $event->replaceLine(
                    $index,
                    str_replace($expression, $otherExpression, $line)
                );
            }

            $topHourParts = $parts;
            $topHourParts[0] = '59';
            $topHourExpression = implode(' ', $topHourParts);

            $event->appendBlock(
                <<<LIQ
                def queue_top_hour_news_bulletin() =
                    if top_of_hour_id_enabled() then
                        log("AI News: deferred until after Top-of-Hour Station ID.")
                    else
                        queue_news_bulletin()
                    end
                end
                cron.add("{$topHourExpression}", {queue_top_hour_news_bulletin()})
                LIQ
            );

            // There is only one AI News cron block per station.
            break;
        }
    }
}
