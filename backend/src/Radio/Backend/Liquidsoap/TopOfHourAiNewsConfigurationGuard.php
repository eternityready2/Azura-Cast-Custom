<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Prevents Liquidsoap's legacy :59 AI News cron from competing with the
 * station-wide automatic Station ID.
 *
 * The Station ID owns the protected end-of-hour handoff whenever TOH is
 * enabled. Bottom-of-hour AI News remains untouched. Top-of-hour AI News is
 * therefore omitted from Liquidsoap's direct request queue while automatic TOH
 * is enabled instead of racing the mandatory ID for the same track boundary.
 */
final class TopOfHourAiNewsConfigurationGuard implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // ConfigWriter writes the AI News block at priority 28. Run directly
            // afterwards, before crossfade configuration at priority 25.
            WriteLiquidsoapConfiguration::class => ['removeConflictingTopOfHourCron', 27],
        ];
    }

    public function removeConflictingTopOfHourCron(WriteLiquidsoapConfiguration $event): void
    {
        $config = $event->getBackendConfig();
        if (
            !$config->top_of_hour_id_enabled
            || !$config->ai_news_enabled
            || !$config->ai_news_top_of_hour
        ) {
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

            $minutes = array_values(array_filter(
                explode(',', $parts[0]),
                static fn(string $minute): bool => trim($minute) !== '59'
            ));

            if ([] === $minutes) {
                $event->replaceLine(
                    $index,
                    '# AI News top-of-hour cron suppressed: automatic Station ID owns the protected :59 handoff.'
                );
                continue;
            }

            $parts[0] = implode(',', $minutes);
            $newExpression = implode(' ', $parts);
            $event->replaceLine(
                $index,
                str_replace($expression, $newExpression, $line)
            );
        }
    }
}
